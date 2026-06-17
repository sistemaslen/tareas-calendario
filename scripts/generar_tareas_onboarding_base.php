<?php

date_default_timezone_set('America/Mexico_City');

function log_msg($msg) {
    $line = date('Y-m-d H:i:s') . ' | ' . $msg . PHP_EOL;
    file_put_contents(__DIR__ . '/../logs/tareas_onboarding_base.log', $line, FILE_APPEND);
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

log_msg('==== INICIO TAREAS ONBOARDING BASE ====');

$today = new DateTime('today');
$currentWeekStart = startOfWeek($today);
$currentWeekEnd = (clone $currentWeekStart)->modify('+6 days');
$nextWeekStart = (clone $currentWeekStart)->modify('+7 days');
$nextWeekEnd = (clone $currentWeekStart)->modify('+13 days');

log_msg('Ventana evaluada: ACTUAL ' . $currentWeekStart->format('Y-m-d') . ' a ' . $currentWeekEnd->format('Y-m-d')
    . ' | PROXIMA ' . $nextWeekStart->format('Y-m-d') . ' a ' . $nextWeekEnd->format('Y-m-d'));

// Reglas:
// - Solo colaboradores BASE
// - Solo contrato INDETERMINADO
// - Solo estatus ALTA
// - Onboarding en rango dia 7 a dia 10 (contando ingreso como dia 1)
$query = "
SELECT
    e.id AS employee_id,
    e.employee_number,
    e.status,
    jh.hire_date,
    jh.contract_type,
    jh.employee_category,
    COALESCE(gi.full_name, '') AS full_name
FROM employees e
INNER JOIN employee_job_history jh ON jh.employee_id = e.id
LEFT JOIN employee_general_info gi ON gi.employee_id = e.id
WHERE jh.hire_date IS NOT NULL
  AND e.status = 'ALTA'
  AND UPPER(LTRIM(RTRIM(COALESCE(jh.contract_type, '')))) = 'INDETERMINADO'
  AND UPPER(LTRIM(RTRIM(COALESCE(jh.employee_category, '')))) = 'BASE'
";

$result = sqlsrv_query($sqlsrv, $query);
if ($result === false) {
    log_msg('Error en query de empleados: ' . sqlsrv_last_error_message());
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
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'RH', 'MEDIA', 'PENDIENTE', NULL, NULL, NULL, 'AUTO_ONBOARDING_BASE', ?);
END
";

while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
    $hireRaw = $row['hire_date'];
    if ($hireRaw instanceof DateTimeInterface) {
        $hireDate = new DateTime($hireRaw->format('Y-m-d'));
    } else {
        $hireDate = DateTime::createFromFormat('Y-m-d', (string)$hireRaw);
    }
    if (!$hireDate) {
        continue;
    }

    // Dia 7 a dia 10 contando ingreso como dia 1:
    // inicio = +6 dias, fin limite = +9 dias
    $rangeStart = (clone $hireDate)->modify('+6 days');
    $rangeEnd = (clone $hireDate)->modify('+9 days');

    $anchorDateStr = $rangeStart->format('Y-m-d');
    $dueDateStr = $rangeEnd->format('Y-m-d');

    $bucket = computeBucket($rangeEnd, $today, $currentWeekStart, $currentWeekEnd, $nextWeekStart, $nextWeekEnd);

    $employeeId = (int)$row['employee_id'];
    $employeeNumber = (string)($row['employee_number'] ?? '');
    $employeeName = (string)($row['full_name'] ?? '');

    $title = 'Onboarding colaborador base';
    $description = 'Realizar onboarding entre el dia 7 y 10 desde el ingreso (solo BASE + contrato INDETERMINADO + estatus ALTA).';

    $metadata = json_encode([
        'rule' => 'ONBOARDING_BASE_DIA_7_A_10',
        'generated_at' => date('c'),
        'hire_date' => $hireDate->format('Y-m-d'),
        'range_start' => $anchorDateStr,
        'range_end' => $dueDateStr,
        'contract_type' => (string)($row['contract_type'] ?? ''),
        'employee_category' => (string)($row['employee_category'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);

    $taskCode = 'ONBOARDING_BASE';

    $params = [
        $taskCode,
        $employeeId,
        $anchorDateStr,
        $dueDateStr,
        $bucket,
        $employeeName,
        $description,
        $metadata,
        $taskCode,
        $employeeId,
        $anchorDateStr,
        $taskCode,
        $title,
        $description,
        $employeeId,
        $employeeNumber,
        $employeeName,
        $anchorDateStr,
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

    log_msg('Upsert OK employee_id=' . $employeeId . ' onboarding=' . $anchorDateStr . ' a ' . $dueDateStr . ' bucket=' . $bucket);
}

sqlsrv_free_stmt($result);
sqlsrv_close($sqlsrv);

log_msg('==== FIN TAREAS ONBOARDING BASE ====');
