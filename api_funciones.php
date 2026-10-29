<?php
/**
 * API JSON del proyecto Almuerzos Conscientes.
 * Port fiel de app.py (Flask) a PHP para poder desplegarse en InfinityFree,
 * cuya BD solo acepta conexiones desde el propio hosting.
 *
 * Los frontend (index.html, estudiantes.html, desperdicio_pae.html, ...)
 * consumen exactamente las mismas rutas y formas de respuesta que el backend
 * Flask, de modo que funcionan sin cambios contra este archivo.
 */

const TIPOS_CONSUMO = ['vaso_leche', 'almuerzo'];
const TIPOS_ALIMENTACION = ['PAE', 'almuerzo'];
const GRADOS_ALMUERZO = ['5-1', '5-2', '5-3', '10-1', '10-2', '10-3', '11-1', '11-2', '11-3'];
const GRADOS_PAE = ['6-1', '6-2', '6-3', '7-1', '7-2', '7-3', '8-1', '8-2', '8-3', '9-1', '9-2', '9-3'];

/** Grados que reciben entrega del PAE (sexto 1 a once 3). */
function grados_entrega_pae()
{
    $grados = [];
    for ($g = 6; $g <= 11; $g++) {
        for ($s = 1; $s <= 3; $s++) {
            $grados[] = $g . '-' . $s;
        }
    }
    return $grados;
}

function grados_permitidos()
{
    return array_merge(GRADOS_ALMUERZO, GRADOS_PAE);
}

function grados_por_alimentacion($tipo)
{
    return strtolower((string) $tipo) === 'almuerzo' ? GRADOS_ALMUERZO : GRADOS_PAE;
}

function validar_grado_alimentacion($grado, $tipo)
{
    return in_array((string) $grado, grados_por_alimentacion($tipo), true);
}

// ---------------------------------------------------------------------------
// Respuestas JSON
// ---------------------------------------------------------------------------

function json_out($datos, $codigo = 200)
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error($mensaje, $codigo, $extra = [])
{
    json_out(array_merge(['ok' => false, 'error' => $mensaje], $extra), $codigo);
}

function error_db($mensaje = 'No fue posible conectar con MySQL.')
{
    json_out(['ok' => false, 'error' => $mensaje, 'modo_local_disponible' => true], 503);
}

function cuerpo_json()
{
    static $cache = null;
    static $leido = false;
    if (!$leido) {
        $crudo = file_get_contents('php://input');
        $decodificado = json_decode($crudo, true);
        $cache = is_array($decodificado) ? $decodificado : [];
        $leido = true;
    }
    return $cache;
}

function datos_peticion()
{
    $json = cuerpo_json();
    if ($json) {
        return $json;
    }
    return $_POST;
}

/** Codifica como objeto JSON incluso cuando el arreglo está vacío. */
function como_objeto($valor)
{
    if (is_array($valor) && array_keys($valor) === range(0, count($valor) - 1) && count($valor) === 0) {
        return new stdClass();
    }
    return $valor;
}

// ---------------------------------------------------------------------------
// Sesiones y autenticación (mismas reglas que app.py)
// ---------------------------------------------------------------------------

function usuario_actual()
{
    return $_SESSION['usuario'] ?? null;
}

function exigir_sesion()
{
    if (!usuario_actual()) {
        json_out(['ok' => false, 'error' => 'Debe iniciar sesión.', 'autenticado' => false], 401);
    }
}

function verificar_contrasena($texto, $almacenado)
{
    // Formato werkzeug: pbkdf2:sha256:1000000$<salt>$<hash_hex>
    $partes = explode('$', (string) $almacenado, 3);
    if (count($partes) !== 3) {
        return false;
    }
    [$metodoIteraciones, $sal, $hashHex] = $partes;
    $sub = explode(':', $metodoIteraciones);
    if (count($sub) !== 3 || $sub[0] !== 'pbkdf2') {
        return false;
    }
    $iteraciones = (int) $sub[2];
    $largo = (int) (strlen($hashHex) / 2);
    if ($largo < 1 || strlen($hashHex) % 2 !== 0) {
        return false;
    }
    $derivado = hash_pbkdf2($sub[1], (string) $texto, $sal, $iteraciones, $largo, true);
    return hash_equals(hex2bin($hashHex), $derivado);
}

// ---------------------------------------------------------------------------
// Utilidades de datos
// ---------------------------------------------------------------------------

function fecha_solicitada($valor = null)
{
    if ($valor) {
        $fecha = DateTime::createFromFormat('Y-m-d', (string) $valor);
        if ($fecha && $fecha->format('Y-m-d') === (string) $valor) {
            return $valor;
        }
    }
    return date('Y-m-d');
}

function consultar($sql, $params = [], $uno = false)
{
    global $pdo;
    $sentencia = $pdo->prepare($sql);
    $sentencia->execute($params);
    $filas = $sentencia->fetchAll(PDO::FETCH_ASSOC);
    if ($uno) {
        return $filas[0] ?? null;
    }
    return $filas;
}

function ejecutar($sql, $params = [])
{
    global $pdo;
    $sentencia = $pdo->prepare($sql);
    $sentencia->execute($params);
    return (int) $pdo->lastInsertId();
}

function es_error_integridad(PDOException $error)
{
    return $error->getCode() === '23000' || ($error->errorInfo[0] ?? '') === '23000';
}

function validar_estudiante($nombre, $documento, $edad, $grado, $tipoAlimentacion = 'PAE')
{
    $tipo = trim((string) ($tipoAlimentacion ?: 'PAE'));
    if (!in_array($tipo, TIPOS_ALIMENTACION, true)) {
        $tipo = '';
    }
    $nombreLimpio = trim(preg_replace('/\s+/', ' ', (string) $nombre));
    $documentoLimpio = trim((string) $documento);
    $edadEntero = (int) trim((string) $edad);
    $valido = $nombreLimpio !== ''
        && preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+$/u', $nombreLimpio)
        && preg_match('/^\d+$/', $documentoLimpio)
        && $edadEntero >= 3 && $edadEntero <= 20
        && in_array((string) $grado, grados_permitidos(), true)
        && in_array($tipo, TIPOS_ALIMENTACION, true);
    return [$valido ? true : false, $nombreLimpio, $documentoLimpio, $edadEntero, $tipo];
}

function crear_codigo_qr()
{
    return 'AC-' . strtoupper(bin2hex(random_bytes(10)));
}

/**
 * Archiva consumos de más de 24 horas y cierra el padrón del día anterior
 * como "no consumió" (misma lógica que archivar_consumos_vencidos en app.py).
 */
function archivar_consumos_vencidos()
{
    global $pdo;
    try {
        $pdo->beginTransaction();
        $pdo->exec(
            "INSERT INTO historial_consumo
               (asistencia_id, estudiante_id, nombre_estudiante, documento, grado,
                fecha_consumo, hora_consumo, tipo_consumo, consumido_en, metodo_registro)
             SELECT a.id, e.id, e.nombre, e.documento, e.grado,
                    a.fecha, a.hora, a.tipo_consumo, a.consumido_en, a.metodo_registro
             FROM asistencia a JOIN estudiantes e ON e.id=a.estudiante_id
             WHERE a.consumido_en < (NOW() - INTERVAL 24 HOUR)
               AND NOT EXISTS (SELECT 1 FROM historial_consumo h WHERE h.asistencia_id=a.id)"
        );
        $pdo->exec(
            "INSERT INTO historial_consumo
               (asistencia_id, estudiante_id, nombre_estudiante, documento, grado,
                fecha_consumo, hora_consumo, tipo_consumo, consumido_en, metodo_registro)
             SELECT NULL, e.id, e.nombre, e.documento, e.grado,
                    DATE_SUB(CURDATE(), INTERVAL 1 DAY), '23:59:59', 'ninguno',
                    TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '23:59:59'), 'formulario'
             FROM estudiantes e
             WHERE NOT EXISTS (SELECT 1 FROM asistencia a WHERE a.estudiante_id=e.id AND a.fecha=DATE_SUB(CURDATE(), INTERVAL 1 DAY))
               AND NOT EXISTS (SELECT 1 FROM historial_consumo h WHERE h.estudiante_id=e.id AND h.fecha_consumo=DATE_SUB(CURDATE(), INTERVAL 1 DAY))"
        );
        $pdo->exec("DELETE FROM asistencia WHERE consumido_en < (NOW() - INTERVAL 24 HOUR)");
        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function token_formulario_qr()
{
    $acceso = consultar(
        'SELECT token FROM accesos_qr WHERE nombre=? AND activo=1',
        ['formulario_desperdicio_pae'],
        true
    );
    if ($acceso) {
        return $acceso['token'];
    }
    $token = bin2hex(random_bytes(16));
    ejecutar(
        'INSERT INTO accesos_qr (nombre, token, activo) VALUES (?, ?, 1)',
        ['formulario_desperdicio_pae', $token]
    );
    return $token;
}

function url_formulario_qr()
{
    $token = token_formulario_qr();
    $base = trim((string) (getenv('PUBLIC_BASE_URL') ?: ''));
    if ($base !== '') {
        return rtrim($base, '/') . '/acceso-formulario/' . $token;
    }
    $esquema = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https' : 'http';
    $raiz = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $raiz = $raiz === '/' || $raiz === '.' ? '' : rtrim($raiz, '/');
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    return $esquema . '://' . $host . $raiz . '/acceso-formulario/' . $token;
}

// ---------------------------------------------------------------------------
// Endpoints públicos
// ---------------------------------------------------------------------------

function ep_login()
{
    $datos = datos_peticion();
    $usuario = strtolower(trim((string) ($datos['usuario'] ?? '')));
    $contrasena = (string) ($datos['contrasena'] ?? '');
    if ($usuario === '' || $contrasena === '') {
        json_error('Ingrese usuario y contraseña.', 400);
    }
    try {
        $cuenta = consultar(
            'SELECT id, usuario, nombre, rol, contrasena_hash FROM usuarios WHERE usuario=? AND activo=1',
            [$usuario],
            true
        );
        if (!$cuenta || !verificar_contrasena($contrasena, $cuenta['contrasena_hash'])) {
            json_error('Usuario o contraseña incorrectos.', 401);
        }
        session_regenerate_id(true);
        $_SESSION['usuario'] = [
            'id' => (int) $cuenta['id'],
            'usuario' => $cuenta['usuario'],
            'nombre' => $cuenta['nombre'],
            'rol' => $cuenta['rol'],
        ];
        ejecutar('UPDATE usuarios SET ultimo_acceso=CURRENT_TIMESTAMP WHERE id=?', [$cuenta['id']]);
        json_out(['ok' => true, 'usuario' => $_SESSION['usuario'], 'redirect' => '/index.html']);
    } catch (PDOException $error) {
        error_log('[login] ' . $error->getMessage());
        error_db('No fue posible validar el usuario en MySQL.');
    }
}

function ep_logout()
{
    session_destroy();
    json_out(['ok' => true, 'redirect' => '/index1.html']);
}

function ep_sesion()
{
    json_out(['ok' => true, 'autenticado' => (bool) usuario_actual(), 'usuario' => usuario_actual()]);
}

function ep_estado_bd()
{
    global $host, $puerto, $usuario, $baseDeDatos, $pdo;
    $config = [
        'host' => $host,
        'puerto' => $puerto,
        'usuario' => $usuario,
        'base_de_datos' => $baseDeDatos,
    ];
    try {
        $pdo->query('SELECT 1');
        json_out(['ok' => true, 'conectado' => true, 'config' => $config]);
    } catch (PDOException $error) {
        json_out([
            'ok' => false,
            'conectado' => false,
            'config' => $config,
            'error' => $error->getMessage(),
            'sugerencia' => 'Revise el archivo .env (DB_HOST, DB_USER, DB_PASSWORD, DB_NAME) y que la base '
                . 'haya sido importada con database/crear_tablas_phpmyadmin.sql en phpMyAdmin.',
        ], 503);
    }
}

function ep_estudiantes_listar()
{
    try {
        archivar_consumos_vencidos();
        $filas = consultar(
            "SELECT e.id, e.nombre, e.documento, e.grado, e.fecha_registro,
                    COALESCE(a.tipo_consumo, 'ninguno') AS tipo_consumo,
                    a.fecha AS fecha_consumo, a.hora AS hora_consumo
             FROM estudiantes e LEFT JOIN asistencia a
               ON a.estudiante_id=e.id AND a.fecha=CURDATE()
             ORDER BY e.nombre"
        );
        json_out(['ok' => true, 'data' => $filas]);
    } catch (PDOException $error) {
        error_log('[estudiantes] ' . $error->getMessage());
        error_db();
    }
}

function ep_estudiantes_consumo()
{
    $datos = datos_peticion();
    $nombre = trim(preg_replace('/\s+/', ' ', (string) ($datos['nombre'] ?? '')));
    $documento = trim((string) ($datos['documento'] ?? ''));
    $tipoConsumo = trim((string) ($datos['tipo_consumo'] ?? ''));
    if (($documento !== '' && !preg_match('/^\d+$/', $documento))
        || ($documento === '' && ($nombre === '' || !preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+$/u', $nombre)))
        || !in_array($tipoConsumo, TIPOS_CONSUMO, true)) {
        json_error('Ingrese un nombre válido y seleccione Vaso de leche o Almuerzo.', 400);
    }
    try {
        archivar_consumos_vencidos();
        // Nunca se crea un estudiante desde este formulario: solo consumo.
        $existente = consultar(
            'SELECT id, nombre, documento, grado, codigo_qr FROM estudiantes
             WHERE documento=? OR LOWER(TRIM(nombre))=LOWER(TRIM(?))',
            [$documento, $nombre],
            true
        );
        if (!$existente) {
            json_error('Este nombre no existe en Registro de estudiantes. No se realizó ningún registro.', 404);
        }
        $fecha = date('Y-m-d');
        $hora = date('H:i:s');
        $previo = consultar(
            'SELECT id FROM asistencia WHERE estudiante_id=? AND fecha=?',
            [$existente['id'], $fecha],
            true
        );
        if ($previo) {
            ejecutar(
                'UPDATE asistencia SET tipo_consumo=?, hora=?, consumido_en=?, metodo_registro=? WHERE id=?',
                [$tipoConsumo, $hora, date('Y-m-d H:i:s'), 'formulario', $previo['id']]
            );
        } else {
            ejecutar(
                'INSERT INTO asistencia (estudiante_id, fecha, hora, tipo_consumo, consumido_en, metodo_registro)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$existente['id'], $fecha, $hora, $tipoConsumo, date('Y-m-d H:i:s'), 'formulario']
            );
        }
        json_out([
            'ok' => true,
            'mensaje' => $existente['nombre'] . ' se registró exitosamente.',
            'data' => array_merge($existente, [
                'tipo_consumo' => $tipoConsumo,
                'fecha' => $fecha,
                'hora' => $hora,
            ]),
        ]);
    } catch (PDOException $error) {
        error_log('[consumo-publico] ' . $error->getMessage());
        error_db('No fue posible conectar con el Registro de estudiantes en MySQL.');
    }
}

function ep_acceso_formulario($token)
{
    try {
        $acceso = consultar(
            'SELECT id FROM accesos_qr WHERE token=? AND nombre=? AND activo=1',
            [$token, 'formulario_desperdicio_pae'],
            true
        );
        if (!$acceso) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Este código QR ya no está activo.';
            exit;
        }
        ejecutar('UPDATE accesos_qr SET ultimo_uso=CURRENT_TIMESTAMP WHERE id=?', [$acceso['id']]);
        $raiz = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $raiz = $raiz === '/' || $raiz === '.' ? '' : rtrim($raiz, '/');
        header('Location: ' . $raiz . '/desperdicio_pae.html', true, 302);
        exit;
    } catch (PDOException $error) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No fue posible validar el código QR en la base de datos.';
        exit;
    }
}

function ep_formulario_desperdicio_post()
{
    $datos = datos_peticion();
    $grado = trim((string) ($datos['grado'] ?? ''));
    $peso = (float) ($datos['peso_sobrante_gramos'] ?? 0);
    $tipo = 'PAE';
    if (!in_array($grado, grados_permitidos(), true)) {
        json_error('Seleccione un grado válido.', 400);
    }
    if (is_nan($peso) || $peso <= 0) {
        json_error('El gramaje debe ser mayor que cero.', 400);
    }
    try {
        $fecha = fecha_solicitada($datos['fecha'] ?? null);
        $nota = trim((string) ($datos['nota'] ?? ''));
        $uid = substr(trim((string) ($datos['uid'] ?? '')), 0, 40);
        $uid = $uid === '' ? null : $uid;
        if ($uid && consultar('SELECT id FROM residuos WHERE uid_envio=?', [$uid], true)) {
            json_out(['ok' => true, 'duplicado' => true, 'data' => [
                'fecha' => $fecha, 'grado' => $grado, 'peso' => $peso, 'tipo' => $tipo,
            ]]);
        }
        ejecutar(
            'INSERT INTO residuos (fecha, peso_sobrante_gramos, grupo_tipo, tipo_alimentacion, grado, nota, uid_envio)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$fecha, $peso, 'individual', $tipo, $grado, $nota !== '' ? $nota : null, $uid]
        );
        json_out(['ok' => true, 'data' => ['fecha' => $fecha, 'grado' => $grado, 'peso' => $peso, 'tipo' => $tipo]], 201);
    } catch (PDOException $error) {
        error_log('[desperdicio-qr] ' . $error->getMessage());
        error_db('No fue posible guardar el desperdicio PAE en MySQL.');
    }
}

function ep_formulario_desperdicio_resumen()
{
    try {
        $fecha = fecha_solicitada($_GET['fecha'] ?? null);
        $filas = consultar(
            'SELECT grado, tipo_alimentacion, SUM(peso_sobrante_gramos) AS peso, GROUP_CONCAT(NULLIF(nota,"") SEPARATOR " · ") AS notas
             FROM residuos WHERE fecha=? AND tipo_alimentacion="PAE" AND grado IS NOT NULL
             GROUP BY grado, tipo_alimentacion',
            [$fecha]
        );
        $data = [];
        foreach ($filas as $fila) {
            $data[] = [
                'grado' => $fila['grado'],
                'tipo' => $fila['tipo_alimentacion'],
                'peso' => (float) ($fila['peso'] ?? 0),
                'notas' => $fila['notas'] ?: '',
            ];
        }
        json_out(['ok' => true, 'data' => $data]);
    } catch (PDOException $error) {
        error_db();
    }
}

function ep_guardar_estudiante_formulario()
{
    $documento = trim((string) ($_POST['documento'] ?? ''));
    $tipoConsumo = trim((string) ($_POST['tipo_consumo'] ?? ''));
    $consulta = '?exito=1';
    if (!preg_match('/^\d+$/', $documento) || !in_array($tipoConsumo, TIPOS_CONSUMO, true)) {
        $consulta = '?error=' . rawurlencode('Documento o servicio inválido.');
    } else {
        try {
            archivar_consumos_vencidos();
            $existente = consultar(
                'SELECT id, nombre, documento, grado FROM estudiantes WHERE documento=?',
                [$documento],
                true
            );
            if (!$existente) {
                $consulta = '?error=' . rawurlencode('Ese número de documento no está registrado.');
            } else {
                $fecha = date('Y-m-d');
                $hora = date('H:i:s');
                $previo = consultar(
                    'SELECT id FROM asistencia WHERE estudiante_id=? AND fecha=?',
                    [$existente['id'], $fecha],
                    true
                );
                if ($previo) {
                    ejecutar(
                        'UPDATE asistencia SET tipo_consumo=?, hora=?, consumido_en=?, metodo_registro=? WHERE id=?',
                        [$tipoConsumo, $hora, date('Y-m-d H:i:s'), 'formulario', $previo['id']]
                    );
                } else {
                    ejecutar(
                        'INSERT INTO asistencia (estudiante_id, fecha, hora, tipo_consumo, consumido_en, metodo_registro)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        [$existente['id'], $fecha, $hora, $tipoConsumo, date('Y-m-d H:i:s'), 'formulario']
                    );
                }
            }
        } catch (PDOException $error) {
            error_log('[guardar-formulario] ' . $error->getMessage());
            $consulta = '?error=' . rawurlencode('No fue posible guardar el registro en MySQL.');
        }
    }
    $raiz = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $raiz = $raiz === '/' || $raiz === '.' ? '' : rtrim($raiz, '/');
    header('Location: ' . $raiz . '/estudiantes.html' . $consulta, true, 302);
    exit;
}

function ep_qr_acceso()
{
    exigir_sesion();
    try {
        json_out(['ok' => true, 'url' => url_formulario_qr()]);
    } catch (PDOException $error) {
        error_db('Conecte MySQL para generar el acceso QR.');
    }
}

// ---------------------------------------------------------------------------
// Endpoints protegidos (requieren sesión)
// ---------------------------------------------------------------------------

function ep_registro_crear()
{
    exigir_sesion();
    $datos = datos_peticion();
    $nombre = trim(preg_replace('/\s+/', ' ', (string) ($datos['nombre'] ?? '')));
    $documento = trim((string) ($datos['documento'] ?? ''));
    $grado = trim((string) ($datos['grado'] ?? ''));
    $tipo = trim((string) ($datos['tipo_alimentacion'] ?? 'PAE'));
    if ($nombre === ''
        || !preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+$/u', $nombre)
        || !preg_match('/^\d+$/', $documento)
        || !validar_grado_alimentacion($grado, $tipo)) {
        json_error('Ingrese nombre solo con letras, documento solo con números y un grado compatible con el servicio seleccionado.', 400);
    }
    try {
        $existente = consultar('SELECT id FROM estudiantes WHERE documento=?', [$documento], true);
        if ($existente) {
            json_error('El documento ya está en Registro de estudiantes.', 409);
        }
        $nuevoId = ejecutar(
            'INSERT INTO estudiantes (nombre, documento, edad, grado, tipo_alimentacion, codigo_qr)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$nombre, $documento, 10, $grado, $tipo, crear_codigo_qr()]
        );
        json_out(['ok' => true, 'data' => [
            'id' => $nuevoId,
            'nombre' => $nombre,
            'documento' => $documento,
            'grado' => $grado,
            'tipo_alimentacion' => $tipo,
            'tipo_consumo' => 'ninguno',
        ]], 201);
    } catch (PDOException $error) {
        if (es_error_integridad($error)) {
            json_error('El documento ya está registrado.', 409);
        }
        error_log('[registro] ' . $error->getMessage());
        error_db('No fue posible guardar el estudiante en MySQL.');
    }
}

function ep_registro_eliminar($id)
{
    exigir_sesion();
    try {
        ejecutar('DELETE FROM estudiantes WHERE id=?', [(int) $id]);
        json_out(['ok' => true]);
    } catch (PDOException $error) {
        if (es_error_integridad($error)) {
            json_error('No se puede borrar: el estudiante tiene registros de consumo asociados.', 409);
        }
        error_db('No fue posible borrar el estudiante en la base de datos.');
    }
}

function ep_estudiantes_editar($id)
{
    exigir_sesion();
    $datos = datos_peticion();
    $nombre = trim((string) ($datos['nombre'] ?? ''));
    $documento = trim((string) ($datos['documento'] ?? ''));
    $edad = (int) (($datos['edad'] ?? 10) ?: 10);
    $grado = trim((string) ($datos['grado'] ?? ''));
    $tipo = trim((string) ($datos['tipo_alimentacion'] ?? 'PAE'));
    [$valido, $nombre, $documento, $edad, $tipo] = validar_estudiante($nombre, $documento, $edad, $grado, $tipo);
    if (!$valido) {
        json_error('Nombre solo con letras, documento numérico, edad entre 3 y 20 y un grado válido (5º, 7º, 8º, 9º, 10º u 11º, carpeta 1, 2 o 3).', 400);
    }
    try {
        $duplicado = consultar(
            'SELECT id FROM estudiantes WHERE (documento=? OR LOWER(TRIM(nombre))=LOWER(TRIM(?))) AND id<>?',
            [$documento, $nombre, (int) $id],
            true
        );
        if ($duplicado) {
            json_error('El documento o el nombre ya pertenece a otro estudiante.', 409);
        }
        ejecutar(
            'UPDATE estudiantes SET nombre=?, documento=?, edad=?, grado=?, tipo_alimentacion=? WHERE id=?',
            [$nombre, $documento, $edad, $grado, $tipo, (int) $id]
        );
        json_out(['ok' => true, 'data' => [
            'id' => (int) $id, 'nombre' => $nombre, 'documento' => $documento, 'grado' => $grado,
        ]]);
    } catch (PDOException $error) {
        if (es_error_integridad($error)) {
            json_error('El documento ya está registrado.', 409);
        }
        error_db('No fue posible actualizar el estudiante en MySQL.');
    }
}

function ep_control()
{
    exigir_sesion();
    $fecha = fecha_solicitada($_GET['fecha'] ?? null);
    try {
        archivar_consumos_vencidos();
        $filas = consultar(
            'SELECT e.id, e.nombre, e.documento, e.grado, DATE(?) AS fecha,
                    COALESCE(a.tipo_consumo, "ninguno") AS tipo_consumo,
                    a.hora AS hora,
                    CASE WHEN a.estudiante_id IS NULL OR a.tipo_consumo="ninguno" THEN "no_consumio" ELSE "consumio" END AS estado
             FROM estudiantes e
             LEFT JOIN (
                 SELECT x.estudiante_id, x.fecha, x.tipo_consumo, x.hora
                 FROM asistencia x
                 INNER JOIN (
                     SELECT estudiante_id, fecha, MAX(id) AS max_id
                     FROM asistencia WHERE fecha=? GROUP BY estudiante_id, fecha
                 ) ult ON ult.max_id=x.id
                 UNION ALL
                 SELECT h.estudiante_id, h.fecha_consumo AS fecha, h.tipo_consumo, h.hora_consumo AS hora
                 FROM historial_consumo h
                 WHERE h.fecha_consumo=?
                   AND NOT EXISTS (
                       SELECT 1 FROM asistencia a2
                       WHERE a2.estudiante_id=h.estudiante_id AND a2.fecha=h.fecha_consumo
                   )
             ) a ON a.estudiante_id=e.id AND a.fecha=?
             ORDER BY e.nombre',
            [$fecha, $fecha, $fecha, $fecha]
        );
        json_out(['ok' => true, 'data' => $filas]);
    } catch (PDOException $error) {
        error_log('[control] ' . $error->getMessage());
        error_db('No fue posible consultar el control de estudiantes.');
    }
}

function ep_estado_estudiante($documento)
{
    exigir_sesion();
    $documento = trim((string) $documento);
    if (!preg_match('/^\d+$/', $documento)) {
        json_error('Documento inválido.', 400);
    }
    $fecha = fecha_solicitada($_GET['fecha'] ?? null);
    try {
        archivar_consumos_vencidos();
        $estudiante = consultar(
            'SELECT id, nombre, documento, grado FROM estudiantes WHERE documento=?',
            [$documento],
            true
        );
        if (!$estudiante) {
            json_error('Estudiante no encontrado.', 404);
        }
        $registro = consultar(
            'SELECT tipo_consumo, hora FROM asistencia WHERE estudiante_id=? AND fecha=? ORDER BY id DESC LIMIT 1',
            [$estudiante['id'], $fecha],
            true
        );
        $tipo = $registro['tipo_consumo'] ?? 'ninguno';
        json_out(['ok' => true, 'data' => array_merge($estudiante, [
            'fecha' => $fecha,
            'tipo_consumo' => $tipo,
            'hora' => $registro['hora'] ?? null,
            'estado' => ($registro && $tipo !== 'ninguno') ? 'consumio' : 'no_consumio',
        ])]);
    } catch (PDOException $error) {
        error_db('No fue posible consultar el estado del estudiante.');
    }
}

function ep_consumos_listar()
{
    exigir_sesion();
    $fecha = fecha_solicitada($_GET['fecha'] ?? null);
    try {
        archivar_consumos_vencidos();
        $filas = consultar(
            'SELECT a.fecha, a.hora, a.metodo_registro, e.nombre, e.documento,
                    e.grado, e.tipo_alimentacion, e.codigo_qr, a.tipo_consumo
             FROM asistencia a JOIN estudiantes e ON e.id=a.estudiante_id
             WHERE a.fecha=? ORDER BY a.hora',
            [$fecha]
        );
        json_out(['ok' => true, 'data' => $filas]);
    } catch (PDOException $error) {
        error_db();
    }
}

function ep_consumos_crear()
{
    exigir_sesion();
    $datos = datos_peticion();
    $codigo = trim((string) ($datos['codigo'] ?? $datos['documento'] ?? ''));
    $fecha = fecha_solicitada($datos['fecha'] ?? null);
    if ($codigo === '') {
        json_error('Ingrese el documento o escanee el código QR.', 400);
    }
    $diaSemana = (int) date('w', strtotime($fecha));
    if ($diaSemana === 0 || $diaSemana === 6) {
        json_error('El registro de consumo está disponible de lunes a viernes.', 400);
    }
    try {
        archivar_consumos_vencidos();
        $menu = consultar('SELECT * FROM menu_diario WHERE fecha=?', [$fecha], true);
        if (!$menu) {
            json_error('No hay un menú configurado para esta fecha.', 409);
        }
        if (!(int) $menu['registro_habilitado']) {
            json_error('El registro de consumo está cerrado por el docente.', 403);
        }
        $horaActual = date('H:i');
        $inicio = substr($menu['ventana_inicio'], 0, 5);
        $fin = substr($menu['ventana_fin'], 0, 5);
        if (!($inicio <= $horaActual && $horaActual <= $fin)) {
            json_error('El registro solo está habilitado entre ' . $inicio . ' y ' . $fin . '.', 403);
        }
        $estudiante = consultar(
            'SELECT id, nombre, documento, grado, tipo_alimentacion, codigo_qr FROM estudiantes
             WHERE documento=? OR codigo_qr=?',
            [$codigo, $codigo],
            true
        );
        if (!$estudiante) {
            json_error('No encontramos un estudiante con ese documento o código QR.', 404);
        }
        $previo = consultar(
            'SELECT id FROM asistencia WHERE estudiante_id=? AND fecha=?',
            [$estudiante['id'], $fecha],
            true
        );
        if ($previo) {
            json_error($estudiante['nombre'] . ' ya confirmó su consumo hoy.', 409);
        }
        ejecutar(
            'INSERT INTO asistencia (estudiante_id, fecha, hora, tipo_consumo, consumido_en, metodo_registro)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$estudiante['id'], $fecha, date('H:i:s'), (string) ($datos['tipo_consumo'] ?? 'almuerzo'),
             date('Y-m-d H:i:s'), (string) ($datos['metodo'] ?? 'documento')]
        );
        json_out(['ok' => true, 'mensaje' => 'Consumo confirmado para ' . $estudiante['nombre'] . '.', 'data' => $estudiante]);
    } catch (PDOException $error) {
        error_db('No fue posible registrar el consumo en MySQL.');
    }
}

function ep_historial()
{
    exigir_sesion();
    try {
        archivar_consumos_vencidos();
        $desde = $_GET['desde'] ?? null;
        $filtro = '';
        $params = [];
        if ($desde) {
            $filtro = ' WHERE fecha_consumo >= ?';
            $params[] = fecha_solicitada($desde);
        }
        $filas = consultar(
            'SELECT id, nombre_estudiante, documento, grado, fecha_consumo,
                    hora_consumo, tipo_consumo, consumido_en, archivado_en, metodo_registro
             FROM historial_consumo' . $filtro . ' ORDER BY consumido_en DESC LIMIT 1000',
            $params
        );
        json_out(['ok' => true, 'data' => $filas]);
    } catch (PDOException $error) {
        error_db();
    }
}

function ep_menu_get()
{
    exigir_sesion();
    $fecha = fecha_solicitada($_GET['fecha'] ?? null);
    try {
        $menu = consultar(
            'SELECT id, fecha, nombre_menu, tipo_racion, gramaje_total, gramajes_grupo,
                    ventana_inicio, ventana_fin, registro_habilitado, notas
             FROM menu_diario WHERE fecha=?',
            [$fecha],
            true
        );
        if (!$menu) {
            json_out(['ok' => true, 'data' => null]);
        }
        if (is_string($menu['gramajes_grupo']) && $menu['gramajes_grupo'] !== '') {
            $decodificado = json_decode($menu['gramajes_grupo'], true);
            $menu['gramajes_grupo'] = is_array($decodificado) ? como_objeto($decodificado) : null;
        }
        $menu['componentes'] = consultar(
            'SELECT componente, alimento, gramos, unidad, grado, es_opcional FROM menu_componentes WHERE menu_id=? ORDER BY id',
            [$menu['id']]
        );
        json_out(['ok' => true, 'data' => $menu]);
    } catch (PDOException $error) {
        error_db();
    }
}

function ep_menu_post()
{
    exigir_sesion();
    $datos = datos_peticion();
    $fecha = fecha_solicitada($datos['fecha'] ?? null);
    $nombreMenu = trim((string) ($datos['nombre_menu'] ?? ''));
    $tipoRacion = trim((string) ($datos['tipo_racion'] ?? 'caliente'));
    $gramaje = (float) ($datos['gramaje_total'] ?? 0);
    $inicio = substr((string) ($datos['ventana_inicio'] ?? '09:30'), 0, 5);
    $fin = substr((string) ($datos['ventana_fin'] ?? '13:00'), 0, 5);
    $habilitado = ($datos['registro_habilitado'] ?? true) ? 1 : 0;
    $diaSemana = (int) date('w', strtotime($fecha));
    if (($diaSemana === 0 || $diaSemana === 6) || $nombreMenu === '' || is_nan($gramaje) || $gramaje <= 0) {
        json_error('El menú debe corresponder a un día hábil (lunes a viernes), con nombre y gramaje válido.', 400);
    }
    $reemplazar = array_key_exists('componentes', $datos);
    $componentes = $datos['componentes'] ?? [];
    global $pdo;
    try {
        $pdo->beginTransaction();
        $gramajesGrupo = json_encode(
            como_objeto($datos['gramajes_grupo'] ?? []),
            JSON_UNESCAPED_UNICODE
        );
        $sentencia = $pdo->prepare(
            'INSERT INTO menu_diario
               (fecha, nombre_menu, tipo_racion, gramaje_total, gramajes_grupo, ventana_inicio, ventana_fin, registro_habilitado, notas)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE nombre_menu=VALUES(nombre_menu), tipo_racion=VALUES(tipo_racion),
               gramaje_total=VALUES(gramaje_total), gramajes_grupo=VALUES(gramajes_grupo),
               ventana_inicio=VALUES(ventana_inicio), ventana_fin=VALUES(ventana_fin),
               registro_habilitado=VALUES(registro_habilitado), notas=VALUES(notas)'
        );
        $sentencia->execute([
            $fecha, $nombreMenu, $tipoRacion, $gramaje, $gramajesGrupo,
            $inicio, $fin, $habilitado, trim((string) ($datos['notas'] ?? '')),
        ]);
        $filaMenu = consultar('SELECT id FROM menu_diario WHERE fecha=?', [$fecha], true);
        $menuId = (int) $filaMenu['id'];
        if ($reemplazar) {
            $pdo->prepare('DELETE FROM menu_componentes WHERE menu_id=?')->execute([$menuId]);
        }
        $insertar = $pdo->prepare(
            'INSERT INTO menu_componentes (menu_id, componente, alimento, gramos, unidad, grado, es_opcional)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $filas = 0;
        foreach ((array) $componentes as $componente) {
            $alimento = trim((string) ($componente['alimento'] ?? ''));
            $categoria = trim((string) ($componente['componente'] ?? '')) ?: 'otros';
            $gramos = (float) ($componente['gramos'] ?? 0);
            $unidad = (strtoupper((string) ($componente['unidad'] ?? '')) === 'L' || strtolower($categoria) === 'bebida') ? 'L' : 'g';
            $grado = trim((string) ($componente['grado'] ?? ''));
            $grado = $grado === '' ? null : $grado;
            if ($grado !== null && !in_array($grado, GRADOS_ALMUERZO, true)) {
                $pdo->rollBack();
                json_error('Las proteínas y componentes del menú solo pueden asociarse a grados de almuerzo (quinto, décimo y once).', 400);
            }
            if ($alimento !== '' && $gramos >= 0) {
                $insertar->execute([$menuId, $categoria, $alimento, $gramos, $unidad, $grado, !empty($componente['es_opcional']) ? 1 : 0]);
                $filas++;
            }
        }
        $pdo->commit();
        json_out(['ok' => true, 'mensaje' => 'Menú guardado correctamente.', 'id' => $menuId]);
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[menu] ' . $error->getMessage());
        error_db('No fue posible guardar el menú. Verifique la base de datos y los gramajes.');
    }
}

function ep_residuos_get()
{
    exigir_sesion();
    $fecha = fecha_solicitada($_GET['fecha'] ?? null);
    try {
        $filas = consultar(
            'SELECT fecha, peso_sobrante_gramos AS peso, grupo_tipo AS grupo, tipo_alimentacion, grado, nota
             FROM residuos WHERE fecha=? ORDER BY id DESC',
            [$fecha]
        );
        json_out(['ok' => true, 'data' => $filas]);
    } catch (PDOException $error) {
        error_db();
    }
}

function ep_residuos_post()
{
    exigir_sesion();
    $datos = datos_peticion();
    try {
        $fecha = fecha_solicitada($datos['fecha'] ?? null);
        $peso = (float) ($datos['peso_sobrante_gramos'] ?? 0);
        $tipo = trim((string) ($datos['tipo_alimentacion'] ?? 'PAE')) ?: 'PAE';
        $grado = trim((string) ($datos['grado'] ?? ''));
        $grado = $grado === '' ? null : $grado;
        if (!in_array($tipo, ['PAE', 'almuerzo'], true)) {
            json_error('Seleccione si el desperdicio corresponde al PAE o al almuerzo.', 400);
        }
        if ($grado !== null && !in_array($grado, grados_permitidos(), true)) {
            json_error('El grado no corresponde al tipo de alimentación seleccionado.', 400);
        }
        if (is_nan($peso) || $peso < 0) {
            json_error('Ingrese un peso de desperdicio válido.', 400);
        }
        ejecutar(
            'INSERT INTO residuos (fecha, peso_sobrante_gramos, grupo_tipo, tipo_alimentacion, grado, nota) VALUES (?, ?, ?, ?, ?, ?)',
            [$fecha, $peso, (string) ($datos['grupo_tipo'] ?? 'general') ?: 'general', $tipo, $grado, trim((string) ($datos['nota'] ?? ''))]
        );
        json_out(['ok' => true, 'mensaje' => 'Peso de desperdicio registrado.']);
    } catch (PDOException $error) {
        error_db('No fue posible registrar el desperdicio en MySQL.');
    }
}

const COMPONENTES_PAE_VALIDOS = ['proteína', 'carbohidrato', 'legumbre', 'ensalada', 'fruta', 'bebida', 'otros'];

function normalizar_componentes_pae($lista, $porcentaje)
{
    $resultado = [];
    foreach (is_array($lista) ? $lista : [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $tipo = strtolower(trim((string) ($item['componente'] ?? 'otros')));
        if (!in_array($tipo, COMPONENTES_PAE_VALIDOS, true)) {
            $tipo = 'otros';
        }
        $alimento = substr(trim((string) ($item['alimento'] ?? '')), 0, 120);
        $servido = (float) ($item['servido'] ?? 0);
        if ($alimento === '' || is_nan($servido) || $servido < 0) {
            throw new InvalidArgumentException('Cada componente necesita nombre y una cantidad válida.');
        }
        $grado = trim((string) ($item['grado'] ?? ''));
        if ($grado !== '' && !in_array($grado, GRADOS_ALMUERZO, true)) {
            throw new InvalidArgumentException('El grado del componente debe ser de almuerzo.');
        }
        $resultado[] = [
            'componente' => $tipo,
            'grado' => $grado === '' ? null : $grado,
            'alimento' => $alimento,
            'unidad' => $tipo === 'bebida' ? 'L' : 'g',
            'servido' => round($servido, 3),
            'entregado' => round($servido * $porcentaje / 100, 3),
        ];
    }
    return $resultado;
}

function espejo_componentes_menu($fecha, $componentes)
{
    $menu = consultar('SELECT id FROM menu_diario WHERE fecha=?', [$fecha], true);
    if (!$menu) {
        return;
    }
    ejecutar('DELETE FROM menu_componentes WHERE menu_id=?', [$menu['id']]);
    $filas = [];
    foreach ($componentes as $c) {
        $filas[] = [$menu['id'], $c['componente'], $c['alimento'], $c['servido'], $c['unidad'], $c['grado'], 0];
    }
    if ($filas) {
        global $pdo;
        $sentencia = $pdo->prepare(
            'INSERT INTO menu_componentes (menu_id, componente, alimento, gramos, unidad, grado, es_opcional) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($filas as $fila) {
            $sentencia->execute($fila);
        }
    }
}

function ep_pae_entrega_get()
{
    exigir_sesion();
    $fecha = fecha_solicitada($_GET['fecha'] ?? null);
    try {
        $registro = consultar(
            'SELECT id, fecha, porcentaje_entregado, porcentaje_disponible, observacion, componentes
             FROM pae_entregas WHERE fecha=?',
            [$fecha],
            true
        );
        json_out(['ok' => true, 'data' => entrega_a_dict($registro)]);
    } catch (PDOException $error) {
        error_db('No fue posible consultar la entrega del PAE.');
    }
}

function entrega_a_dict($registro)
{
    if (!$registro) {
        return null;
    }
    $componentes = [];
    if (!empty($registro['componentes'])) {
        $decodificado = json_decode($registro['componentes'], true);
        $componentes = is_array($decodificado) ? $decodificado : [];
    }
    $registro['porcentaje_entregado'] = (float) $registro['porcentaje_entregado'];
    $registro['porcentaje_disponible'] = (float) $registro['porcentaje_disponible'];
    $registro['componentes'] = $componentes;
    return $registro;
}

function ep_pae_entrega_post()
{
    exigir_sesion();
    $datos = datos_peticion();
    try {
        $fecha = fecha_solicitada($datos['fecha'] ?? null);
        $entregado = (float) ($datos['porcentaje_entregado'] ?? 0);
        $disponible = (float) ($datos['porcentaje_disponible'] ?? 0);
    } catch (Exception $error) {
        json_error('Ingrese una fecha y porcentajes válidos.', 400);
    }
    if (is_nan($entregado) || is_nan($disponible) || $entregado < 0 || $entregado > 100 || $disponible < 0 || $disponible > 100) {
        json_error('Los porcentajes deben estar entre 0 y 100.', 400);
    }
    try {
        $componentes = normalizar_componentes_pae($datos['componentes'] ?? [], $entregado);
    } catch (InvalidArgumentException $error) {
        json_error('Revise los componentes: cada uno necesita alimento y una cantidad válida.', 400);
    }
    $observacion = trim((string) ($datos['observacion'] ?? ''));
    try {
        ejecutar(
            'INSERT INTO pae_entregas (fecha, porcentaje_entregado, porcentaje_disponible, observacion, componentes)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE porcentaje_entregado=VALUES(porcentaje_entregado),
               porcentaje_disponible=VALUES(porcentaje_disponible), observacion=VALUES(observacion),
               componentes=VALUES(componentes)',
            [$fecha, $entregado, $disponible, $observacion !== '' ? $observacion : null,
             json_encode($componentes, JSON_UNESCAPED_UNICODE)]
        );
        try {
            espejo_componentes_menu($fecha, $componentes);
        } catch (PDOException $error) {
            // el registro principal ya quedó guardado
        }
        json_out(['ok' => true, 'data' => [
            'fecha' => $fecha,
            'porcentaje_entregado' => $entregado,
            'porcentaje_disponible' => $disponible,
            'observacion' => $observacion,
            'componentes' => $componentes,
        ]]);
    } catch (PDOException $error) {
        error_db('No fue posible guardar el registro del almuerzo en MySQL.');
    }
}

function entrega_grupos_a_dict($fecha, $dia, $filas)
{
    $pct = $dia ? (float) $dia['porcentaje_entregado'] : 0.0;
    $grupos = [];
    foreach ($filas as $f) {
        $servido = (float) ($f['gramos_servidos'] ?? 0);
        $grupos[] = [
            'grado' => $f['grado'],
            'servido' => $servido,
            'entregado' => round($servido * $pct / 100, 2),
        ];
    }
    return [
        'fecha' => $fecha,
        'porcentaje_entregado' => $pct,
        'gramaje_total' => ($dia && $dia['gramaje_total'] !== null) ? (float) $dia['gramaje_total'] : null,
        'observacion' => ($dia['observacion'] ?? '') ?: '',
        'grupos' => $grupos,
    ];
}

function ep_entrega_grupos_get()
{
    exigir_sesion();
    $fecha = fecha_solicitada($_GET['fecha'] ?? null);
    try {
        $dia = consultar(
            'SELECT fecha, porcentaje_entregado, gramaje_total, observacion FROM pae_entrega_dia WHERE fecha=?',
            [$fecha],
            true
        );
        $filas = consultar('SELECT grado, gramos_servidos FROM pae_entrega_grupos WHERE fecha=?', [$fecha]);
        if (!$dia && !$filas) {
            json_out(['ok' => true, 'data' => null]);
        }
        json_out(['ok' => true, 'data' => entrega_grupos_a_dict($fecha, $dia, $filas)]);
    } catch (PDOException $error) {
        error_db('No fue posible consultar la entrega del PAE por grupo.');
    }
}

function ep_entrega_grupos_post()
{
    exigir_sesion();
    $datos = datos_peticion();
    global $pdo;
    try {
        $fecha = fecha_solicitada($datos['fecha'] ?? null);
        $porcentaje = (float) ($datos['porcentaje_entregado'] ?? 0);
        $total = $datos['gramaje_total'] ?? null;
        $total = ($total === null || $total === '') ? null : (float) $total;
        $grupos = $datos['grupos'] ?? [];
        if (!is_array($grupos) || is_nan($porcentaje) || ($total !== null && is_nan($total))) {
            throw new InvalidArgumentException();
        }
        if ($porcentaje < 0 || $porcentaje > 100) {
            json_error('El porcentaje entregado debe estar entre 0 y 100.', 400);
        }
        $permitidos = grados_entrega_pae();
        $limpios = [];
        foreach ($grupos as $grado => $gramos) {
            if (!in_array((string) $grado, $permitidos, true)) {
                throw new InvalidArgumentException();
            }
            $valor = (float) ($gramos ?? 0);
            if (is_nan($valor) || $valor < 0) {
                throw new InvalidArgumentException();
            }
            $limpios[(string) $grado] = $valor;
        }
    } catch (Exception $error) {
        json_error('Revise el porcentaje y el gramaje de cada grupo (sexto 1 a once 3).', 400);
    }
    $observacion = substr(trim((string) ($datos['observacion'] ?? '')), 0, 500);
    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO pae_entrega_dia (fecha, porcentaje_entregado, gramaje_total, observacion) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE porcentaje_entregado=VALUES(porcentaje_entregado),
               gramaje_total=VALUES(gramaje_total), observacion=VALUES(observacion)'
        )->execute([$fecha, $porcentaje, $total, $observacion !== '' ? $observacion : null]);
        $pdo->prepare('DELETE FROM pae_entrega_grupos WHERE fecha=?')->execute([$fecha]);
        $filas = [];
        $insertar = $pdo->prepare('INSERT INTO pae_entrega_grupos (fecha, grado, gramos_servidos) VALUES (?, ?, ?)');
        foreach ($limpios as $grado => $valor) {
            if ($valor > 0) {
                $insertar->execute([$fecha, $grado, $valor]);
                $filas[] = ['grado' => $grado, 'gramos_servidos' => $valor];
            }
        }
        $pdo->commit();
        $dia = ['porcentaje_entregado' => $porcentaje, 'gramaje_total' => $total, 'observacion' => $observacion];
        json_out(['ok' => true, 'data' => entrega_grupos_a_dict($fecha, $dia, $filas)]);
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_db('No fue posible guardar la entrega del PAE por grupo en MySQL.');
    }
}

function ep_componentes_pae_get()
{
    exigir_sesion();
    $fecha = fecha_solicitada($_GET['fecha'] ?? null);
    try {
        $filas = consultar(
            'SELECT id, fecha, platillo, descripcion, item, gramos_consumidos, es_esencial, creado_en
             FROM componentes_pae WHERE fecha=? ORDER BY es_esencial DESC, id DESC',
            [$fecha]
        );
        json_out(['ok' => true, 'data' => $filas]);
    } catch (PDOException $error) {
        error_db('No fue posible consultar los componentes del PAE.');
    }
}

function ep_componentes_pae_post()
{
    exigir_sesion();
    $datos = datos_peticion();
    $platillo = trim((string) ($datos['platillo'] ?? ''));
    $descripcion = trim((string) ($datos['descripcion'] ?? ''));
    $item = trim((string) ($datos['item'] ?? ''));
    try {
        $fecha = fecha_solicitada($datos['fecha'] ?? null);
        $gramos = (float) ($datos['gramos_consumidos'] ?? 0);
    } catch (Exception $error) {
        json_error('Ingrese una fecha y un gramaje válido.', 400);
    }
    if ($platillo === '' || $item === '' || is_nan($gramos) || $gramos < 0) {
        json_error('Complete el platillo, el ítem y un gramaje válido.', 400);
    }
    try {
        $esEsencial = !isset($datos['es_esencial']) || $datos['es_esencial'] ? 1 : 0;
        $nuevoId = ejecutar(
            'INSERT INTO componentes_pae (fecha, platillo, descripcion, item, gramos_consumidos, es_esencial) VALUES (?, ?, ?, ?, ?, ?)',
            [$fecha, $platillo, $descripcion !== '' ? $descripcion : null, $item, $gramos, $esEsencial]
        );
        json_out(['ok' => true, 'mensaje' => 'Componente del PAE registrado.', 'data' => [
            'id' => $nuevoId, 'fecha' => $fecha, 'platillo' => $platillo, 'descripcion' => $descripcion,
            'item' => $item, 'gramos_consumidos' => $gramos, 'es_esencial' => (bool) $esEsencial,
        ]], 201);
    } catch (PDOException $error) {
        error_db('No fue posible guardar el componente del PAE en MySQL.');
    }
}

function ep_dashboard()
{
    exigir_sesion();
    $fecha = fecha_solicitada($_GET['fecha'] ?? null);
    $grado = trim((string) ($_GET['grado'] ?? ''));
    try {
        archivar_consumos_vencidos();
        $menu = consultar('SELECT * FROM menu_diario WHERE fecha=?', [$fecha], true);
        $filtro = $grado !== '' ? ' AND e.grado=?' : '';
        $params = $grado !== '' ? [$fecha, $grado] : [$fecha];
        $consumos = consultar(
            'SELECT e.grado, e.tipo_alimentacion, COUNT(a.id) AS consumos
             FROM asistencia a JOIN estudiantes e ON e.id=a.estudiante_id
             WHERE a.fecha=?' . $filtro . ' GROUP BY e.grado, e.tipo_alimentacion ORDER BY e.grado',
            $params
        );
        $desperdicios = consultar(
            'SELECT tipo_alimentacion, grado, SUM(peso_sobrante_gramos) AS total
             FROM residuos WHERE fecha=? GROUP BY tipo_alimentacion, grado',
            [$fecha]
        );
        $waste = [];
        foreach ($desperdicios as $fila) {
            $waste[$fila['tipo_alimentacion'] . '|' . ($fila['grado'] ?: 'general')] = (float) ($fila['total'] ?? 0);
        }
        $gramaje = $menu ? (float) $menu['gramaje_total'] : 0;
        $gramajes = [];
        if ($menu && !empty($menu['gramajes_grupo'])) {
            $decodificado = is_string($menu['gramajes_grupo'])
                ? json_decode($menu['gramajes_grupo'], true)
                : $menu['gramajes_grupo'];
            if (is_array($decodificado)) {
                $gramajes = $decodificado;
            }
        }
        $grupos = [];
        foreach ($consumos as $item) {
            $g = $item['grado'];
            $tipo = $item['tipo_alimentacion'];
            $servidoGrupo = (int) $item['consumos'] * (float) (($gramajes[$g] ?? 0) ?: $gramaje);
            $desperdicioGrupo = $waste[$tipo . '|' . $g] ?? ($waste[$tipo . '|general'] ?? 0);
            $grupos[] = [
                'grupo' => $g,
                'tipo_alimentacion' => $tipo,
                'consumos' => (int) $item['consumos'],
                'servido' => $servidoGrupo,
                'desperdicio' => $desperdicioGrupo,
            ];
        }
        $totalConsumos = 0;
        $servido = 0;
        foreach ($grupos as $g) {
            $totalConsumos += $g['consumos'];
            $servido += $g['servido'];
        }
        $sobrante = array_sum($waste);
        $porTipoGrado = [];
        foreach ($waste as $clave => $valor) {
            [$tipo, $g] = explode('|', $clave);
            $porTipoGrado[] = ['tipo_alimentacion' => $tipo, 'grado' => $g, 'desperdicio' => $valor];
        }
        json_out(['ok' => true, 'data' => [
            'fecha' => $fecha,
            'menu' => $menu ?: null,
            'consumos' => $totalConsumos,
            'servido' => $servido,
            'desperdicio' => $sobrante,
            'consumo_efectivo' => max($servido - $sobrante, 0),
            'grupos' => $grupos,
            'desperdicios_por_tipo_grado' => $porTipoGrado,
        ]]);
    } catch (PDOException $error) {
        error_log('[dashboard] ' . $error->getMessage());
        error_db();
    }
}

function ep_reportes()
{
    exigir_sesion();
    $desde = fecha_solicitada($_GET['desde'] ?? null);
    $hasta = fecha_solicitada($_GET['hasta'] ?? null);
    $grado = trim((string) ($_GET['grado'] ?? ''));
    try {
        archivar_consumos_vencidos();
        $filtro = $grado !== '' ? ' AND e.grado=?' : '';
        $params = $grado !== '' ? [$desde, $hasta, $grado] : [$desde, $hasta];
        $filas = consultar(
            'SELECT a.fecha, e.grado, e.tipo_alimentacion, COUNT(a.id) AS consumos,
                    COALESCE(m.gramaje_total, 0) AS gramaje,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(m.gramajes_grupo, CONCAT("$.", e.grado))), m.gramaje_total, 0) AS gramaje_grupo,
                    COALESCE((SELECT SUM(r.peso_sobrante_gramos) FROM residuos r
                              WHERE r.fecha=a.fecha AND r.tipo_alimentacion=e.tipo_alimentacion
                                AND (r.grado=e.grado OR r.grado IS NULL)), 0) AS desperdicio
             FROM asistencia a JOIN estudiantes e ON e.id=a.estudiante_id
             LEFT JOIN menu_diario m ON m.fecha=a.fecha
             WHERE a.fecha BETWEEN ? AND ?' . $filtro . '
             GROUP BY a.fecha, e.grado, e.tipo_alimentacion, m.gramaje_total, m.gramajes_grupo
             ORDER BY a.fecha DESC, e.grado',
            $params
        );
        foreach ($filas as &$fila) {
            $fila['gramaje'] = (float) ($fila['gramaje_grupo'] ?? $fila['gramaje'] ?? 0);
            $fila['servido'] = (int) $fila['consumos'] * $fila['gramaje'];
            $fila['consumos'] = (int) $fila['consumos'];
            $fila['desperdicio'] = (float) $fila['desperdicio'];
        }
        unset($fila);
        json_out(['ok' => true, 'data' => $filas]);
    } catch (PDOException $error) {
        error_log('[reportes] ' . $error->getMessage());
        error_db();
    }
}
