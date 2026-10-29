<?php
/**
 * Panel CRUD de estudiantes.
 * El código QR contiene el token único guardado en estudiantes.codigo_qr.
 * La imagen se obtiene desde un endpoint QR externo para evitar instalar
 * dependencias adicionales en XAMPP; el registro funciona también sin cámara.
 */
require_once __DIR__ . '/../config/db.php';

function escapar(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function crearCodigoQr(): string
{
    return 'AC-' . strtoupper(bin2hex(random_bytes(10)));
}

$mensaje = '';
$error = '';
$estudianteEnEdicion = null;

// Mismos grupos que usa el panel Flask: PAE de 6º a 9º; almuerzo de 5º, 10º y 11º.
$gradosPae = ['6-1', '6-2', '6-3', '7-1', '7-2', '7-3', '8-1', '8-2', '8-3', '9-1', '9-2', '9-3'];
$gradosAlmuerzo = ['5-1', '5-2', '5-3', '10-1', '10-2', '10-3', '11-1', '11-2', '11-3'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $accion = $_POST['accion'] ?? '';
        $nombre = trim($_POST['nombre'] ?? '');
        $documento = trim($_POST['documento'] ?? '');
        $edad = filter_input(INPUT_POST, 'edad', FILTER_VALIDATE_INT);
        $grado = trim($_POST['grado'] ?? '');
        $tipoAlimentacion = trim($_POST['tipo_alimentacion'] ?? 'PAE');

        if ($accion === 'crear' || $accion === 'editar') {
            $gradosValidos = $tipoAlimentacion === 'PAE' ? $gradosPae : $gradosAlmuerzo;
            if (!preg_match('/^[\p{L} ]+$/u', $nombre) || !preg_match('/^[0-9]+$/', $documento) || $edad === false || $edad < 3 || $edad > 20 || !in_array($tipoAlimentacion, ['PAE', 'almuerzo'], true) || !in_array($grado, $gradosValidos, true)) {
                throw new InvalidArgumentException('Escriba un nombre con letras, un documento numérico, una edad entre 3 y 20, y seleccione un grado compatible con el servicio: PAE de 6º a 9º, o almuerzo de 5º, 10º u 11º.');
            }

            if ($accion === 'crear') {
                $consulta = $pdo->prepare(
                    'INSERT INTO estudiantes (nombre, documento, edad, grado, tipo_alimentacion, codigo_qr)
                     VALUES (:nombre, :documento, :edad, :grado, :tipo_alimentacion, :codigo_qr)'
                );
                $consulta->execute([
                    ':nombre' => $nombre,
                    ':documento' => $documento,
                    ':edad' => $edad,
                    ':grado' => $grado,
                    ':tipo_alimentacion' => $tipoAlimentacion,
                    ':codigo_qr' => crearCodigoQr(),
                ]);
                $mensaje = 'Estudiante creado correctamente.';
            } else {
                $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                if (!$id) {
                    throw new InvalidArgumentException('El estudiante seleccionado no es válido.');
                }

                $consulta = $pdo->prepare(
                    'UPDATE estudiantes
                     SET nombre = :nombre, documento = :documento, edad = :edad, grado = :grado, tipo_alimentacion = :tipo_alimentacion
                     WHERE id = :id'
                );
                $consulta->execute([
                    ':nombre' => $nombre,
                    ':documento' => $documento,
                    ':edad' => $edad,
                    ':grado' => $grado,
                    ':tipo_alimentacion' => $tipoAlimentacion,
                    ':id' => $id,
                ]);
                $mensaje = 'Estudiante actualizado correctamente.';
            }
        } elseif ($accion === 'eliminar') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) {
                throw new InvalidArgumentException('El estudiante seleccionado no es válido.');
            }

            $consulta = $pdo->prepare('DELETE FROM estudiantes WHERE id = :id');
            $consulta->execute([':id' => $id]);
            $mensaje = 'Estudiante eliminado correctamente.';
        }
    }

    $idEditar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);
    if ($idEditar) {
        $consulta = $pdo->prepare('SELECT * FROM estudiantes WHERE id = :id');
        $consulta->execute([':id' => $idEditar]);
        $estudianteEnEdicion = $consulta->fetch() ?: null;
    }

    $estudiantes = $pdo->query(
        "SELECT id, nombre, documento, edad, grado, tipo_alimentacion, codigo_qr, fecha_registro
         FROM estudiantes ORDER BY grado ASC, nombre ASC"
    )->fetchAll();
} catch (InvalidArgumentException $errorValidacion) {
    $error = $errorValidacion->getMessage();
    $estudiantes = $pdo->query('SELECT * FROM estudiantes ORDER BY nombre ASC')->fetchAll();
} catch (PDOException $errorBaseDatos) {
    $error = $errorBaseDatos->getCode() === '23000'
        ? 'El documento ya está registrado.'
        : 'No fue posible completar la operación en la base de datos.';
    $estudiantes = $pdo->query('SELECT * FROM estudiantes ORDER BY nombre ASC')->fetchAll();
}

$editando = is_array($estudianteEnEdicion);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estudiantes | Almuerzos Conscientes</title>
    <style>
        :root { font-family: Arial, sans-serif; color: #173b32; background: #f4f7f2; }
        body { max-width: 1100px; margin: 0 auto; padding: 32px 18px; }
        h1 { margin-bottom: 6px; } p { color: #527066; }
        .panel { background: #fff; border: 1px solid #d9e5dd; border-radius: 8px; padding: 22px; margin-top: 20px; }
        form.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; align-items: end; }
        label { display: block; font-weight: bold; margin-bottom: 6px; }
        input, button { width: 100%; padding: 10px; border: 1px solid #b9cbc1; border-radius: 5px; font: inherit; box-sizing: border-box; }
        button { background: #176b4d; color: white; border: 0; cursor: pointer; font-weight: bold; }
        button.danger { background: #a43d3d; } a { color: #176b4d; }
        .notice { padding: 12px; border-radius: 5px; background: #e4f4e8; color: #175d38; }
        .error { background: #fbe4e4; color: #8c2727; }
        .table-wrap { overflow-x: auto; } table { border-collapse: collapse; width: 100%; min-width: 780px; }
        th, td { text-align: left; border-bottom: 1px solid #e1ebe4; padding: 11px 8px; vertical-align: middle; }
        th { background: #edf5ee; } .qr { width: 88px; height: 88px; }
        .actions { display: flex; gap: 8px; align-items: center; } .actions form { margin: 0; }
        .actions button, .actions a { width: auto; padding: 7px 10px; text-decoration: none; }
    </style>
</head>
<body>
    <h1>Gestión de estudiantes</h1>
    <p>Registre beneficiarios y genere automáticamente su código QR único.</p>

    <?php if ($mensaje !== ''): ?><div class="notice"><?= escapar($mensaje) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?= escapar($error) ?></div><?php endif; ?>

    <section class="panel">
        <h2><?= $editando ? 'Editar estudiante' : 'Nuevo estudiante' ?></h2>
        <form class="grid" method="post">
            <input type="hidden" name="accion" value="<?= $editando ? 'editar' : 'crear' ?>">
            <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int) $estudianteEnEdicion['id'] ?>"><?php endif; ?>
            <div><label for="nombre">Nombre completo</label><input id="nombre" name="nombre" required maxlength="150" value="<?= escapar($estudianteEnEdicion['nombre'] ?? '') ?>"></div>
            <div><label for="documento">Número de documento</label><input id="documento" name="documento" required inputmode="numeric" maxlength="30" value="<?= escapar($estudianteEnEdicion['documento'] ?? '') ?>"></div>
            <div><label for="edad">Edad</label><input id="edad" name="edad" type="number" min="3" max="20" required value="<?= escapar((string) ($estudianteEnEdicion['edad'] ?? '')) ?>"></div>
            <div><label for="tipo_alimentacion">Servicio</label><select id="tipo_alimentacion" name="tipo_alimentacion" required>
                <option value="PAE" <?= ($estudianteEnEdicion['tipo_alimentacion'] ?? 'PAE') === 'PAE' ? 'selected' : '' ?>>PAE · sexto a noveno</option>
                <option value="almuerzo" <?= ($estudianteEnEdicion['tipo_alimentacion'] ?? '') === 'almuerzo' ? 'selected' : '' ?>>Almuerzo · quinto, décimo y once</option>
            </select></div>
            <div><label for="grado">Grado / carpeta</label><select id="grado" name="grado" required><option value="">Seleccione</option></select></div>
            <button type="submit"><?= $editando ? 'Guardar cambios' : 'Crear estudiante' ?></button>
        </form>
        <?php if ($editando): ?><p><a href="index.php">Cancelar edición</a></p><?php endif; ?>
    </section>

    <section class="panel">
        <h2>Estudiantes registrados</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Nombre</th><th>Documento</th><th>Grado</th><th>Alimentación</th><th>Código QR</th><th>Registro</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($estudiantes as $estudiante): ?>
                <tr>
                    <td><?= escapar($estudiante['nombre']) ?></td>
                    <td><?= escapar($estudiante['documento']) ?></td>
                    <td><?= escapar($estudiante['grado']) ?></td>
                    <td><?= escapar($estudiante['tipo_alimentacion'] ?? 'PAE') ?></td>
                    <td><img class="qr" src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= rawurlencode($estudiante['codigo_qr']) ?>" alt="Código QR de <?= escapar($estudiante['nombre']) ?>"><br><?= escapar($estudiante['codigo_qr']) ?></td>
                    <td><?= escapar($estudiante['fecha_registro']) ?></td>
                    <td><div class="actions"><a href="?editar=<?= (int) $estudiante['id'] ?>">Editar</a><form method="post" onsubmit="return confirm('¿Eliminar este estudiante?');"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $estudiante['id'] ?>"><button class="danger" type="submit">Eliminar</button></form></div></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$estudiantes): ?><tr><td colspan="7">No hay estudiantes registrados.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <script>
    const GRADOS = <?= json_encode(['PAE' => $gradosPae, 'almuerzo' => $gradosAlmuerzo], JSON_UNESCAPED_UNICODE) ?>;
    const GRADO_ACTUAL = <?= json_encode($estudianteEnEdicion['grado'] ?? '') ?>;
    function pintarGrados() {
        const tipo = document.getElementById('tipo_alimentacion').value;
        const select = document.getElementById('grado');
        const actual = select.value || GRADO_ACTUAL;
        select.innerHTML = '<option value="">Seleccione</option>' + GRADOS[tipo].map(function (g) {
            const partes = g.split('-');
            return '<option value="' + g + '"' + (g === actual ? ' selected' : '') + '>' + partes[0] + 'º' + partes[1] + '</option>';
        }).join('');
    }
    document.getElementById('tipo_alimentacion').addEventListener('change', pintarGrados);
    pintarGrados();
    </script>
</body>
</html>
