<?php
// Router local para desarrollo: php -S 0.0.0.0:8080 router.php
// NO subir a InfinityFree (ahí Apache usa .htaccess y los HTML
// se suben a la raíz del sitio).
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (preg_match('#^/(api/|acceso-formulario/|estudiantes/guardar$)#', $uri)) {
    $_GET['_ruta'] = $uri;
    require __DIR__ . '/api.php';
    return true;
}

$archivo = __DIR__ . $uri;
if (is_file($archivo)) {
    return false; // el servidor de PHP sirve el archivo real
}

// En local las páginas viven en src/public; en InfinityFree, en la raíz.
$publico = __DIR__ . '/src/public' . $uri;
if (is_file($publico) && is_readable($publico)) {
    $ext = strtolower(pathinfo($publico, PATHINFO_EXTENSION));
    $tipos = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'txt' => 'text/plain; charset=utf-8',
    ];
    header('Content-Type: ' . ($tipos[$ext] ?? 'application/octet-stream'));
    readfile($publico);
    return true;
}

return false;
