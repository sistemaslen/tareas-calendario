<?php

date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/../config/sqlserver.php';
require_once __DIR__ . '/mailer.php';

function log_msg($msg) {
    $line = date('Y-m-d H:i:s') . ' | ' . $msg . PHP_EOL;
    file_put_contents(__DIR__ . '/../logs/tareas_notificacion.log', $line, FILE_APPEND);
}

function normalizeText(string $value): string {
    $value = trim($value);
    $value = strtoupper($value);
    return strtr($value, [
        'Á' => 'A',
        'É' => 'E',
        'Í' => 'I',
        'Ó' => 'O',
        'Ú' => 'U',
    ]);
}

function roleMatches(string $roleName, string $expected): bool {
    return normalizeText($roleName) === normalizeText($expected);
}

function getUserEmailsByRoles($sqlsrv, array $roleTargets): array {
    if (empty($roleTargets)) {
        return [];
    }

    $emails = [];
    $roleSql = "
        SELECT DISTINCT u.email, r.name AS role_name
        FROM user_profiles up
        INNER JOIN roles r ON r.id = up.role_id
        INNER JOIN auth_user u ON u.id = up.user_id
        WHERE up.is_active = 1
          AND r.is_active = 1
          AND u.is_active = 1
          AND u.email IS NOT NULL
          AND LTRIM(RTRIM(u.email)) <> ''
    ";
    $roleRows = sqlsrv_query($sqlsrv, $roleSql);

    if ($roleRows === false) {
        $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        $msg = is_array($errors) && isset($errors[0]['message']) ? $errors[0]['message'] : 'Error desconocido';
        throw new RuntimeException('No se pudo consultar gestion de usuarios (roles/correos): ' . $msg);
    }

    while ($row = sqlsrv_fetch_array($roleRows, SQLSRV_FETCH_ASSOC)) {
        $roleName = normalizeText((string)$row['role_name']);
        foreach ($roleTargets as $targetRole) {
            $targetRoleNorm = normalizeText((string)$targetRole);
            if (roleMatches($roleName, $targetRoleNorm)
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
    WHERE task_code = ?
      AND is_active = 1
    ";
    $destResult = sqlsrv_query($sqlsrv, $destSql, [$taskCode]);
    if ($destResult === false) {
        $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        $msg = is_array($errors) && isset($errors[0]['message']) ? $errors[0]['message'] : 'Error desconocido';
        throw new RuntimeException('No se pudieron cargar destinatarios: ' . $msg);
    }

    while ($d = sqlsrv_fetch_array($destResult, SQLSRV_FETCH_ASSOC)) {
        if ($d['destination_type'] === 'EMAIL') {
            $emails[] = trim((string)$d['destination_value']);
        } elseif ($d['destination_type'] === 'ROLE') {
            $roleTargets[] = normalizeText((string)$d['destination_value']);
        }
    }
    sqlsrv_free_stmt($destResult);

    // Fallback: para ALTA_IMSS siempre tomar RH y ADMIN de gestion de usuarios.
    if ($taskCode === 'ALTA_IMSS' && empty($roleTargets)) {
        $roleTargets = ['RH', 'ADMIN'];
    }

    if (!empty($roleTargets)) {
        $emails = array_merge($emails, getUserEmailsByRoles($sqlsrv, $roleTargets));
    }

    $emails = array_values(array_unique(array_filter($emails, function($e) {
        return filter_var($e, FILTER_VALIDATE_EMAIL);
    })));

    return $emails;
}

function loadTasks($sqlsrv): array {
    $sql = "
    SELECT
      id,
      employee_number,
      employee_name,
      hire_date,
      due_date,
      week_bucket,
      status
    FROM tareas_gestion_talento
    WHERE task_code = 'ALTA_IMSS'
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
        if ($row['hire_date'] instanceof DateTimeInterface) {
            $row['hire_date'] = $row['hire_date']->format('Y-m-d');
        }
        if ($row['due_date'] instanceof DateTimeInterface) {
            $row['due_date'] = $row['due_date']->format('Y-m-d');
        }

        if ($row['week_bucket'] === 'VENCIDA') {
            $vencidas[] = $row;
        } elseif ($row['week_bucket'] === 'ACTUAL') {
            $actuales[] = $row;
        } else {
            $proximas[] = $row;
        }
    }

    sqlsrv_free_stmt($result);

    return [$vencidas, $actuales, $proximas];
}

function buildHtml(array $vencidas, array $actuales, array $proximas): string {

    $today = date('d/m/Y');

    // ── Render filas de tabla ──────────────────────────────────────────────
    $renderRows = function(array $items, string $emptyMsg) {
        if (empty($items)) {
            return '<tr><td colspan="5" style="padding:12px 14px;color:#9ca3af;font-size:13px;font-style:italic;">'
                . htmlspecialchars($emptyMsg) . '</td></tr>';
        }
        $rows = '';
        foreach ($items as $t) {
            $hireFormatted = !empty($t['hire_date'])
                ? date('d/m/Y', strtotime((string)$t['hire_date'])) : '—';
            $dueFormatted  = !empty($t['due_date'])
                ? date('d/m/Y', strtotime((string)$t['due_date'])) : '—';
            $statusLabel = match((string)$t['status']) {
                'PENDIENTE'  => 'Pendiente',
                'EN_PROCESO' => 'En proceso',
                'COMPLETADA' => 'Completada',
                'CANCELADA'  => 'Cancelada',
                default      => htmlspecialchars((string)$t['status']),
            };
            $rows .= '<tr>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#374151;">'
                    . htmlspecialchars((string)$t['employee_number']) . '</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#1f1754;font-weight:600;">'
                    . htmlspecialchars((string)$t['employee_name']) . '</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;">'
                    . $hireFormatted . '</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;">'
                    . $dueFormatted . '</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:12px;color:#374151;">'
                    . $statusLabel . '</td>'
                . '</tr>';
        }
        return $rows;
    };

    // ── Cabecera de sección ────────────────────────────────────────────────
    $sectionHeader = function(string $label, string $borderColor, string $bgColor, string $textColor, string $subtitleColor, string $subtitle) {
        return '
        <tr>
          <td style="padding:20px 32px 0;">
            <div style="border-left:4px solid ' . $borderColor . ';background:' . $bgColor . ';
                        border-radius:0 8px 8px 0;padding:12px 16px;">
              <div style="font-size:13px;font-weight:700;color:' . $textColor . ';letter-spacing:0.03em;">'
                    . $label . '</div>
              <div style="font-size:12px;color:' . $subtitleColor . ';margin-top:3px;">' . $subtitle . '</div>
            </div>
          </td>
        </tr>';
    };

    // ── Tabla de tareas ────────────────────────────────────────────────────
    $sectionTable = function(string $rows) {
        return '
        <tr>
          <td style="padding:10px 32px 4px;">
            <table width="100%" cellpadding="0" cellspacing="0"
                   style="border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
              <thead>
                <tr style="background:#f9fafb;">
                  <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;
                              color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;
                              border-bottom:1px solid #e5e7eb;">No. Empleado</th>
                  <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;
                              color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;
                              border-bottom:1px solid #e5e7eb;">Nombre</th>
                  <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;
                              color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;
                              border-bottom:1px solid #e5e7eb;">Fecha ingreso</th>
                  <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;
                              color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;
                              border-bottom:1px solid #e5e7eb;">Fecha límite</th>
                  <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;
                              color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;
                              border-bottom:1px solid #e5e7eb;">Estatus</th>
                </tr>
              </thead>
              <tbody>' . $rows . '</tbody>
            </table>
          </td>
        </tr>';
    };

    $totalVencidas = count($vencidas);
    $totalActuales = count($actuales);
    $totalProximas = count($proximas);
    $total = $totalVencidas + $totalActuales + $totalProximas;

    $vencidasRows  = $renderRows($vencidas, 'Sin tareas vencidas');
    $actualesRows  = $renderRows($actuales, 'Sin tareas para esta semana');
    $proximasRows  = $renderRows($proximas, 'Sin tareas para la próxima semana');

    $alertBanner = $totalVencidas > 0
        ? '<tr><td style="padding:0 32px 0;">
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;
                        padding:12px 16px;display:flex;align-items:center;">
              <span style="font-size:18px;margin-right:10px;">⚠️</span>
              <span style="font-size:13px;color:#b91c1c;font-weight:600;">
                ' . $totalVencidas . ' ' . ($totalVencidas === 1 ? 'alta IMSS vencida' : 'altas IMSS vencidas')
                . ' — requiere atención inmediata</span>
            </div>
          </td></tr>'
        : '';

    return '<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#f1f0fb;font-family:\'Segoe UI\',Tahoma,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f0fb;padding:32px 16px;">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0"
       style="max-width:640px;background:#ffffff;border-radius:16px;overflow:hidden;
              box-shadow:0 4px 24px rgba(103,83,246,0.10);border:1px solid #e2daff;">

  <!-- HEADER -->
  <tr>
    <td style="background:#6753f6;padding:28px 32px 24px;">
      <div style="font-size:10px;font-weight:700;letter-spacing:0.16em;text-transform:uppercase;
                  color:rgba(255,255,255,0.65);margin-bottom:8px;">
        Sistema Integral · Gestión de Talento
      </div>
      <div style="font-size:22px;font-weight:700;color:#ffffff;line-height:1.25;">
        Resumen semanal — Alta IMSS
      </div>
      <div style="font-size:13px;color:rgba(255,255,255,0.75);margin-top:6px;">
        Generado el ' . $today . ' · ' . $total . ' ' . ($total === 1 ? 'tarea activa' : 'tareas activas') . '
      </div>
    </td>
  </tr>

  <!-- INTRO -->
  <tr>
    <td style="padding:24px 32px 8px;">
      <p style="margin:0;font-size:14px;color:#374151;line-height:1.7;">
        A continuación el resumen de <strong style="color:#1f1754;">Altas del IMSS</strong>
        pendientes de entrega a Nóminas. Las altas deben entregarse dentro de los primeros
        <strong>5 días hábiles</strong> desde el ingreso del colaborador.
      </p>
    </td>
  </tr>

  <!-- ALERT BANNER (solo si hay vencidas) -->
  ' . $alertBanner . '

  <!-- DIVIDER -->
  <tr><td style="padding:16px 32px 0;">
    <div style="border-top:1px solid #ece9ff;"></div>
  </td></tr>

  <!-- SECCIÓN VENCIDAS -->
  ' . $sectionHeader(
        '⛔ Vencidas (' . $totalVencidas . ')',
        '#dc2626', '#fef2f2', '#b91c1c', '#ef4444',
        'Pasaron los 5 días hábiles sin registrar la entrega'
    ) . '
  ' . $sectionTable($vencidasRows) . '

  <!-- SECCIÓN ACTUALES -->
  ' . $sectionHeader(
        '🔴 Esta semana (' . $totalActuales . ')',
        '#ea580c', '#fff7ed', '#c2410c', '#fb923c',
        'Fecha límite dentro de los próximos 7 días'
    ) . '
  ' . $sectionTable($actualesRows) . '

  <!-- SECCIÓN PRÓXIMAS -->
  ' . $sectionHeader(
        '🔵 Próxima semana (' . $totalProximas . ')',
        '#0284c7', '#f0f9ff', '#0369a1', '#38bdf8',
        'Fecha límite en la semana siguiente'
    ) . '
  ' . $sectionTable($proximasRows) . '

  <!-- DIVIDER -->
  <tr><td style="padding:20px 32px 0;">
    <div style="border-top:1px solid #ece9ff;"></div>
  </td></tr>

  <!-- FOOTER -->
  <tr>
    <td style="padding:16px 32px 24px;">
      <p style="margin:0 0 10px;font-size:11px;color:#9ca3af;line-height:1.6;">
        Este mensaje fue generado automáticamente. Por favor no respondas a este correo.<br>
        © 2026 Grupo LEN &middot; Talento Humano
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>';
}


try {
    $sqlsrv = getSqlServerConnection();

    list($vencidas, $actuales, $proximas) = loadTasks($sqlsrv);
    $to = findRecipients($sqlsrv, 'ALTA_IMSS');

    if (empty($to)) {
        throw new RuntimeException('No hay destinatarios configurados para ALTA_IMSS y no se encontraron usuarios RH/Admin con correo.');
    }

    $subject = 'Tareas semanales de Gestión de Talento — Alta IMSS';
    $html = buildHtml($vencidas, $actuales, $proximas);
    $plain = 'Vencidas: ' . count($vencidas) . ' | Esta semana: ' . count($actuales) . ' | Próxima semana: ' . count($proximas);

    send_email_smtp($to, $subject, $html, $plain);

        $updateMetaSql = "
            UPDATE tareas_gestion_talento
            SET metadata_json = JSON_MODIFY(
                        COALESCE(metadata_json, '{}'),
                        '$.last_weekly_notification_at',
                        CONVERT(VARCHAR(19), GETDATE(), 126)
                    )
            WHERE task_code = 'ALTA_IMSS'
                AND week_bucket IN ('VENCIDA', 'ACTUAL', 'PROXIMA')
                AND status IN ('PENDIENTE', 'EN_PROCESO')
        ";
        sqlsrv_query($sqlsrv, $updateMetaSql);

    log_msg('Notificacion semanal enviada a: ' . implode(', ', $to)
        . ' | Vencidas: ' . count($vencidas)
        . ' | Actuales: ' . count($actuales)
        . ' | Proximas: ' . count($proximas));
    echo 'Notificacion enviada. Destinatarios: ' . count($to) . PHP_EOL;

    sqlsrv_close($sqlsrv);
} catch (Throwable $e) {
    log_msg('ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
