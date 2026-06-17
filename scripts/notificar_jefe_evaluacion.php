<?php

/**
 * notificar_jefe_evaluacion.php
 *
 * Envía un correo al jefe directo de cada colaborador cuya primera
 * evaluacion cae en la semana actual o proxima, recordandole que debe
 * completar la evaluacion y la autoevaluacion de su subordinado.
 *
 * Origen de datos:
 *  - employees.manager_id  → ForeignKey al empleado jefe
 *  - employee_general_info.corporate_email del jefe
 * Solo colaboradores con status = 'ALTA'.
 */

date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/../config/sqlserver.php';
require_once __DIR__ . '/mailer.php';

function log_msg($msg) {
    $line = date('Y-m-d H:i:s') . ' | ' . $msg . PHP_EOL;
    file_put_contents(__DIR__ . '/../logs/notificacion_jefe_evaluacion.log', $line, FILE_APPEND);
}

function startOfWeek(DateTime $date): DateTime {
    $copy = clone $date;
    $copy->setTime(0, 0, 0);
    $dayOfWeek = (int)$copy->format('N');
    $copy->modify('-' . ($dayOfWeek - 1) . ' days');
    return $copy;
}

function generatedDocsRootCandidates(): array {
    $candidates = [];

    $envRoot = getenv('GENERATED_DOCS_ROOT');
    if (is_string($envRoot) && trim($envRoot) !== '') {
        $candidates[] = trim($envRoot);
    }

    $envDir = getenv('GENERATED_DOCS_DIR');
    if (is_string($envDir) && trim($envDir) !== '') {
        $candidates[] = trim($envDir);
    }

    // Ruta local tipica cuando backend y sync viven bajo c:/apache/htdocs
    $candidates[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'talent-management-app' . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'generated_documents';
    // Ruta por defecto dentro del contenedor backend
    $candidates[] = '/app/media/generated_documents';
    // Fallback relativo al proyecto de scripts
    $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'generated_documents';

    $unique = [];
    $seen = [];
    foreach ($candidates as $path) {
        $normalized = rtrim((string)$path, "\\/");
        if ($normalized === '' || isset($seen[$normalized])) {
            continue;
        }
        $seen[$normalized] = true;
        $unique[] = $normalized;
    }

    return $unique;
}

function resolveGeneratedFilePath(string $relativePath): ?string {
    $relativePath = trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relativePath));
    if ($relativePath === '') {
        return null;
    }

    foreach (generatedDocsRootCandidates() as $root) {
        $fullPath = $root . DIRECTORY_SEPARATOR . $relativePath;
        if (is_file($fullPath)) {
            return $fullPath;
        }
    }

    return null;
}

function findEvaluationAttachmentForEmployee($sqlsrv, int $employeeId): ?array {
    $sql = "
        SELECT TOP 1 relative_path, file_name
        FROM employee_generated_documents
        WHERE employee_id = ?
          AND (
                file_name COLLATE Latin1_General_CI_AI LIKE '%evaluacion%desempeno%'
                OR template_name COLLATE Latin1_General_CI_AI LIKE '%evaluacion%desempeno%'
          )
                ORDER BY
                        CASE WHEN LOWER(LTRIM(RTRIM(COALESCE(category, '')))) = 'alta' THEN 0 ELSE 1 END,
                        updated_at DESC,
                        created_at DESC
    ";

    $result = sqlsrv_query($sqlsrv, $sql, [$employeeId]);
    if ($result === false) {
        return null;
    }

    $row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($result);

    if (!$row || empty($row['relative_path'])) {
        return null;
    }

    $fullPath = resolveGeneratedFilePath((string)$row['relative_path']);
    if (!$fullPath) {
        return null;
    }

    return [
        'path' => $fullPath,
        'name' => (string)($row['file_name'] ?? basename($fullPath)),
    ];
}

function buildManagerHtml(string $managerName, array $subordinates, string $today): string {
    $rows = '';
    foreach ($subordinates as $s) {
        $hireFmt  = !empty($s['hire_date'])  ? date('d/m/Y', strtotime((string)$s['hire_date']))  : '-';
        $evalFmt  = !empty($s['eval_date'])  ? date('d/m/Y', strtotime((string)$s['eval_date']))  : '-';
        $bucketLabel = match($s['bucket']) {
            'ACTUAL'  => '<span style="color:#d97706;font-weight:600;">Esta semana</span>',
            'PROXIMA' => '<span style="color:#2563eb;">Proxima semana</span>',
            'VENCIDA' => '<span style="color:#dc2626;font-weight:600;">Vencida</span>',
            default   => htmlspecialchars((string)$s['bucket']),
        };
        $docLabel = !empty($s['has_attachment'])
            ? '<span style="color:#166534;font-weight:600;">Adjunto</span>'
            : '<span style="color:#9ca3af;">No encontrado</span>';
        $rows .= '<tr>'
            . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#374151;">' . htmlspecialchars((string)$s['employee_number']) . '</td>'
            . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#1f1754;font-weight:600;">' . htmlspecialchars((string)$s['employee_name']) . '</td>'
            . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;">' . $hireFmt . '</td>'
            . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;">' . $evalFmt . '</td>'
            . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;">' . $bucketLabel . '</td>'
            . '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;">' . $docLabel . '</td>'
            . '</tr>';
    }

    $managerNameSafe = htmlspecialchars($managerName ?: 'Estimado/a jefe');

    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Segoe UI,Tahoma,Arial,sans-serif;background:#f1f0fb;padding:24px;">'
        . '<table width="100%" style="max-width:720px;margin:0 auto;background:#fff;border:1px solid #e2daff;border-radius:12px;padding:20px;">'

        . '<tr><td>'
        . '<h2 style="margin:0;color:#1f1754;">Recordatorio: Evaluacion de colaboradores a tu cargo</h2>'
        . '<p style="color:#6b7280;margin:4px 0 16px;">Generado el ' . $today . '</p>'
        . '</td></tr>'

        . '<tr><td>'
        . '<p style="color:#374151;">Hola ' . $managerNameSafe . ',</p>'
        . '<p style="color:#374151;">El siguiente colaborador bajo tu supervision tiene su <strong>primera evaluacion y autoevaluacion</strong> proxima o en curso. '
        . 'Por favor completa tanto la <strong>evaluacion de desempeno</strong> como la <strong>autoevaluacion</strong> en las fechas indicadas.</p>'
        . '</td></tr>'

        . '<tr><td>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e5e7eb;">'
        . '<thead><tr style="background:#f9fafb;">'
        . '<th style="padding:8px;text-align:left;">No. Empleado</th>'
        . '<th style="padding:8px;text-align:left;">Nombre</th>'
        . '<th style="padding:8px;text-align:left;">Fecha ingreso</th>'
        . '<th style="padding:8px;text-align:left;">Fecha evaluacion (dia 21)</th>'
        . '<th style="padding:8px;text-align:left;">Urgencia</th>'
        . '<th style="padding:8px;text-align:left;">Documento evaluacion</th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody>'
        . '</table>'
        . '</td></tr>'

        . '<tr><td>'
        . '<p style="color:#6b7280;font-size:12px;margin-top:20px;">'
        . 'Este mensaje es generado automaticamente por el sistema de Gestion de Talento. '
        . 'Si tienes dudas, contacta al area de Recursos Humanos.'
        . '</p>'
        . '</td></tr>'

        . '</table></body></html>';
}

try {
    $sqlsrv = getSqlServerConnection();

    $today      = new DateTime('today');
    $weekStart  = startOfWeek($today);
    $weekEnd    = (clone $weekStart)->modify('+6 days');
    $nextStart  = (clone $weekStart)->modify('+7 days');
    $nextEnd    = (clone $weekStart)->modify('+13 days');

    $todayStr = $today->format('Y-m-d');
    $weekStartStr = $weekStart->format('Y-m-d');
    $weekEndStr   = $weekEnd->format('Y-m-d');
    $nextStartStr = $nextStart->format('Y-m-d');
    $nextEndStr   = $nextEnd->format('Y-m-d');

    log_msg("==== INICIO NOTIFICACION JEFE EVALUACION ====");
    log_msg("Ventana: ACTUAL $weekStartStr a $weekEndStr | PROXIMA $nextStartStr a $nextEndStr");

    // Traemos empleados ALTA con primera evaluacion en ventana ACTUAL o PROXIMA,
    // junto con datos de su jefe directo (manager_id).
    $sql = "
    SELECT
        e.id           AS employee_id,
        e.employee_number,
        e.manager_id,
        jh.hire_date,
        jh.first_performance_evaluation_sent AS eval_date,
        COALESCE(gi.full_name, '')            AS employee_name,
        COALESCE(mgi.full_name, '')           AS manager_name,
        COALESCE(mgi.corporate_email, '')     AS manager_email
    FROM employees e
    INNER JOIN employee_job_history jh ON jh.employee_id = e.id
    LEFT JOIN employee_general_info gi  ON gi.employee_id  = e.id
    LEFT JOIN employee_general_info mgi ON mgi.employee_id = e.manager_id
    WHERE jh.first_performance_evaluation_sent IS NOT NULL
      AND e.status   = 'ALTA'
      AND e.manager_id IS NOT NULL
      AND (
            -- ACTUAL: vence esta semana
            (jh.first_performance_evaluation_sent >= ? AND jh.first_performance_evaluation_sent <= ?)
            OR
            -- PROXIMA: vence la semana siguiente
            (jh.first_performance_evaluation_sent >= ? AND jh.first_performance_evaluation_sent <= ?)
            OR
            -- VENCIDA: ya paso pero aun no completada (incluir para que el jefe actue)
            jh.first_performance_evaluation_sent < ?
      )
    ORDER BY mgi.full_name ASC, jh.first_performance_evaluation_sent ASC
    ";

    $result = sqlsrv_query($sqlsrv, $sql, [
        $weekStartStr, $weekEndStr,
        $nextStartStr, $nextEndStr,
        $todayStr,
    ]);

    if ($result === false) {
        $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        throw new RuntimeException('Error en query: ' . (is_array($errors) && isset($errors[0]['message']) ? $errors[0]['message'] : 'desconocido'));
    }

    // Agrupar por manager_email
    $byManager = [];
    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        $managerEmail = trim((string)($row['manager_email'] ?? ''));
        if (!filter_var($managerEmail, FILTER_VALIDATE_EMAIL)) {
            log_msg("Sin email valido para manager_id={$row['manager_id']} (empleado {$row['employee_number']}) — omitido");
            continue;
        }

        if ($row['hire_date'] instanceof DateTimeInterface) $row['hire_date'] = $row['hire_date']->format('Y-m-d');
        if ($row['eval_date'] instanceof DateTimeInterface) $row['eval_date'] = $row['eval_date']->format('Y-m-d');

        // Calcular bucket de urgencia
        $evalDt = DateTime::createFromFormat('Y-m-d', (string)$row['eval_date']);
        if (!$evalDt) continue;
        $evalDt->setTime(0,0,0);
        $todayDt = clone $today; $todayDt->setTime(0,0,0);
        if ($evalDt < $todayDt) {
            $bucket = 'VENCIDA';
        } elseif ($evalDt >= $weekStart && $evalDt <= $weekEnd) {
            $bucket = 'ACTUAL';
        } else {
            $bucket = 'PROXIMA';
        }

        if (!isset($byManager[$managerEmail])) {
            $byManager[$managerEmail] = [
                'manager_name' => (string)($row['manager_name'] ?? ''),
                'subordinates' => [],
                'attachments' => [],
            ];
        }

        $attachment = findEvaluationAttachmentForEmployee($sqlsrv, (int)$row['employee_id']);
        $hasAttachment = $attachment !== null;
        if ($attachment !== null) {
            $byManager[$managerEmail]['attachments'][] = $attachment;
        }

        $byManager[$managerEmail]['subordinates'][] = [
            'employee_id'     => (int)$row['employee_id'],
            'employee_number' => (string)($row['employee_number'] ?? ''),
            'employee_name'   => (string)($row['employee_name'] ?? ''),
            'hire_date'       => (string)($row['hire_date'] ?? ''),
            'eval_date'       => (string)($row['eval_date'] ?? ''),
            'bucket'          => $bucket,
            'has_attachment'  => $hasAttachment,
        ];
    }
    sqlsrv_free_stmt($result);

    if (empty($byManager)) {
        log_msg('No hay jefes con colaboradores en ventana de evaluacion. Sin envios.');
        echo 'Sin notificaciones que enviar.' . PHP_EOL;
        sqlsrv_close($sqlsrv);
        exit(0);
    }

    $todayFormatted = date('d/m/Y');
    $sent = 0;

    foreach ($byManager as $managerEmail => $data) {
        $managerName  = $data['manager_name'];
        $subordinates = $data['subordinates'];
        $attachments  = $data['attachments'];

        // Deduplicar adjuntos por ruta
        $uniqueAttachments = [];
        $seenAttachmentPaths = [];
        foreach ($attachments as $attachment) {
            $path = (string)($attachment['path'] ?? '');
            if ($path === '' || isset($seenAttachmentPaths[$path])) {
                continue;
            }
            $seenAttachmentPaths[$path] = true;
            $uniqueAttachments[] = $attachment;
        }

        $html  = buildManagerHtml($managerName, $subordinates, $todayFormatted);
        $plain = 'Tienes ' . count($subordinates) . ' colaborador(es) con primera evaluacion proxima o vencida. '
            . 'Adjuntos enviados: ' . count($uniqueAttachments) . '. Revisa el correo HTML.';
        $subject = 'Recordatorio: Evaluacion de tu(s) colaborador(es) — Dia 21';

        send_email_smtp([$managerEmail], $subject, $html, $plain, $uniqueAttachments);
        log_msg("Email enviado a $managerEmail ($managerName) — "
            . count($subordinates)
            . ' colaborador(es), adjuntos='
            . count($uniqueAttachments));
        $sent++;
    }

    log_msg("==== FIN NOTIFICACION JEFE EVALUACION — $sent emails enviados ====");
    echo "Emails enviados: $sent" . PHP_EOL;

    sqlsrv_close($sqlsrv);
} catch (Throwable $e) {
    log_msg('ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
