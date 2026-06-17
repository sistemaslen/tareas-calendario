<?php

date_default_timezone_set('America/Mexico_City');

function log_msg($msg) {
    $line = date('Y-m-d H:i:s') . ' | ' . $msg . PHP_EOL;
    file_put_contents(__DIR__ . '/../logs/tareas_evaluaciones.log', $line, FILE_APPEND);
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

function upsertTask(
    $sqlsrv,
    string $upsertSql,
    string $taskCode,
    int $employeeId,
    string $employeeNumber,
    string $employeeName,
    string $anchorDateStr,
    string $dueDateStr,
    string $bucket,
    string $title,
    string $description,
    string $metadata,
    string $source
): void {
    $params = [
        $taskCode,
        $employeeId,
        $anchorDateStr,   // hire_date anchor (SELECT / UPDATE WHERE)
        $dueDateStr,
        $bucket,
        $employeeName,
        $description,
        $metadata,
        $taskCode,
        $employeeId,
        $anchorDateStr,   // WHERE update
        $taskCode,
        $title,
        $description,
        $employeeId,
        $employeeNumber,
        $employeeName,
        $anchorDateStr,   // hire_date INSERT
        $dueDateStr,
        $bucket,
        $source,
        $metadata,
    ];

    $result = sqlsrv_query($sqlsrv, $upsertSql, $params);
    if ($result === false) {
        log_msg("Error upsert $taskCode employee_id=$employeeId : " . sqlsrv_last_error_message());
    } else {
        sqlsrv_free_stmt($result);
        log_msg("Upsert OK $taskCode employee_id=$employeeId due=$dueDateStr bucket=$bucket");
    }
}

require_once __DIR__ . '/../config/sqlserver.php';
$sqlsrv = getSqlServerConnection();

log_msg('==== INICIO TAREAS EVALUACIONES ====');

$today = new DateTime('today');
$currentWeekStart = startOfWeek($today);
$currentWeekEnd   = (clone $currentWeekStart)->modify('+6 days');
$nextWeekStart    = (clone $currentWeekStart)->modify('+7 days');
$nextWeekEnd      = (clone $currentWeekStart)->modify('+13 days');

log_msg('Ventana evaluada: ACTUAL ' . $currentWeekStart->format('Y-m-d') . ' a ' . $currentWeekEnd->format('Y-m-d')
    . ' | PROXIMA ' . $nextWeekStart->format('Y-m-d') . ' a ' . $nextWeekEnd->format('Y-m-d'));

// Traemos hire_date, primera y segunda evaluacion. Autoevaluacion toma la misma fecha.
$query = "
SELECT
    e.id AS employee_id,
    e.employee_number,
    jh.hire_date,
    jh.first_performance_evaluation_sent,
    jh.second_performance_evaluation_sent,
    COALESCE(gi.full_name, '') AS full_name
FROM employees e
INNER JOIN employee_job_history jh ON jh.employee_id = e.id
LEFT JOIN employee_general_info gi ON gi.employee_id = e.id
WHERE (jh.first_performance_evaluation_sent IS NOT NULL
    OR jh.second_performance_evaluation_sent IS NOT NULL)
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
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'RH', 'ALTA', 'PENDIENTE', NULL, NULL, NULL, ?, ?);
END
";

while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
    $employeeId     = (int)$row['employee_id'];
    $employeeNumber = (string)($row['employee_number'] ?? '');
    $employeeName   = (string)($row['full_name'] ?? '');

    // ----- PRIMERA EVALUACION (dia 21 desde ingreso) -----
    if (!empty($row['first_performance_evaluation_sent']) && !empty($row['hire_date'])) {
        $hireRaw = $row['hire_date'];
        $hireDate = $hireRaw instanceof DateTimeInterface
            ? new DateTime($hireRaw->format('Y-m-d'))
            : DateTime::createFromFormat('Y-m-d', (string)$hireRaw);

        $evalRaw = $row['first_performance_evaluation_sent'];
        $eval1Date = $evalRaw instanceof DateTimeInterface
            ? new DateTime($evalRaw->format('Y-m-d'))
            : DateTime::createFromFormat('Y-m-d', (string)$evalRaw);

        if ($hireDate && $eval1Date) {
            $hireDateStr  = $hireDate->format('Y-m-d');    // anchor = fecha de ingreso
            $eval1DateStr = $eval1Date->format('Y-m-d');
            $bucket1 = computeBucket($eval1Date, $today, $currentWeekStart, $currentWeekEnd, $nextWeekStart, $nextWeekEnd);

            $meta1 = json_encode([
                'rule'           => 'PRIMERA_EVALUACION_DIA_21',
                'generated_at'   => date('c'),
                'hire_date'      => $hireDateStr,
                'eval_date'      => $eval1DateStr,
                'autoevaluacion' => $eval1DateStr,  // misma fecha
            ], JSON_UNESCAPED_UNICODE);

            upsertTask(
                $sqlsrv, $upsertSql,
                'PRIMERA_EVALUACION',
                $employeeId, $employeeNumber, $employeeName,
                $hireDateStr,   // anchor
                $eval1DateStr,  // due_date
                $bucket1,
                'Primera evaluacion y autoevaluacion',
                'Realizar primera evaluacion y autoevaluacion del colaborador (dia 21 desde ingreso). La autoevaluacion toma la misma fecha.',
                $meta1,
                'AUTO_PRIMERA_EVALUACION'
            );
        }
    }

    // ----- SEGUNDA EVALUACION (dia 49 desde ingreso) -----
    if (!empty($row['second_performance_evaluation_sent']) && !empty($row['first_performance_evaluation_sent'])) {
        $anchor2Raw = $row['first_performance_evaluation_sent'];
        $anchor2Date = $anchor2Raw instanceof DateTimeInterface
            ? new DateTime($anchor2Raw->format('Y-m-d'))
            : DateTime::createFromFormat('Y-m-d', (string)$anchor2Raw);

        $eval2Raw = $row['second_performance_evaluation_sent'];
        $eval2Date = $eval2Raw instanceof DateTimeInterface
            ? new DateTime($eval2Raw->format('Y-m-d'))
            : DateTime::createFromFormat('Y-m-d', (string)$eval2Raw);

        if ($anchor2Date && $eval2Date) {
            $anchor2DateStr = $anchor2Date->format('Y-m-d');  // anchor = fecha primera evaluacion
            $eval2DateStr   = $eval2Date->format('Y-m-d');
            $bucket2 = computeBucket($eval2Date, $today, $currentWeekStart, $currentWeekEnd, $nextWeekStart, $nextWeekEnd);

            $meta2 = json_encode([
                'rule'                => 'SEGUNDA_EVALUACION_DIA_49',
                'generated_at'        => date('c'),
                'first_eval_date'     => $anchor2DateStr,
                'eval_date'           => $eval2DateStr,
                'autoevaluacion'      => $eval2DateStr,  // misma fecha
            ], JSON_UNESCAPED_UNICODE);

            upsertTask(
                $sqlsrv, $upsertSql,
                'SEGUNDA_EVALUACION',
                $employeeId, $employeeNumber, $employeeName,
                $anchor2DateStr,  // anchor = primera evaluacion
                $eval2DateStr,    // due_date
                $bucket2,
                'Segunda evaluacion y autoevaluacion',
                'Realizar segunda evaluacion y autoevaluacion del colaborador (dia 49 desde ingreso). La autoevaluacion toma la misma fecha.',
                $meta2,
                'AUTO_SEGUNDA_EVALUACION'
            );
        }
    }
}

sqlsrv_free_stmt($result);
sqlsrv_close($sqlsrv);

log_msg('==== FIN TAREAS EVALUACIONES ====');
