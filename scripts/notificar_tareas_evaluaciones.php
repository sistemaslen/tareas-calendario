<?php

date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/../config/sqlserver.php';
require_once __DIR__ . '/mailer.php';

function log_msg($msg) {
    $line = date('Y-m-d H:i:s') . ' | ' . $msg . PHP_EOL;
    file_put_contents(__DIR__ . '/../logs/tareas_evaluaciones_notificacion.log', $line, FILE_APPEND);
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
        throw new RuntimeException('Error consultando roles: ' . (is_array($errors) && isset($errors[0]['message']) ? $errors[0]['message'] : 'desconocido'));
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
    if ($destResult !== false) {
        while ($d = sqlsrv_fetch_array($destResult, SQLSRV_FETCH_ASSOC)) {
            if ($d['destination_type'] === 'EMAIL') $emails[] = trim((string)$d['destination_value']);
            if ($d['destination_type'] === 'ROLE')  $roleTargets[] = normalizeText((string)$d['destination_value']);
        }
        sqlsrv_free_stmt($destResult);
    }
    if (empty($roleTargets)) $roleTargets = ['RH', 'ADMIN'];
    $emails = array_merge($emails, getUserEmailsByRoles($sqlsrv, $roleTargets));
    return array_values(array_unique(array_filter($emails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
}

function loadTasksByCode($sqlsrv, string $taskCode): array {
    $sql = "
        SELECT id, employee_number, employee_name, hire_date AS anchor_date, due_date, week_bucket, status
        FROM tareas_gestion_talento
        WHERE task_code = ?
          AND week_bucket IN ('VENCIDA', 'ACTUAL', 'PROXIMA')
          AND status IN ('PENDIENTE', 'EN_PROCESO')
        ORDER BY
          CASE week_bucket WHEN 'VENCIDA' THEN 1 WHEN 'ACTUAL' THEN 2 WHEN 'PROXIMA' THEN 3 ELSE 4 END,
          due_date ASC,
          employee_number ASC
    ";
    $result = sqlsrv_query($sqlsrv, $sql, [$taskCode]);
    if ($result === false) {
        $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        throw new RuntimeException('Error consultando tareas ' . $taskCode . ': ' . (is_array($errors) && isset($errors[0]['message']) ? $errors[0]['message'] : 'desconocido'));
    }
    $vencidas = []; $actuales = []; $proximas = [];
    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        if ($row['anchor_date'] instanceof DateTimeInterface) $row['anchor_date'] = $row['anchor_date']->format('Y-m-d');
        if ($row['due_date']   instanceof DateTimeInterface) $row['due_date']     = $row['due_date']->format('Y-m-d');
        if ($row['week_bucket'] === 'VENCIDA')     $vencidas[] = $row;
        elseif ($row['week_bucket'] === 'ACTUAL')  $actuales[] = $row;
        else                                        $proximas[] = $row;
    }
    sqlsrv_free_stmt($result);
    return [$vencidas, $actuales, $proximas];
}

function renderSection(array $vencidas, array $actuales, array $proximas, string $anchorLabel, string $dueLabel): string {
    $thStyle = 'padding:8px;text-align:left;';
    $headerRow = '<thead><tr style="background:#f9fafb;">'
        . '<th style="' . $thStyle . '">No. Empleado</th>'
        . '<th style="' . $thStyle . '">Nombre</th>'
        . '<th style="' . $thStyle . '">' . htmlspecialchars($anchorLabel) . '</th>'
        . '<th style="' . $thStyle . '">' . htmlspecialchars($dueLabel) . '</th>'
        . '<th style="' . $thStyle . '">Estatus</th>'
        . '</tr></thead>';

    $renderRows = function(array $items, string $emptyMsg) {
        if (empty($items)) {
            return '<tr><td colspan="5" style="padding:12px 14px;color:#9ca3af;font-size:13px;font-style:italic;">' . htmlspecialchars($emptyMsg) . '</td></tr>';
        }
        $rows = '';
        foreach ($items as $t) {
            $anchorFmt = !empty($t['anchor_date']) ? date('d/m/Y', strtotime((string)$t['anchor_date'])) : '-';
            $dueFmt    = !empty($t['due_date'])    ? date('d/m/Y', strtotime((string)$t['due_date']))    : '-';
            $rows .= '<tr>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;">' . htmlspecialchars((string)$t['employee_number']) . '</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#1f1754;font-weight:600;">' . htmlspecialchars((string)$t['employee_name']) . '</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;">' . $anchorFmt . '</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;">' . $dueFmt . '</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:12px;">' . htmlspecialchars((string)$t['status']) . '</td>'
                . '</tr>';
        }
        return $rows;
    };

    $html = '';
    foreach ([
        ['label' => 'Vencidas (' . count($vencidas) . ')', 'items' => $vencidas, 'empty' => 'Sin tareas vencidas'],
        ['label' => 'Esta semana (' . count($actuales) . ')', 'items' => $actuales, 'empty' => 'Sin tareas para esta semana'],
        ['label' => 'Proxima semana (' . count($proximas) . ')', 'items' => $proximas, 'empty' => 'Sin tareas para la proxima semana'],
    ] as $group) {
        $html .= '<tr><td><h3 style="margin:16px 0 4px;">' . $group['label'] . '</h3></td></tr>';
        $html .= '<tr><td><table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e5e7eb;">'
            . $headerRow . '<tbody>' . $renderRows($group['items'], $group['empty']) . '</tbody></table></td></tr>';
    }
    return $html;
}

function buildHtml(array $p1, array $p2, array $s1, array $s2, array $s3, array $s4): string {
    [$vencidas1, $actuales1, $proximas1] = [$p1, $p2, []]; // re-use passed groups
    $today = date('d/m/Y');
    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Segoe UI,Tahoma,Arial,sans-serif;background:#f1f0fb;padding:24px;">'
        . '<table width="100%" style="max-width:800px;margin:0 auto;background:#fff;border:1px solid #e2daff;border-radius:12px;padding:20px;">'
        . '<tr><td><h2 style="margin:0;color:#1f1754;">Resumen semanal - Evaluaciones y autoevaluaciones</h2>'
        . '<p style="color:#6b7280;">Generado el ' . $today . '</p></td></tr>'
        . '<tr><td><p style="color:#374151;">La autoevaluacion del colaborador toma la misma fecha que la evaluacion.</p></td></tr>'

        . '<tr><td><h2 style="color:#1f1754;border-top:2px solid #e2daff;padding-top:12px;">Primera evaluacion (dia 21)</h2></td></tr>'
        . renderSection($p1[0], $p1[1], $p1[2], 'Fecha ingreso', 'Fecha evaluacion')

        . '<tr><td><h2 style="color:#1f1754;border-top:2px solid #e2daff;padding-top:12px;">Segunda evaluacion (dia 49)</h2></td></tr>'
        . renderSection($s1[0], $s1[1], $s1[2], 'Fecha 1ra evaluacion', 'Fecha 2da evaluacion')

        . '</table></body></html>';
}

try {
    $sqlsrv = getSqlServerConnection();

    [$v1, $a1, $p1] = loadTasksByCode($sqlsrv, 'PRIMERA_EVALUACION');
    [$v2, $a2, $p2] = loadTasksByCode($sqlsrv, 'SEGUNDA_EVALUACION');

    // Merge recipients from both task codes
    $to1 = findRecipients($sqlsrv, 'PRIMERA_EVALUACION');
    $to2 = findRecipients($sqlsrv, 'SEGUNDA_EVALUACION');
    $to  = array_values(array_unique(array_merge($to1, $to2)));

    if (empty($to)) {
        throw new RuntimeException('No hay destinatarios para evaluaciones.');
    }

    $totalAll = count($v1) + count($a1) + count($p1) + count($v2) + count($a2) + count($p2);

    // Build HTML: pass grouped arrays
    $today = date('d/m/Y');
    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Segoe UI,Tahoma,Arial,sans-serif;background:#f1f0fb;padding:24px;">'
        . '<table width="100%" style="max-width:800px;margin:0 auto;background:#fff;border:1px solid #e2daff;border-radius:12px;padding:20px;">'
        . '<tr><td><h2 style="margin:0;color:#1f1754;">Resumen semanal - Evaluaciones y autoevaluaciones</h2>'
        . '<p style="color:#6b7280;">Generado el ' . $today . '</p></td></tr>'
        . '<tr><td><p style="color:#374151;">La autoevaluacion del colaborador toma la misma fecha que la evaluacion.</p></td></tr>'
        . '<tr><td><h2 style="color:#1f1754;border-top:2px solid #e2daff;padding-top:12px;">Primera evaluacion — dia 21 desde ingreso</h2></td></tr>'
        . renderSection($v1, $a1, $p1, 'Fecha ingreso', 'Fecha 1ra evaluacion')
        . '<tr><td><h2 style="color:#1f1754;border-top:2px solid #e2daff;padding-top:12px;">Segunda evaluacion — dia 49 desde ingreso</h2></td></tr>'
        . renderSection($v2, $a2, $p2, 'Fecha 1ra evaluacion', 'Fecha 2da evaluacion')
        . '</table></body></html>';

    $plain = 'Primera eval — Vencidas: ' . count($v1) . ' | Esta semana: ' . count($a1) . ' | Proxima: ' . count($p1)
           . ' || Segunda eval — Vencidas: ' . count($v2) . ' | Esta semana: ' . count($a2) . ' | Proxima: ' . count($p2);

    $subject = 'Tareas semanales de Gestion de Talento - Evaluaciones y autoevaluaciones';
    send_email_smtp($to, $subject, $html, $plain);

    // Marcar notificacion enviada en ambos task_codes
    foreach (['PRIMERA_EVALUACION', 'SEGUNDA_EVALUACION'] as $code) {
        $updateMetaSql = "
            UPDATE tareas_gestion_talento
            SET metadata_json = JSON_MODIFY(
                        COALESCE(metadata_json, '{}'),
                        '$.last_weekly_notification_at',
                        CONVERT(VARCHAR(19), GETDATE(), 126)
                    )
            WHERE task_code = '$code'
              AND week_bucket IN ('VENCIDA', 'ACTUAL', 'PROXIMA')
              AND status IN ('PENDIENTE', 'EN_PROCESO')
        ";
        sqlsrv_query($sqlsrv, $updateMetaSql);
    }

    log_msg("Notificacion semanal enviada. Destinatarios: " . count($to) . ". Total tareas: $totalAll");
    echo 'Notificacion enviada. Destinatarios: ' . count($to) . PHP_EOL;

    sqlsrv_close($sqlsrv);
} catch (Throwable $e) {
    log_msg('ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
