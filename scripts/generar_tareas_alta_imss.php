<?php

date_default_timezone_set('America/Mexico_City');

function log_msg($msg) {
    $line = date('Y-m-d H:i:s') . ' | ' . $msg . PHP_EOL;
    file_put_contents(__DIR__ . '/../logs/tareas_imss.log', $line, FILE_APPEND);
}

function sqlsrv_last_error_message(): string {
    $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    return is_array($errors) && isset($errors[0]['message'])
        ? $errors[0]['message']
        : 'Error desconocido';
}

function startOfWeek(DateTime $date): DateTime {
    $copy = clone $date;
    $copy->setTime(0, 0, 0);
    $dayOfWeek = (int)$copy->format('N'); // 1=Lunes, 7=Domingo
    $copy->modify('-' . ($dayOfWeek - 1) . ' days');
    return $copy;
}

/**
 * Calcula la fecha limite contando dias habiles a partir de $date (inclusive).
 * Ejemplo: addBusinessDays(lunes, 5) = viernes de esa misma semana.
 * Si $date no es habil (finde), avanza al siguiente habil y cuenta desde ahi.
 */
function addBusinessDays(DateTime $date, int $businessDays): DateTime {
    $result = clone $date;
    $result->setTime(0, 0, 0);

    // Si el dia de inicio no es habil, avanzar al siguiente habil
    while ((int)$result->format('N') >= 6) {
        $result->modify('+1 day');
    }
    // El dia de inicio ya cuenta como dia 1
    $counted = 1;

    while ($counted < $businessDays) {
        $result->modify('+1 day');
        $weekday = (int)$result->format('N');
        if ($weekday < 6) {
            $counted++;
        }
    }

    return $result;
}

/**
 * Calcula el bucket de urgencia de la tarea.
 *   VENCIDA  → due_date ya pasó (no se completó a tiempo)
 *   ACTUAL   → vence esta semana
 *   PROXIMA  → vence la semana siguiente
 *   FUTURA   → vence en 2+ semanas
 * No retorna null: siempre se genera la tarea.
 */
function computeBucket(
    DateTime $dueDate,
    DateTime $today,
    DateTime $currentWeekStart,
    DateTime $currentWeekEnd,
    DateTime $nextWeekStart,
    DateTime $nextWeekEnd
): string {
    $dueDateDay = clone $dueDate; $dueDateDay->setTime(0,0,0);
    $todayDay   = clone $today;   $todayDay->setTime(0,0,0);

    if ($dueDateDay < $todayDay)  return 'VENCIDA';
    if ($dueDateDay >= $currentWeekStart && $dueDateDay <= $currentWeekEnd) return 'ACTUAL';
    if ($dueDateDay >= $nextWeekStart    && $dueDateDay <= $nextWeekEnd)    return 'PROXIMA';
    return 'FUTURA';
}

require_once __DIR__ . '/../config/sqlserver.php';
// Conexion unica a GestionTalento: empleados y tareas viven en la misma BD.
$sqlsrv = getSqlServerConnection();

log_msg('==== INICIO TAREAS IMSS ====');

$today = new DateTime('today');
$currentWeekStart = startOfWeek($today);
$currentWeekEnd = (clone $currentWeekStart)->modify('+6 days');
$nextWeekStart = (clone $currentWeekStart)->modify('+7 days');
$nextWeekEnd = (clone $currentWeekStart)->modify('+13 days');

log_msg('Ventana evaluada: ACTUAL ' . $currentWeekStart->format('Y-m-d') . ' a ' . $currentWeekEnd->format('Y-m-d')
    . ' | PROXIMA ' . $nextWeekStart->format('Y-m-d') . ' a ' . $nextWeekEnd->format('Y-m-d'));

$query = "
SELECT
    e.id AS employee_id,
    e.employee_number,
    e.status,
    jh.hire_date,
    COALESCE(gi.full_name, '') AS full_name
FROM employees e
INNER JOIN employee_job_history jh ON jh.employee_id = e.id
LEFT JOIN employee_general_info gi ON gi.employee_id = e.id
WHERE e.status = 'ALTA'
  AND jh.hire_date IS NOT NULL
";

$result = sqlsrv_query($sqlsrv, $query);

if ($result === false) {
    $msg = sqlsrv_last_error_message();
    log_msg('Error en query de empleados: ' . $msg);
    die('Error query SQL Server');
}

$countCheck = sqlsrv_query($sqlsrv, "SELECT COUNT(*) AS total FROM employees WHERE status = 'ALTA'");
if ($countCheck !== false) {
    $countRow = sqlsrv_fetch_array($countCheck, SQLSRV_FETCH_ASSOC);
    log_msg('Empleados ALTA encontrados: ' . ($countRow['total'] ?? '?'));
    sqlsrv_free_stmt($countCheck);
}

$jCountCheck = sqlsrv_query($sqlsrv, "SELECT COUNT(*) AS total FROM employee_job_history WHERE hire_date IS NOT NULL");
if ($jCountCheck !== false) {
    $jRow = sqlsrv_fetch_array($jCountCheck, SQLSRV_FETCH_ASSOC);
    log_msg('Registros job_history con hire_date: ' . ($jRow['total'] ?? '?'));
    sqlsrv_free_stmt($jCountCheck);
}

$upsertSql = "
IF EXISTS (
    SELECT 1
    FROM tareas_gestion_talento
    WHERE task_code = ?
      AND employee_id = ?
      AND hire_date = ?
)
BEGIN
    UPDATE tareas_gestion_talento
    SET due_date = ?,
        week_bucket = ?,
        employee_name = ?,
        description = ?,
        metadata_json = ?,
        updated_at = GETDATE()
    WHERE task_code = ?
      AND employee_id = ?
      AND hire_date = ?;
END
ELSE
BEGIN
    INSERT INTO tareas_gestion_talento (
        task_code,
        title,
        description,
        employee_id,
        employee_number,
        employee_name,
        hire_date,
        due_date,
        week_bucket,
        owner_area,
        priority,
        status,
        status_changed_at,
        status_changed_by_user,
        status_changed_by_email,
        source,
        metadata_json
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'NOMINAS', 'ALTA', 'PENDIENTE', NULL, NULL, NULL, 'AUTO_IMSS', ?);
END
";

$actuales  = [];
$proximas  = [];
$vencidas  = [];
$futuras   = [];
$insertedOrUpdated = 0;
$rowsRead = 0;
$invalidHireDate = 0;
$outOfWindow = 0;
$upsertErrors = 0;
$sampleLimit = 25;

while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
    $rowsRead++;

    $hireDateRaw = $row['hire_date'];
    if ($hireDateRaw instanceof DateTimeInterface) {
        $hireDate = new DateTime($hireDateRaw->format('Y-m-d'));
    } else {
        $hireDate = DateTime::createFromFormat('Y-m-d', (string)$hireDateRaw);
    }
    if (!$hireDate) {
        $invalidHireDate++;
        if ($invalidHireDate <= $sampleLimit) {
            log_msg('Descartado employee_id=' . (string)($row['employee_id'] ?? '') . ' por hire_date invalido: ' . (string)$hireDateRaw);
        }
        continue;
    }

    $hireDateStr = $hireDate->format('Y-m-d');
    $dueDate = addBusinessDays($hireDate, 5);
    $dueDateStr = $dueDate->format('Y-m-d');
    $bucket = computeBucket($dueDate, $today, $currentWeekStart, $currentWeekEnd, $nextWeekStart, $nextWeekEnd);

    $employeeId     = (int)$row['employee_id'];
    $employeeNumber = (string)($row['employee_number'] ?? '');
    $employeeName   = (string)($row['full_name'] ?? '');

    log_msg("Procesando employee_id={$employeeId} hire={$hireDateStr} due={$dueDateStr} bucket={$bucket}");

    $title = 'Alta del IMSS (entrega a nominas)';
    $description = 'Entregar Alta del IMSS a nominas dentro de los primeros 5 dias habiles desde el ingreso.';

    $metadata = json_encode([
        'rule' => 'ALTA_IMSS_5_DIAS_HABILES',
        'generated_at' => date('c'),
        'window' => [
            'current_week_start' => $currentWeekStart->format('Y-m-d'),
            'current_week_end' => $currentWeekEnd->format('Y-m-d'),
            'next_week_start' => $nextWeekStart->format('Y-m-d'),
            'next_week_end' => $nextWeekEnd->format('Y-m-d')
        ]
    ], JSON_UNESCAPED_UNICODE);

    $taskCode = 'ALTA_IMSS';

    $upsertParams = [
        $taskCode,
        $employeeId,
        $hireDateStr,
        $dueDateStr,
        $bucket,
        $employeeName,
        $description,
        $metadata,
        $taskCode,
        $employeeId,
        $hireDateStr,
        $taskCode,
        $title,
        $description,
        $employeeId,
        $employeeNumber,
        $employeeName,
        $hireDateStr,
        $dueDateStr,
        $bucket,
        $metadata,
    ];

    $upsertResult = sqlsrv_query($sqlsrv, $upsertSql, $upsertParams);
    if ($upsertResult === false) {
        $upsertErrors++;
        $msg = sqlsrv_last_error_message();
        log_msg('Error upsert employee_id=' . $employeeId . ' : ' . $msg);
        continue;
    }

    sqlsrv_free_stmt($upsertResult);

    $insertedOrUpdated++;

    $taskInfo = [
        'employee_id'    => $employeeId,
        'employee_number'=> $employeeNumber,
        'employee_name'  => $employeeName,
        'hire_date'      => $hireDateStr,
        'due_date'       => $dueDateStr,
        'bucket'         => $bucket,
    ];

    if ($bucket === 'ACTUAL')  $actuales[]  = $taskInfo;
    elseif ($bucket === 'PROXIMA') $proximas[] = $taskInfo;
    elseif ($bucket === 'VENCIDA') $vencidas[] = $taskInfo;
    else                       $futuras[]   = $taskInfo;
}

sqlsrv_free_stmt($result);
sqlsrv_close($sqlsrv);

log_msg('Filas leidas de origen: ' . $rowsRead);
log_msg('Descartados por hire_date invalido: ' . $invalidHireDate);
log_msg('Errores en upsert: ' . $upsertErrors);
log_msg('Registros insertados/actualizados: ' . $insertedOrUpdated);
log_msg('  VENCIDAS : ' . count($vencidas));
log_msg('  ACTUAL   : ' . count($actuales));
log_msg('  PROXIMA  : ' . count($proximas));
log_msg('  FUTURA   : ' . count($futuras));
log_msg('==== FIN TAREAS IMSS ====');

foreach ([
    'VENCIDAS (ya pasaron los 5 dias habiles)' => $vencidas,
    'ACTUALES (vencen esta semana)'            => $actuales,
    'PROXIMAS (vencen semana siguiente)'       => $proximas,
    'FUTURAS  (vencen en 2+ semanas)'          => $futuras,
] as $seccion => $lista) {
    echo "\n=== {$seccion} ===\n";
    if (empty($lista)) {
        echo "Sin tareas\n";
    } else {
        foreach ($lista as $t) {
            echo sprintf(
                "[%s] %s | Ingreso: %s | Vence: %s | Bucket: %s\n",
                $t['employee_number'],
                $t['employee_name'] ?: '(sin nombre)',
                $t['hire_date'],
                $t['due_date'],
                $t['bucket']
            );
        }
    }
}

echo "\nProceso completado. Insertadas/actualizadas: {$insertedOrUpdated}\n";
