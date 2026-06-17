<?php

date_default_timezone_set('America/Mexico_City');

function log_msg($msg) {
    $line = date('Y-m-d H:i:s') . ' | ' . $msg . PHP_EOL;
    file_put_contents(__DIR__ . '/../logs/tareas_firma_contrato.log', $line, FILE_APPEND);
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

log_msg('==== INICIO TAREAS FIRMA CONTRATO ====');

$today = new DateTime('today');
$currentWeekStart = startOfWeek($today);
$currentWeekEnd = (clone $currentWeekStart)->modify('+6 days');
$nextWeekStart = (clone $currentWeekStart)->modify('+7 days');
$nextWeekEnd = (clone $currentWeekStart)->modify('+13 days');

log_msg('Ventana evaluada: ACTUAL ' . $currentWeekStart->format('Y-m-d') . ' a ' . $currentWeekEnd->format('Y-m-d')
    . ' | PROXIMA ' . $nextWeekStart->format('Y-m-d') . ' a ' . $nextWeekEnd->format('Y-m-d'));

// La firma de contrato definitivo ocurre el dia 57 desde el ingreso:
// second_training_contract_end + 1 dia.
$query = "
SELECT
    e.id AS employee_id,
    e.employee_number,
    jh.second_training_contract_end,
    COALESCE(gi.full_name, '') AS full_name
FROM employees e
INNER JOIN employee_job_history jh ON jh.employee_id = e.id
LEFT JOIN employee_general_info gi ON gi.employee_id = e.id
WHERE jh.second_training_contract_end IS NOT NULL
  AND e.status = 'ALTA'
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
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'NOMINAS', 'ALTA', 'PENDIENTE', NULL, NULL, NULL, 'AUTO_FIRMA_CONTRATO', ?);
END
";

while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
    $sourceRaw = $row['second_training_contract_end'];
    if ($sourceRaw instanceof DateTimeInterface) {
        $secondContractEnd = new DateTime($sourceRaw->format('Y-m-d'));
    } else {
        $secondContractEnd = DateTime::createFromFormat('Y-m-d', (string)$sourceRaw);
    }
    if (!$secondContractEnd) {
        continue;
    }

    // Firma = dia siguiente al fin del segundo contrato (dia 57 desde el ingreso)
    $firmaDate = (clone $secondContractEnd)->modify('+1 day');
    $firmaDateStr = $firmaDate->format('Y-m-d');

    $bucket = computeBucket($firmaDate, $today, $currentWeekStart, $currentWeekEnd, $nextWeekStart, $nextWeekEnd);

    $employeeId     = (int)$row['employee_id'];
    $employeeNumber = (string)($row['employee_number'] ?? '');
    $employeeName   = (string)($row['full_name'] ?? '');

    $title = 'Firma de contrato definitivo';
    $description = 'Firma de contrato indeterminado o determinado en la fecha programada (dia 57 desde el ingreso).';

    $metadata = json_encode([
        'rule' => 'FIRMA_CONTRATO_DIA_57',
        'generated_at' => date('c'),
        'firma_date' => $firmaDateStr,
        'second_contract_end' => $secondContractEnd->format('Y-m-d'),
    ], JSON_UNESCAPED_UNICODE);

    $taskCode = 'FIRMA_CONTRATO';

    $params = [
        $taskCode,
        $employeeId,
        $firmaDateStr,   // anchor: fecha de firma (hire_date en la tarea)
        $firmaDateStr,   // due_date
        $bucket,
        $employeeName,
        $description,
        $metadata,
        $taskCode,
        $employeeId,
        $firmaDateStr,   // anchor WHERE update
        $taskCode,
        $title,
        $description,
        $employeeId,
        $employeeNumber,
        $employeeName,
        $firmaDateStr,   // hire_date en INSERT
        $firmaDateStr,   // due_date en INSERT
        $bucket,
        $metadata,
    ];

    $upsertResult = sqlsrv_query($sqlsrv, $upsertSql, $params);
    if ($upsertResult === false) {
        log_msg('Error upsert employee_id=' . $employeeId . ' : ' . sqlsrv_last_error_message());
        continue;
    }
    sqlsrv_free_stmt($upsertResult);

    log_msg('Upsert OK employee_id=' . $employeeId . ' firma=' . $firmaDateStr . ' bucket=' . $bucket);
}

sqlsrv_free_stmt($result);
sqlsrv_close($sqlsrv);

log_msg('==== FIN TAREAS FIRMA CONTRATO ====');
