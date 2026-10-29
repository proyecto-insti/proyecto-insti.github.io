<?php
/**
 * Conexión PDO a MySQL para el módulo PHP.
 * Lee las variables DB_* del archivo .env de la raíz del proyecto: es la
 * misma fuente de configuración que usa app.py (Flask), de modo que ambas
 * partes se conectan a la misma base de datos.
 * Si .env no existe, usa las variables del entorno del servidor o, en su
 * defecto, los valores por defecto de XAMPP.
 */

function cargarEnv(string $ruta): array
{
    $variables = [];
    if (!is_file($ruta)) {
        return $variables;
    }
    foreach (file($ruta, FILE_IGNORE_NEW_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || strpos($linea, '=') === false) {
            continue;
        }
        [$clave, $valor] = explode('=', $linea, 2);
        $clave = trim($clave);
        $valor = trim($valor);
        if (strlen($valor) >= 2 && ($valor[0] === '"' || $valor[0] === "'") && substr($valor, -1) === $valor[0]) {
            $valor = substr($valor, 1, -1);
        }
        if ($clave !== '') {
            $variables[$clave] = $valor;
        }
    }
    return $variables;
}

function valorEnv(array $env, string $clave, string $defecto): string
{
    $entorno = getenv($clave);
    if ($entorno !== false && $entorno !== '') {
        return $entorno;
    }
    if (isset($env[$clave]) && $env[$clave] !== '') {
        return $env[$clave];
    }
    return $defecto;
}

$env = cargarEnv(dirname(__DIR__) . '/.env');

$host = valorEnv($env, 'DB_HOST', '127.0.0.1');
$puerto = valorEnv($env, 'DB_PORT', '3306');
$baseDeDatos = valorEnv($env, 'DB_NAME', 'almuerzos_conscientes');
$usuario = valorEnv($env, 'DB_USER', 'root');
$contrasena = valorEnv($env, 'DB_PASSWORD', '');

$dsn = "mysql:host={$host};port={$puerto};dbname={$baseDeDatos};charset=utf8mb4";

$opciones = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

// Servidores MySQL en la nube (p. ej. TiDB Serverless) exigen TLS.
$sslCa = valorEnv($env, 'DB_SSL_CA', '');
if ($sslCa !== '' && !is_file($sslCa) && is_file(dirname(__DIR__) . '/' . $sslCa)) {
    $sslCa = dirname(__DIR__) . '/' . $sslCa;
}
if ($sslCa !== '' || in_array(strtolower(valorEnv($env, 'DB_SSL', '')), ['1', 'true', 'si', 'yes'], true)) {
    if ($sslCa !== '') {
        $opciones[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
    }
    $opciones[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
}

try {
    $pdo = new PDO($dsn, $usuario, $contrasena, $opciones);
} catch (PDOException $error) {
    if (defined('API_ACTIVA') && API_ACTIVA) {
        // El API JSON captura la excepción y responde con el código 503.
        throw $error;
    }
    http_response_code(500);
    exit('No fue posible conectar con MySQL. Verifique que el servidor esté activo y que las credenciales del archivo .env sean correctas.');
}
