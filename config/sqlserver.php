<?php

/**
 * Conexion a GestionTalento: BD principal que contiene empleados,
 * info de talento y tareas de gestion.
 *
 * Credenciales por defecto: host=192.168.0.137, db=GestionTalento, user=integra
 * Se pueden sobreescribir con variables de entorno: GT_DB_HOST, GT_DB_PORT,
 * GT_DB_NAME, GT_DB_USER, GT_DB_PASSWORD
 */
function getSqlServerConnection(): mixed {
    $serverHost = getenv('GT_DB_HOST') ?: '192.168.0.137';
    $serverPort = getenv('GT_DB_PORT') ?: '1433';
    $serverName = $serverHost . ',' . $serverPort;

    $dbName = getenv('GT_DB_NAME') ?: 'GestionTalento';
    // GT_DB_USER > DB_USER > credencial hardcodeada de respaldo
    $user   = getenv('GT_DB_USER') ?: (getenv('DB_USER') ?: 'integra');
    $pass   = getenv('GT_DB_PASSWORD') ?: (getenv('DB_PASSWORD') ?: 'hibrido');

    $opts = [
        'Database'               => $dbName,
        'CharacterSet'           => 'UTF-8',
        'TrustServerCertificate' => true,
    ];

    if ($user !== '') {
        $opts['UID'] = $user;
        $opts['PWD'] = $pass;
    }

    $conn = sqlsrv_connect($serverName, $opts);

    if ($conn === false) {
        $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        $msg = 'Error SQL Server (GestionTalento): ';
        $msg .= (is_array($errors) && !empty($errors[0]['message']))
            ? $errors[0]['message']
            : 'Error desconocido';
        die($msg);
    }

    return $conn;
}
