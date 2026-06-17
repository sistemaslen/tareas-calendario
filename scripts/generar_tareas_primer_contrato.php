<?php

date_default_timezone_set('America/Mexico_City');

function log_msg($msg) {
    $line = date('Y-m-d H:i:s') . ' | ' . $msg . PHP_EOL;
    file_put_contents(__DIR__ . '/../logs/tareas_primer_contrato.log', $line, FILE_APPEND);
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
$sqlsrv = getSqlServerConnection();

log_msg('==== INICIO TAREAS PRIMER CONTRATO ====');

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
    jh.hire_date,
    jh.first_training_contract_end,
    COALESCE(gi.full_name, '') AS full_name
FROM employees e
INNER JOIN employee_job_history jh ON jh.employee_id = e.id
LEFT JOIN employee_general_info gi ON gi.employee_id = e.id
WHERE jh.first_training_contract_end IS NOT NULL
  AND e.status = 'ALTA'
";

$result = sqlsrv_query($sqlsrv, $query);
if ($result === false) {
    $msg = sqlsrv_last_error_message();
    log_msg('Error en query de empleados: ' . $msg);
    die('Error query SQL Server');
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
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'NOMINAS', 'ALTA', 'PENDIENTE', NULL, NULL, NULL, 'AUTO_PRIMER_CONTRATO', ?);
END
";

$actuales = [];
$proximas = [];
$vencidas = [];
$futuras  = [];

while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
    // Anchor: fecha de ingreso real (usado como hire_date en la tarea)
    $hireRaw = $row['hire_date'];
    if ($hireRaw instanceof DateTimeInterface) {
        $hireDate = new DateTime($hireRaw->format('Y-m-d'));
    } else {
        $hireDate = DateTime::createFromFormat('Y-m-d', (string)$hireRaw);
    }
    if (!$hireDate) {
        continue;
    }
    $hireDateStr = $hireDate->format('Y-m-d');

    // Due date: MISMO día de ingreso (la tarea se gestiona el primer día del colaborador)
    // Se guarda first_training_contract_end solo en metadata como referencia
    $sourceRaw = $row['first_training_contract_end'];
    if ($sourceRaw instanceof DateTimeInterface) {
        $contractEndDate = new DateTime($sourceRaw->format('Y-m-d'));
    } else {
        $contractEndDate = DateTime::createFromFormat('Y-m-d', (string)$sourceRaw);
    }
    if (!$contractEndDate) {
        continue;
    }

    $contractEndDateStr = $contractEndDate->format('Y-m-d');
    $dueDateStr = $hireDateStr;  // evento de un solo día: vence el mismo día de ingreso
    $bucket = computeBucket($hireDate, $today, $currentWeekStart, $currentWeekEnd, $nextWeekStart, $nextWeekEnd);

    $employeeId     = (int)$row['employee_id'];
    $employeeNumber = (string)($row['employee_number'] ?? '');
    $employeeName   = (string)($row['full_name'] ?? '');

    $title = 'Revision de primer contrato';
    $description = 'Firmar y registrar el primer contrato el día de ingreso del colaborador.';

    $metadata = json_encode([
        'rule'                       => 'PRIMER_CONTRATO_DIA_INGRESO',
        'generated_at'               => date('c'),
        'first_training_contract_end' => $contractEndDateStr,
    ], JSON_UNESCAPED_UNICODE);

    $taskCode = 'PRIMER_CONTRATO';

    $params = [
        $taskCode,
        $employeeId,
        $hireDateStr,   // anchor: hire_date real del empleado
        $dueDateStr,
        $bucket,
        $employeeName,
        $description,
        $metadata,
        $taskCode,
        $employeeId,
        $hireDateStr,   // anchor WHERE update
        $taskCode,
        $title,
        $description,
        $employeeId,
        $employeeNumber,
        $employeeName,
        $hireDateStr,   // hire_date en INSERT
        $dueDateStr,
        $bucket,
        $metadata,
    ];

    $upsertResult = sqlsrv_query($sqlsrv, $upsertSql, $params);
    if ($upsertResult === false) {
        log_msg('Error upsert employee_id=' . $employeeId . ' : ' . sqlsrv_last_error_message());
        continue;
    }
    sqlsrv_free_stmt($upsertResult);

    $taskInfo = [
        'employee_number' => $employeeNumber,
        'employee_name' => $employeeName,
        'contract_date' => $contractEndDateStr,
        'due_date' => $dueDateStr,
        'bucket' => $bucket,
    ];

    if ($bucket === 'ACTUAL') $actuales[] = $taskInfo;
    elseif ($bucket === 'PROXIMA') $proximas[] = $taskInfo;
    elseif ($bucket === 'VENCIDA') $vencidas[] = $taskInfo;
    else $futuras[] = $taskInfo;
}

sqlsrv_free_stmt($result);
sqlsrv_close($sqlsrv);

log_msg('VENCIDAS: ' . count($vencidas));
log_msg('ACTUAL: ' . count($actuales));
log_msg('PROXIMA: ' . count($proximas));
log_msg('FUTURA: ' . count($futuras));
log_msg('==== FIN TAREAS PRIMER CONTRATO ====');
