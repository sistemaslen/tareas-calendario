<?php

date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/../config/sqlserver.php';
require_once __DIR__ . '/mailer.php';

function log_msg($msg) {
    $line = date('Y-m-d H:i:s') . ' | ' . $msg . PHP_EOL;
    file_put_contents(__DIR__ . '/../logs/tareas_segundo_contrato_notificacion.log', $line, FILE_APPEND);
}

function normalizeText(string $value): string {
    $value = trim($value);
    $value = strtoupper($value);
    return strtr($value, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']);
}

function getUserEmailsByRoles($sqlsrv, array $roleTargets): array {
    if (empty($roleTargets)) return [];

    $emails = [];
    $roleSql = "
        SELECT DISTINCT u.email, r.name AS role_name
        FROM user_profiles up
        INNER JOIN roles r ON r.id = up.role_id
        INNER JOIN auth_user u ON u.id = up.user_id
        WHERE up.is_active = 1 AND r.is_active = 1 AND u.is_active = 1
          AND u.email IS NOT NULL AND LTRIM(RTRIM(u.email)) <> ''
    ";

    $roleRows = sqlsrv_query($sqlsrv, $roleSql);
    if ($roleRows === false) {
        $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        $msg = is_array($errors) && isset($errors[0]['message']) ? $errors[0]['message'] : 'Error desconocido';
        throw new RuntimeException('No se pudo consultar gestion de usuarios: ' . $msg);
    }

    while ($row = sqlsrv_fetch_array($roleRows, SQLSRV_FETCH_ASSOC)) {
        $roleName = normalizeText((string)$row['role_name']);
        foreach ($roleTargets as $targetRole) {
            $targetRoleNorm = normalizeText((string)$targetRole);
            if ($roleName === $targetRoleNorm
                || ($targetRoleNorm === 'RH' && strpos($roleName, 'RECURSOS HUMANOS') !== false)
                || ($targetRoleNorm === 'ADMIN' && strpos($roleName, 'ADMIN') !== false)) {
                $emails[] = trim((string)$row['email']);
                break;
            }
        }
    }

    sqlsrv_free_stmt($roleRows);
    return $emails;
}

function findRecipients($sqlsrv, string $taskCode): array {
    $emails = [];
    $roleTargets = [];

    $destSql = "
    SELECT destination_type, destination_value
    FROM tareas_notificacion_destinos
    WHERE task_code = ? AND is_active = 1
    ";
    $destResult = sqlsrv_query($sqlsrv, $destSql, [$taskCode]);
    if ($destResult === false) {
        $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        $msg = is_array($errors) && isset($errors[0]['message']) ? $errors[0]['message'] : 'Error desconocido';
        throw new RuntimeException('No se pudieron cargar destinatarios: ' . $msg);
    }

    while ($d = sqlsrv_fetch_array($destResult, SQLSRV_FETCH_ASSOC)) {
        if ($d['destination_type'] === 'EMAIL') $emails[] = trim((string)$d['destination_value']);
        if ($d['destination_type'] === 'ROLE') $roleTargets[] = normalizeText((string)$d['destination_value']);
    }
    sqlsrv_free_stmt($destResult);

    if (empty($roleTargets)) $roleTargets = ['RH', 'ADMIN'];
    $emails = array_merge($emails, getUserEmailsByRoles($sqlsrv, $roleTargets));

    return array_values(array_unique(array_filter($emails, function($e) {
        return filter_var($e, FILTER_VALIDATE_EMAIL);
    })));
}

function loadTasks($sqlsrv): array {
    $sql = "
    SELECT id, employee_number, employee_name, hire_date AS contract_date, due_date, week_bucket, status
    FROM tareas_gestion_talento
    WHERE task_code = 'SEGUNDO_CONTRATO'
      AND week_bucket IN ('VENCIDA', 'ACTUAL', 'PROXIMA')
      AND status IN ('PENDIENTE', 'EN_PROCESO')
    ORDER BY
      CASE week_bucket WHEN 'VENCIDA' THEN 1 WHEN 'ACTUAL' THEN 2 WHEN 'PROXIMA' THEN 3 ELSE 4 END,
      due_date ASC,
      employee_number ASC
    ";

    $result = sqlsrv_query($sqlsrv, $sql);
    if ($result === false) {
        $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        $msg = is_array($errors) && isset($errors[0]['message']) ? $errors[0]['message'] : 'Error desconocido';
        throw new RuntimeException('Error consultando tareas: ' . $msg);
    }

    $vencidas = [];
    $actuales = [];
    $proximas = [];

    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        if ($row['contract_date'] instanceof DateTimeInterface) $row['contract_date'] = $row['contract_date']->format('Y-m-d');
        if ($row['due_date'] instanceof DateTimeInterface) $row['due_date'] = $row['due_date']->format('Y-m-d');

        if ($row['week_bucket'] === 'VENCIDA') $vencidas[] = $row;
        elseif ($row['week_bucket'] === 'ACTUAL') $actuales[] = $row;
        else $proximas[] = $row;
    }

    sqlsrv_free_stmt($result);
    return [$vencidas, $actuales, $proximas];
}

function buildHtml(array $vencidas, array $actuales, array $proximas): string {
    $today = date('d/m/Y');

    $renderRows = function(array $items, string $emptyMsg) {
        if (empty($items)) {
            return '<tr><td colspan="5" style="padding:12px 14px;color:#9ca3af;font-size:13px;font-style:italic;">'
                . htmlspecialchars($emptyMsg) . '</td></tr>';
        }
        $rows = '';
        foreach ($items as $t) {
            $contractFormatted = !empty($t['contract_date']) ? date('d/m/Y', strtotime((string)$t['contract_date'])) : '-';
            $dueFormatted = !empty($t['due_date']) ? date('d/m/Y', strtotime((string)$t['due_date'])) : '-';
            $rows .= '<tr>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#374151;">' . htmlspecialchars((string)$t['employee_number']) . '</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#1f1754;font-weight:600;">' . htmlspecialchars((string)$t['employee_name']) . '</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;">' . $contractFormatted . '</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;">' . $dueFormatted . '</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:12px;color:#374151;">' . htmlspecialchars((string)$t['status']) . '</td>'
                . '</tr>';
        }
        return $rows;
    };

    $totalVencidas = count($vencidas);
    $totalActuales = count($actuales);
    $totalProximas = count($proximas);

    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head><body style="font-family:Segoe UI,Tahoma,Arial,sans-serif;background:#f1f0fb;padding:24px;">'
        . '<table width="100%" style="max-width:760px;margin:0 auto;background:#fff;border:1px solid #e2daff;border-radius:12px;padding:20px;">'
        . '<tr><td><h2 style="margin:0;color:#1f1754;">Resumen semanal - Segundo contrato</h2><p style="color:#6b7280;">Generado el ' . $today . '</p></td></tr>'
        . '<tr><td><p style="color:#374151;">Seguimiento de tareas de segundo contrato (evento de un solo dia).</p></td></tr>'
        . '<tr><td><h3>Vencidas (' . $totalVencidas . ')</h3></td></tr>'
        . '<tr><td><table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e5e7eb;">'
        . '<thead><tr style="background:#f9fafb;"><th style="padding:8px;">No. Empleado</th><th style="padding:8px;">Nombre</th><th style="padding:8px;">Fecha fin 1er contrato</th><th style="padding:8px;">Fecha fin 2do contrato</th><th style="padding:8px;">Estatus</th></tr></thead><tbody>' . $renderRows($vencidas, 'Sin tareas vencidas') . '</tbody></table></td></tr>'
        . '<tr><td><h3>Esta semana (' . $totalActuales . ')</h3></td></tr>'
        . '<tr><td><table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e5e7eb;">'
        . '<thead><tr style="background:#f9fafb;"><th style="padding:8px;">No. Empleado</th><th style="padding:8px;">Nombre</th><th style="padding:8px;">Fecha fin 1er contrato</th><th style="padding:8px;">Fecha fin 2do contrato</th><th style="padding:8px;">Estatus</th></tr></thead><tbody>' . $renderRows($actuales, 'Sin tareas para esta semana') . '</tbody></table></td></tr>'
        . '<tr><td><h3>Proxima semana (' . $totalProximas . ')</h3></td></tr>'
        . '<tr><td><table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e5e7eb;">'
        . '<thead><tr style="background:#f9fafb;"><th style="padding:8px;">No. Empleado</th><th style="padding:8px;">Nombre</th><th style="padding:8px;">Fecha fin 1er contrato</th><th style="padding:8px;">Fecha fin 2do contrato</th><th style="padding:8px;">Estatus</th></tr></thead><tbody>' . $renderRows($proximas, 'Sin tareas para la proxima semana') . '</tbody></table></td></tr>'
        . '</table></body></html>';
}

try {
    $sqlsrv = getSqlServerConnection();

    list($vencidas, $actuales, $proximas) = loadTasks($sqlsrv);
    $to = findRecipients($sqlsrv, 'SEGUNDO_CONTRATO');

    if (empty($to)) {
        throw new RuntimeException('No hay destinatarios para SEGUNDO_CONTRATO.');
    }

    $subject = 'Tareas semanales de Gestion de Talento - Segundo contrato';
    $html = buildHtml($vencidas, $actuales, $proximas);
    $plain = 'Vencidas: ' . count($vencidas) . ' | Esta semana: ' . count($actuales) . ' | Proxima semana: ' . count($proximas);

    send_email_smtp($to, $subject, $html, $plain);

    $updateMetaSql = "
        UPDATE tareas_gestion_talento
        SET metadata_json = JSON_MODIFY(
                    COALESCE(metadata_json, '{}'),
                    '$.last_weekly_notification_at',
                    CONVERT(VARCHAR(19), GETDATE(), 126)
                )
        WHERE task_code = 'SEGUNDO_CONTRATO'
          AND week_bucket IN ('VENCIDA', 'ACTUAL', 'PROXIMA')
          AND status IN ('PENDIENTE', 'EN_PROCESO')
    ";
    sqlsrv_query($sqlsrv, $updateMetaSql);

    log_msg('Notificacion semanal enviada para SEGUNDO_CONTRATO.');
    echo 'Notificacion enviada. Destinatarios: ' . count($to) . PHP_EOL;

    sqlsrv_close($sqlsrv);
} catch (Throwable $e) {
    log_msg('ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
