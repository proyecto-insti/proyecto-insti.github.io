<?php
/**
 * Front controller JSON: todas las rutas /api/* y los formularios POST
 * del sitio llegan aquí (via .htaccess en InfinityFree, o router.php al
 * desarrollar con `php -S`).
 *
 * Mismas rutas y respuestas que app.py (Flask), de modo que el frontend
 * existente funciona sin cambios contra este backend PHP.
 */

define('API_ACTIVA', true);
date_default_timezone_set('America/Bogota');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('X-Content-Type-Options: nosniff');

require __DIR__ . '/lib/api_funciones.php';

// CORS del formulario público de desperdicio (paridad con app.py).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS'
    && strpos($_SERVER['REQUEST_URI'] ?? '', '/api/formulario-desperdicio-pae') !== false) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    http_response_code(204);
    exit;
}

try {
    require __DIR__ . '/config/db.php';
} catch (PDOException $error) {
    error_log('[conexion] ' . $error->getMessage());
    json_out(['ok' => false, 'error' => 'No fue posible conectar con MySQL. Revise el archivo .env y que la base haya sido importada.', 'modo_local_disponible' => true], 503);
}

if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/formulario-desperdicio-pae') !== false) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}

// Determina la ruta solicitada (con o sin rewrite de .htaccess).
$ruta = $_GET['_ruta'] ?? null;
if ($ruta === null) {
    $ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
}
$raizScript = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if ($raizScript !== '/' && $raizScript !== '.' && $ruta !== '/' && strpos($ruta, $raizScript) === 0) {
    $ruta = substr($ruta, strlen($raizScript));
}
$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$mapa = [
    '#^/api/login$#' => ['POST' => 'ep_login'],
    '#^/api/logout$#' => ['POST' => 'ep_logout'],
    '#^/api/sesion$#' => ['GET' => 'ep_sesion'],
    '#^/api/estado-bd$#' => ['GET' => 'ep_estado_bd'],
    '#^/api/estudiantes$#' => ['GET' => 'ep_estudiantes_listar', 'POST' => 'ep_estudiantes_consumo'],
    '#^/api/estudiantes/(\d+)$#' => ['PUT' => 'ep_estudiantes_editar'],
    '#^/api/registro-estudiantes$#' => ['POST' => 'ep_registro_crear'],
    '#^/api/registro-estudiantes/(\d+)$#' => ['DELETE' => 'ep_registro_eliminar'],
    '#^/api/control-estudiantes$#' => ['GET' => 'ep_control'],
    '#^/api/estado-estudiante/([0-9]+)$#' => ['GET' => 'ep_estado_estudiante'],
    '#^/api/consumos$#' => ['GET' => 'ep_consumos_listar', 'POST' => 'ep_consumos_crear'],
    '#^/api/historial-consumos$#' => ['GET' => 'ep_historial'],
    '#^/api/menu$#' => ['GET' => 'ep_menu_get', 'POST' => 'ep_menu_post'],
    '#^/api/residuos$#' => ['GET' => 'ep_residuos_get', 'POST' => 'ep_residuos_post'],
    '#^/api/pae-entrega$#' => ['GET' => 'ep_pae_entrega_get', 'POST' => 'ep_pae_entrega_post'],
    '#^/api/entrega-pae-grupos$#' => ['GET' => 'ep_entrega_grupos_get', 'POST' => 'ep_entrega_grupos_post'],
    '#^/api/componentes-pae$#' => ['GET' => 'ep_componentes_pae_get', 'POST' => 'ep_componentes_pae_post'],
    '#^/api/dashboard$#' => ['GET' => 'ep_dashboard'],
    '#^/api/reportes$#' => ['GET' => 'ep_reportes'],
    '#^/api/qr-acceso$#' => ['GET' => 'ep_qr_acceso'],
    '#^/api/formulario-desperdicio-pae$#' => ['POST' => 'ep_formulario_desperdicio_post'],
    '#^/api/formulario-desperdicio-pae/resumen$#' => ['GET' => 'ep_formulario_desperdicio_resumen'],
    '#^/acceso-formulario/([A-Za-z0-9_\-]+)$#' => ['GET' => 'ep_acceso_formulario'],
    '#^/estudiantes/guardar$#' => ['POST' => 'ep_guardar_estudiante_formulario'],
];

foreach ($mapa as $patron => $acciones) {
    if (!preg_match($patron, $ruta, $coincidencias)) {
        continue;
    }
    $funcion = $acciones[$metodo] ?? null;
    if ($funcion === null) {
        json_error('Método no permitido para esta ruta.', 405);
    }
    call_user_func_array($funcion, array_slice($coincidencias, 1));
    exit;
}

json_error('Ruta no encontrada: ' . $ruta, 404);
