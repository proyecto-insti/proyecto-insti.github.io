"""Servidor de Almuerzos Conscientes.

La aplicación usa MySQL cuando está disponible (XAMPP o una instancia configurada
por variables de entorno). La interfaz web también contempla un modo local de
respaldo para poder revisar el prototipo antes de importar la base de datos.
"""
from contextlib import closing
from datetime import date, datetime, timedelta
import os
import re
import json
import secrets
import socket
import threading
import time
from functools import wraps
from werkzeug.security import check_password_hash  # type: ignore[reportMissingImports]


def _cargar_archivo_env():
    """Lee un archivo .env (si existe) junto a este script y define las
    variables que falten en el entorno. Así no hay que exportar DB_HOST,
    DB_USER, DB_PASSWORD, DB_NAME o DB_PORT manualmente cada vez: basta con
    crear un archivo `.env` (ver `.env.ejemplo`) en la carpeta del proyecto."""
    ruta_env = os.path.join(os.path.dirname(__file__), ".env")
    if not os.path.isfile(ruta_env):
        return
    with open(ruta_env, "r", encoding="utf-8") as archivo:
        for linea in archivo:
            linea = linea.strip()
            if not linea or linea.startswith("#") or "=" not in linea:
                continue
            clave, valor = linea.split("=", 1)
            clave, valor = clave.strip(), valor.strip().strip('"').strip("'")
            os.environ.setdefault(clave, valor)


_cargar_archivo_env()

try:
    import mysql.connector  # type: ignore[reportMissingImports]
except ModuleNotFoundError:  # Permite revisar el modo local antes de instalar MySQL.
    class _MysqlUnavailableError(Exception):
        pass

    class _MysqlUnavailableConnector:
        Error = _MysqlUnavailableError
        IntegrityError = _MysqlUnavailableError

        @staticmethod
        def connect(*args, **kwargs):
            raise _MysqlUnavailableError("mysql-connector-python no está instalado")

    class _MysqlUnavailable:
        connector = _MysqlUnavailableConnector()

    mysql = _MysqlUnavailable()
from flask import Flask, abort, jsonify, redirect, request, send_from_directory, url_for, flash, session  # type: ignore[reportMissingImports]

app = Flask(__name__)
app.secret_key = os.getenv("SECRET_KEY", "almuerzos-conscientes-local")
PUBLIC_DIR = os.path.join(os.path.dirname(__file__), "src", "public")
GRADOS_ALMUERZO = ("5-1", "5-2", "5-3", "10-1", "10-2", "10-3", "11-1", "11-2", "11-3")
GRADOS_PAE = ("6-1", "6-2", "6-3", "7-1", "7-2", "7-3", "8-1", "8-2", "8-3", "9-1", "9-2", "9-3")
GRADOS_PERMITIDOS = GRADOS_ALMUERZO + GRADOS_PAE
# Grupos que reciben la entrega del PAE (formulario "Entrega del PAE" del panel).
GRADOS_ENTREGA_PAE = tuple(f"{g}-{s}" for g in range(6, 12) for s in (1, 2, 3))
TIPOS_ALIMENTACION = ("PAE", "almuerzo")
TIPOS_CONSUMO = ("vaso_leche", "almuerzo")
GRADOS_DESPERDICIO_PAE = GRADOS_ALMUERZO + ("7-1", "7-2", "7-3", "8-1", "8-2", "8-3")
ETIQUETAS_CONSUMO = {"ninguno": "No consumió", "vaso_leche": "Vaso de leche", "almuerzo": "Almuerzo"}


def grados_por_alimentacion(tipo):
    return GRADOS_ALMUERZO if str(tipo or "").strip().lower() == "almuerzo" else GRADOS_PAE


def validar_grado_alimentacion(grado, tipo):
    return str(grado or "").strip() in grados_por_alimentacion(tipo)


def usuario_actual():
    return session.get("usuario")


def requiere_sesion(func):
    @wraps(func)
    def protegida(*args, **kwargs):
        if not usuario_actual():
            if request.path.startswith("/api/"):
                return jsonify({"ok": False, "error": "Debe iniciar sesión.", "autenticado": False}), 401
            return redirect(url_for("login_page"))
        return func(*args, **kwargs)
    return protegida


@app.before_request
def proteger_rutas():
    publicas = {"inicio", "login_page", "login", "estado_sesion", "logout", "acceso_formulario", "desperdicio_pae", "guardar_desperdicio_pae", "resumen_desperdicio_formulario", "estudiantes", "guardar_estudiante", "api_estudiantes", "api_crear_estudiante", "api_estado_bd", "archivos_publicos"}
    if request.endpoint in publicas or request.path.startswith("/static/"):
        return None
    if not usuario_actual():
        if request.path.startswith("/api/"):
            return jsonify({"ok": False, "error": "Debe iniciar sesión.", "autenticado": False}), 401
        return redirect(url_for("login_page"))
    return None


def validar_estudiante(nombre, documento, edad, grado, tipo_alimentacion="PAE"):
    tipo_alimentacion = str(tipo_alimentacion or "PAE").strip()
    if tipo_alimentacion not in TIPOS_ALIMENTACION:
        tipo_alimentacion = ""
    """Valida los datos de identidad antes de enviarlos a MySQL."""
    nombre_limpio = " ".join(str(nombre or "").strip().split())
    documento_limpio = str(documento or "").strip()
    try:
        edad_entero = int(str(edad or "").strip())
    except (TypeError, ValueError):
        edad_entero = 0
    valido = (
        bool(nombre_limpio)
        and bool(re.fullmatch(r"[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+", nombre_limpio))
        and bool(re.fullmatch(r"\d+", documento_limpio))
        and 3 <= edad_entero <= 20
        and grado in GRADOS_PERMITIDOS
        and tipo_alimentacion in TIPOS_ALIMENTACION
    )
    return valido, nombre_limpio, documento_limpio, edad_entero, tipo_alimentacion


def obtener_conexion():
    """Crea una conexión MySQL usando los valores habituales de XAMPP.
    Si falla, deja en la consola el motivo EXACTO (credenciales, host, puerto
    o base de datos incorrectos) en vez de solo decir "no fue posible"."""
    opciones = {}
    ssl_ca = os.getenv("DB_SSL_CA", "").strip()
    if ssl_ca or os.getenv("DB_SSL", "").strip().lower() in ("1", "true", "si", "yes"):
        # Servidores en la nube como TiDB Serverless exigen TLS.
        opciones["ssl_verify_identity"] = True
        if ssl_ca:
            opciones["ssl_ca"] = ssl_ca
    try:
        return mysql.connector.connect(
            host=os.getenv("DB_HOST", "127.0.0.1"),
            port=int(os.getenv("DB_PORT", "3306")),
            user=os.getenv("DB_USER", "root"),
            password=os.getenv("DB_PASSWORD", ""),
            database=os.getenv("DB_NAME", "almuerzos_conscientes"),
            connection_timeout=5,
            **opciones,
        )
    except mysql.connector.Error as error:
        print(f"[MySQL] No fue posible conectar ({os.getenv('DB_HOST','127.0.0.1')}:{os.getenv('DB_PORT','3306')}, "
              f"usuario={os.getenv('DB_USER','root')}, base={os.getenv('DB_NAME','almuerzos_conscientes')}): {error}")
        raise


def crear_codigo_qr():
    return f"AC-{secrets.token_hex(10).upper()}"


def asegurar_columna_residuos_alimentacion():
    """Crea y actualiza la tabla de desperdicios para instalaciones nuevas y existentes."""
    conexion = obtener_conexion()
    cursor = conexion.cursor()
    try:
        # No depender de que el usuario haya importado previamente el SQL.
        cursor.execute("""CREATE TABLE IF NOT EXISTS residuos (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fecha DATE NOT NULL,
            peso_sobrante_gramos DECIMAL(10,2) UNSIGNED NOT NULL,
            grupo_tipo VARCHAR(20) NOT NULL DEFAULT 'general',
            tipo_alimentacion ENUM('PAE','almuerzo') NOT NULL DEFAULT 'PAE',
            grado VARCHAR(20) NULL,
            nota VARCHAR(500) NULL,
            uid_envio VARCHAR(40) NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_residuos_fecha_grado (fecha, grado)
        ) ENGINE=InnoDB""")
        cursor.execute("ALTER TABLE residuos ADD COLUMN IF NOT EXISTS grupo_tipo VARCHAR(20) NOT NULL DEFAULT 'general'")
        # Acepta 'individual' (pesaje por grado del formulario QR) en bases antiguas.
        try:
            cursor.execute("ALTER TABLE residuos MODIFY COLUMN grupo_tipo ENUM('primaria','superior','general','individual') NOT NULL DEFAULT 'general'")
        except mysql.connector.Error:
            pass
        cursor.execute("ALTER TABLE residuos ADD COLUMN IF NOT EXISTS tipo_alimentacion ENUM('PAE','almuerzo') NOT NULL DEFAULT 'PAE' AFTER grupo_tipo")
        cursor.execute("ALTER TABLE residuos ADD COLUMN IF NOT EXISTS grado VARCHAR(20) NULL")
        cursor.execute("ALTER TABLE residuos ADD COLUMN IF NOT EXISTS nota VARCHAR(500) NULL")
        cursor.execute("ALTER TABLE residuos ADD COLUMN IF NOT EXISTS uid_envio VARCHAR(40) NULL")
        try:
            cursor.execute("ALTER TABLE residuos ADD UNIQUE KEY uq_residuos_uid_envio (uid_envio)")
        except mysql.connector.Error:
            pass  # el índice ya existe en instalaciones actualizadas
        conexion.commit()
    finally:
        cursor.close()
        conexion.close()


def asegurar_tabla_componentes_pae():
    """Crea el registro de componentes PAE si la base aún no lo tiene."""
    conexion = obtener_conexion()
    cursor = conexion.cursor()
    try:
        cursor.execute("""CREATE TABLE IF NOT EXISTS componentes_pae (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          fecha DATE NOT NULL,
          platillo VARCHAR(180) NOT NULL,
          descripcion VARCHAR(500) NULL,
          item VARCHAR(180) NOT NULL,
          gramos_consumidos DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0,
          es_esencial BOOLEAN NOT NULL DEFAULT TRUE,
          creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_componentes_pae_fecha (fecha),
          KEY idx_componentes_pae_item (item)
        ) ENGINE=InnoDB""")
        conexion.commit()
    finally:
        cursor.close()
        conexion.close()


def asegurar_tabla_entregas_pae():
    """Crea el registro diario de entrega y disponibilidad porcentual del PAE."""
    conexion = obtener_conexion()
    cursor = conexion.cursor()
    try:
        cursor.execute("""CREATE TABLE IF NOT EXISTS pae_entregas (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          fecha DATE NOT NULL UNIQUE,
          porcentaje_entregado DECIMAL(5,2) NOT NULL DEFAULT 0,
          porcentaje_disponible DECIMAL(5,2) NOT NULL DEFAULT 0,
          observacion VARCHAR(500) NULL,
          creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB""")
        # Detalle servido/entregado por componente (JSON): gramos, y litros en bebidas.
        cursor.execute("ALTER TABLE pae_entregas ADD COLUMN IF NOT EXISTS componentes LONGTEXT NULL AFTER observacion")
        conexion.commit()
    finally:
        cursor.close()
        conexion.close()

def asegurar_tablas_entrega_pae_grupos():
    """Entrega del PAE: porcentaje + observación por día y gramaje por grupo (sexto-1 a once-3)."""
    conexion = obtener_conexion()
    cursor = conexion.cursor()
    try:
        cursor.execute("""CREATE TABLE IF NOT EXISTS pae_entrega_dia (
          fecha DATE NOT NULL PRIMARY KEY,
          porcentaje_entregado DECIMAL(5,2) NOT NULL DEFAULT 0,
          gramaje_total DECIMAL(12,2) NULL,
          observacion VARCHAR(500) NULL,
          actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB""")
        cursor.execute("""CREATE TABLE IF NOT EXISTS pae_entrega_grupos (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          fecha DATE NOT NULL,
          grado VARCHAR(10) NOT NULL,
          gramos_servidos DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0,
          UNIQUE KEY uq_pae_entrega_grupo (fecha, grado)
        ) ENGINE=InnoDB""")
        conexion.commit()
    finally:
        cursor.close()
        conexion.close()


def asegurar_columna_consumo():
    """Agrega el tipo de consumo diario a instalaciones existentes."""
    conexion = obtener_conexion()
    cursor = conexion.cursor()
    try:
        try:
            # Primero ampliamos el ENUM y después migramos el valor antiguo.
            cursor.execute("ALTER TABLE asistencia MODIFY COLUMN tipo_consumo ENUM('ninguno','vaso_leche','almuerzo') NOT NULL DEFAULT 'ninguno' AFTER fecha")
        except mysql.connector.Error:
            cursor.execute("ALTER TABLE asistencia ADD COLUMN tipo_consumo ENUM('ninguno','vaso_leche','almuerzo') NOT NULL DEFAULT 'ninguno' AFTER fecha")
        cursor.execute("UPDATE asistencia SET tipo_consumo='almuerzo' WHERE tipo_consumo='bandeja_transportada'")
        conexion.commit()
    finally:
        cursor.close()
        conexion.close()


def asegurar_columna_alimentacion():
    """Añade el campo nuevo cuando se usa una base creada con una versión anterior."""
    conexion = obtener_conexion()
    cursor = conexion.cursor()
    try:
        cursor.execute("ALTER TABLE estudiantes ADD COLUMN IF NOT EXISTS tipo_alimentacion ENUM('PAE','almuerzo') NOT NULL DEFAULT 'PAE' AFTER grado")
        conexion.commit()
    finally:
        cursor.close()
        conexion.close()


def fecha_solicitada(valor=None):
    try:
        return datetime.strptime(valor, "%Y-%m-%d").date() if valor else date.today()
    except (TypeError, ValueError):
        return date.today()


def fila_a_dict(cursor, fila):
    """Convierte una fila de MySQL en diccionario, dejando los valores listos
    para JSON. mysql-connector devuelve las columnas TIME como
    `datetime.timedelta`, y Flask no sabe convertir ese tipo a JSON: eso hacía
    que /api/estudiantes, /api/control-estudiantes, /api/consumos, etc.
    fallaran con error 500 en cuanto había una hora registrada, y por eso el
    Control y el Registro de estudiantes dejaban de reflejar los cambios."""
    if not fila:
        return None
    datos = dict(zip(cursor.column_names, fila))
    for clave, valor in datos.items():
        if isinstance(valor, timedelta):
            segundos_totales = int(valor.total_seconds())
            horas, resto = divmod(segundos_totales, 3600)
            minutos, segundos = divmod(resto, 60)
            datos[clave] = f"{horas:02d}:{minutos:02d}:{segundos:02d}"
        elif isinstance(valor, (datetime, date)):
            datos[clave] = valor.isoformat(sep=" ") if isinstance(valor, datetime) else valor.isoformat()
    return datos


def consultar(sql, parametros=(), varios=True):
    """Ejecuta una consulta de lectura y devuelve diccionarios."""
    conexion = obtener_conexion()
    cursor = conexion.cursor()
    try:
        cursor.execute(sql, parametros)
        filas = cursor.fetchall()
        datos = [fila_a_dict(cursor, fila) for fila in filas]
        return datos if varios else (datos[0] if datos else None)
    finally:
        with closing(cursor):
            pass
        conexion.close()


def ejecutar(sql, parametros=(), muchos=None):
    """Ejecuta una escritura parametrizada y confirma la transacción."""
    conexion = obtener_conexion()
    cursor = conexion.cursor()
    try:
        if muchos is not None:
            cursor.executemany(sql, muchos)
        else:
            cursor.execute(sql, parametros)
        conexion.commit()
        return cursor.lastrowid
    finally:
        with closing(cursor):
            pass
        conexion.close()


def archivar_consumos_vencidos():
    """Archiva consumos y marca como no consumidos los estudiantes sin registro tras 24 horas."""
    conexion = obtener_conexion()
    cursor = conexion.cursor()
    try:
        cursor.execute(
            """INSERT INTO historial_consumo
               (asistencia_id, estudiante_id, nombre_estudiante, documento, grado,
                fecha_consumo, hora_consumo, tipo_consumo, consumido_en, metodo_registro)
               SELECT a.id, e.id, e.nombre, e.documento, e.grado,
                      a.fecha, a.hora, a.tipo_consumo, a.consumido_en, a.metodo_registro
               FROM asistencia a JOIN estudiantes e ON e.id=a.estudiante_id
               WHERE a.consumido_en < (NOW() - INTERVAL 24 HOUR)
                 AND NOT EXISTS (SELECT 1 FROM historial_consumo h WHERE h.asistencia_id=a.id)"""
        )
        archivados = cursor.rowcount
        # Cada padrón se cierra por día: si ayer no hubo asistencia, queda como no consumido.
        cursor.execute(
            """INSERT INTO historial_consumo
               (asistencia_id, estudiante_id, nombre_estudiante, documento, grado,
                fecha_consumo, hora_consumo, tipo_consumo, consumido_en, metodo_registro)
               SELECT NULL, e.id, e.nombre, e.documento, e.grado,
                      DATE_SUB(CURDATE(), INTERVAL 1 DAY), '23:59:59', 'ninguno',
                      TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '23:59:59'), 'formulario'
               FROM estudiantes e
               WHERE NOT EXISTS (SELECT 1 FROM asistencia a WHERE a.estudiante_id=e.id AND a.fecha=DATE_SUB(CURDATE(), INTERVAL 1 DAY))
                 AND NOT EXISTS (SELECT 1 FROM historial_consumo h WHERE h.estudiante_id=e.id AND h.fecha_consumo=DATE_SUB(CURDATE(), INTERVAL 1 DAY))"""
        )
        archivados += cursor.rowcount
        cursor.execute("DELETE FROM asistencia WHERE consumido_en < (NOW() - INTERVAL 24 HOUR)")
        conexion.commit()
        return archivados
    finally:
        with closing(cursor):
            pass
        conexion.close()


def error_db(mensaje="No fue posible conectar con MySQL."):
    return jsonify({"ok": False, "error": mensaje, "modo_local_disponible": True}), 503


# Hashes iniciales de las cuentas institucionales (usuario "admin" y "docente",
# ambos con contraseña 123456 documentada en el README). Nunca se guardan
# contraseñas en texto plano; se usan para bootstrap si la tabla no fue importada.
USUARIOS_INICIALES = (
    ("admin", "Personal de administración", "administracion", "pbkdf2:sha256:1000000$6wnwtEOzWFqdG5HG$7854634f6d66a31754e586b87bf6cba0f7bbdb5b840c5f387597d4a0d75aa746"),
    ("docente", "Personal docente", "docente", "pbkdf2:sha256:1000000$GpWMXIGBzlV3wpOw$b5f0cb9ad59e6904693e24a75e340536e54a82dc3d3d72cf8a1d75c5b91aea0e"),
)


def asegurar_usuarios():
    """Asegura que MySQL tenga las dos cuentas iniciales sin sobrescribir cambios."""
    conexion = obtener_conexion()
    cursor = conexion.cursor()
    try:
        cursor.execute(
            """CREATE TABLE IF NOT EXISTS usuarios (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                usuario VARCHAR(60) NOT NULL UNIQUE,
                nombre VARCHAR(120) NOT NULL,
                rol ENUM('administracion','docente') NOT NULL,
                contrasena_hash VARCHAR(255) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ultimo_acceso DATETIME NULL
            ) ENGINE=InnoDB"""
        )
        cursor.executemany(
            """INSERT IGNORE INTO usuarios (usuario, nombre, rol, contrasena_hash)
               VALUES (%s,%s,%s,%s)""",
            USUARIOS_INICIALES,
        )
        conexion.commit()
    finally:
        cursor.close()
        conexion.close()


def obtener_token_qr_formulario():
    """Devuelve el token único del acceso QR del formulario público de desperdicio PAE."""
    acceso = consultar(
        "SELECT token FROM accesos_qr WHERE nombre=%s AND activo=1",
        ("formulario_desperdicio_pae",),
        varios=False,
    )
    if acceso:
        return acceso["token"]
    token = secrets.token_urlsafe(32)
    ejecutar(
        "INSERT INTO accesos_qr (nombre, token, activo) VALUES (%s,%s,1)",
        ("formulario_desperdicio_pae", token),
    )
    return token



@app.after_request
def permitir_formulario_local(respuesta):
    """Permite que el formulario del PAE guarde aunque se abra como archivo local."""
    if request.path.startswith("/api/formulario-desperdicio-pae"):
        respuesta.headers["Access-Control-Allow-Origin"] = "*"
        respuesta.headers["Access-Control-Allow-Headers"] = "Content-Type"
        respuesta.headers["Access-Control-Allow-Methods"] = "GET, POST, OPTIONS"
    return respuesta


@app.route("/")
def inicio():
    if usuario_actual():
        return redirect(url_for("panel"))
    return send_from_directory(PUBLIC_DIR, "index1.html")


@app.get("/index1.html")
def login_page():
    if usuario_actual():
        return redirect(url_for("panel"))
    return send_from_directory(PUBLIC_DIR, "index1.html")


@app.get("/index.html")
def panel():
    return send_from_directory(PUBLIC_DIR, "index.html")


@app.post("/api/login")
def login():
    datos = request.get_json(silent=True) or request.form
    usuario = str(datos.get("usuario", "")).strip().lower()
    contrasena = str(datos.get("contrasena", ""))
    if not usuario or not contrasena:
        return jsonify({"ok": False, "error": "Ingrese usuario y contraseña."}), 400
    try:
        asegurar_usuarios()
        cuenta = consultar(
            "SELECT id, usuario, nombre, rol, contrasena_hash FROM usuarios WHERE usuario=%s AND activo=1",
            (usuario,), varios=False
        )
        if not cuenta or not check_password_hash(cuenta["contrasena_hash"], contrasena):
            return jsonify({"ok": False, "error": "Usuario o contraseña incorrectos."}), 401
        session.clear()
        session["usuario"] = {"id": cuenta["id"], "usuario": cuenta["usuario"], "nombre": cuenta["nombre"], "rol": cuenta["rol"]}
        session.permanent = True
        ejecutar("UPDATE usuarios SET ultimo_acceso=CURRENT_TIMESTAMP WHERE id=%s", (cuenta["id"],))
        return jsonify({"ok": True, "usuario": session["usuario"], "redirect": url_for("panel")})
    except mysql.connector.Error:
        return error_db("No fue posible validar el usuario en MySQL.")


@app.get("/api/sesion")
def estado_sesion():
    return jsonify({"ok": True, "autenticado": bool(usuario_actual()), "usuario": usuario_actual()})


@app.get("/api/estado-bd")
def api_estado_bd():
    """Diagnóstico rápido: abre /api/estado-bd en el navegador para saber si
    Flask logra conectarse a MySQL y, si falla, por qué (sin exponer la
    contraseña). Útil para confirmar la conexión desde cualquier dispositivo
    de la red, no solo desde la computadora donde corre `python app.py`."""
    config = {
        "host": os.getenv("DB_HOST", "127.0.0.1"),
        "puerto": os.getenv("DB_PORT", "3306"),
        "usuario": os.getenv("DB_USER", "root"),
        "base_de_datos": os.getenv("DB_NAME", "almuerzos_conscientes"),
    }
    try:
        conexion = obtener_conexion()
        conexion.close()
        return jsonify({"ok": True, "conectado": True, "config": config})
    except mysql.connector.Error as error:
        return jsonify({
            "ok": False,
            "conectado": False,
            "config": config,
            "error": str(error),
            "sugerencia": "Revise que MySQL esté iniciado en XAMPP, que DB_NAME coincida con la base importada "
                           "y que DB_USER/DB_PASSWORD sean correctos. Si usa un puerto distinto a 3306, defina DB_PORT.",
        }), 503


@app.post("/api/logout")
def logout():
    session.clear()
    return jsonify({"ok": True, "redirect": url_for("login_page")})


def ip_red_local():
    """IP del servidor en la red local (para que el QR funcione desde otros dispositivos)."""
    try:
        sonda = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sonda.connect(("10.255.255.255", 1))
        ip = sonda.getsockname()[0]
        sonda.close()
        return ip if ip and not ip.startswith("127.") else None
    except OSError:
        return None


def url_formulario_qr():
    """URL de acceso al formulario público de desperdicio PAE (con token)."""
    token = obtener_token_qr_formulario()
    base_url = os.getenv("PUBLIC_BASE_URL", "").strip().rstrip("/")
    if base_url:
        destino = f"{base_url}{url_for('acceso_formulario', token=token)}"
    else:
        destino = url_for("acceso_formulario", token=token, _external=True)
        ip_lan = ip_red_local()
        # Un celular no puede abrir 127.0.0.1/localhost: se usa la IP del computador en la red.
        if ip_lan:
            destino = re.sub(r"//(127\.0\.0\.1|localhost)(:\d+)?", lambda m: f"//{ip_lan}{m.group(2) or ''}", destino, count=1)
    return destino


@app.get("/qr-estudiantes")
def qr_estudiantes():
    """Página que muestra el QR de acceso al formulario público."""
    return send_from_directory(PUBLIC_DIR, "qr_estudiantes.html")


@app.get("/api/qr-acceso")
def api_qr_acceso():
    """Devuelve la URL con token para que el cliente genere el código QR."""
    try:
        return jsonify({"ok": True, "url": url_formulario_qr()})
    except mysql.connector.Error:
        return error_db("Conecte MySQL para generar el acceso QR.")


@app.get("/acceso-formulario/<token>")
def acceso_formulario(token):
    """Valida en la base de datos el token escaneado y abre solo el formulario."""
    try:
        acceso = consultar(
            "SELECT id FROM accesos_qr WHERE token=%s AND nombre=%s AND activo=1",
            (token, "formulario_desperdicio_pae"),
            varios=False,
        )
        if not acceso:
            return "Este código QR ya no está activo.", 404
        ejecutar("UPDATE accesos_qr SET ultimo_uso=CURRENT_TIMESTAMP WHERE id=%s", (acceso["id"],))
        return redirect(url_for("desperdicio_pae"))
    except mysql.connector.Error:
        return "No fue posible validar el código QR en la base de datos.", 503


@app.get("/desperdicio-pae")
def desperdicio_pae():
    return send_from_directory(PUBLIC_DIR, "desperdicio_pae.html")


@app.post("/api/formulario-desperdicio-pae")
def guardar_desperdicio_pae():
    """Guarda un pesaje PAE enviado desde el formulario público del QR."""
    datos = request.get_json(silent=True) or request.form
    grado = str(datos.get("grado") or "").strip()
    try:
        peso = float(datos.get("peso_sobrante_gramos") or 0)
    except (TypeError, ValueError):
        return jsonify({"ok": False, "error": "Ingrese un gramaje válido."}), 400
    tipo = "PAE"  # este formulario registra únicamente desperdicio del PAE
    if grado not in GRADOS_PERMITIDOS:
        return jsonify({"ok": False, "error": "Seleccione un grado válido."}), 400
    if peso <= 0:
        return jsonify({"ok": False, "error": "El gramaje debe ser mayor que cero."}), 400
    try:
        asegurar_columna_residuos_alimentacion()
        fecha = fecha_solicitada(datos.get("fecha"))
        nota = str(datos.get("nota") or "").strip()
        uid = str(datos.get("uid") or "").strip()[:40] or None
        if uid and consultar("SELECT id FROM residuos WHERE uid_envio=%s", (uid,), varios=False):
            # Reintento de un envío que ya se guardó: se confirma sin duplicar el pesaje.
            return jsonify({"ok": True, "duplicado": True, "data": {"fecha": str(fecha), "grado": grado, "peso": peso, "tipo": tipo}}), 200
        ejecutar("INSERT INTO residuos (fecha, peso_sobrante_gramos, grupo_tipo, tipo_alimentacion, grado, nota, uid_envio) VALUES (%s,%s,%s,%s,%s,%s,%s)", (fecha, peso, "individual", tipo, grado, nota or None, uid))
        return jsonify({"ok": True, "data": {"fecha": str(fecha), "grado": grado, "peso": peso, "tipo": tipo}}), 201
    except mysql.connector.Error as error:
        app.logger.exception("Error guardando desperdicio PAE")
        return error_db("No fue posible guardar el desperdicio PAE en MySQL. Revise que MySQL esté activo y que la base almuerzos_conscientes exista.")
    except Exception:
        app.logger.exception("Error inesperado guardando desperdicio PAE")
        return jsonify({"ok": False, "error": "Ocurrió un error al guardar el desperdicio. Revise la conexión y vuelva a intentar."}), 500


@app.get("/api/formulario-desperdicio-pae/resumen")
def resumen_desperdicio_formulario():
    """Totales de desperdicio por grado y servicio para la fecha indicada (público, solo agregados)."""
    try:
        asegurar_columna_residuos_alimentacion()
        filas = consultar(
            "SELECT grado, tipo_alimentacion, SUM(peso_sobrante_gramos) AS peso, GROUP_CONCAT(NULLIF(nota,'') SEPARATOR ' · ') AS notas "
            "FROM residuos WHERE fecha=%s AND tipo_alimentacion='PAE' AND grado IS NOT NULL GROUP BY grado, tipo_alimentacion",
            (fecha_solicitada(request.args.get("fecha")),),
        )
        return jsonify({"ok": True, "data": [{"grado": f["grado"], "tipo": f["tipo_alimentacion"], "peso": float(f["peso"] or 0), "notas": f["notas"] or ""} for f in filas]})
    except mysql.connector.Error:
        return error_db()


@app.route("/estudiantes")
@app.route("/estudiante.html")
@app.route("/estudiantes.html")
def estudiantes():
    try:
        asegurar_columna_alimentacion()
        archivar_consumos_vencidos()
    except mysql.connector.Error:
        flash("MySQL no está disponible. Puedes usar el modo local del panel y luego importar la base de datos.", "error")
    return send_from_directory(PUBLIC_DIR, "estudiantes.html")


@app.post("/estudiantes/guardar")
def guardar_estudiante():
    """Registra el consumo buscando al estudiante por su documento numérico."""
    documento = str(request.form.get("documento", "")).strip()
    tipo_consumo = request.form.get("tipo_consumo", "").strip()
    if not re.fullmatch(r"\d+", documento) or tipo_consumo not in TIPOS_CONSUMO:
        flash("El documento debe contener solo números y debe seleccionar Vaso de leche o Almuerzo.", "error")
        return redirect(url_for("estudiantes"))
    try:
        asegurar_columna_alimentacion(); asegurar_columna_consumo()
        existente = consultar("SELECT id, nombre, documento, grado FROM estudiantes WHERE documento=%s", (documento,), varios=False)
        if not existente:
            flash("Ese número de documento no está registrado en el padrón de su grado.", "error")
            return redirect(url_for("estudiantes"))
        fecha = date.today(); ahora = datetime.now()
        previo = consultar("SELECT id FROM asistencia WHERE estudiante_id=%s AND fecha=%s", (existente["id"], fecha), varios=False)
        if previo:
            ejecutar("UPDATE asistencia SET tipo_consumo=%s, hora=%s, consumido_en=NOW(), metodo_registro='formulario' WHERE id=%s", (tipo_consumo, ahora.strftime("%H:%M:%S"), previo["id"]))
        else:
            ejecutar("INSERT INTO asistencia (estudiante_id, fecha, hora, tipo_consumo, consumido_en, metodo_registro) VALUES (%s,%s,%s,%s,%s,%s)", (existente["id"], fecha, ahora.strftime("%H:%M:%S"), tipo_consumo, ahora, "formulario"))
        flash(f"{existente['nombre']} ({existente['grado']}) se registró exitosamente: {ETIQUETAS_CONSUMO[tipo_consumo]}.", "ok")
    except mysql.connector.Error:
        flash("No fue posible guardar el registro en MySQL.", "error")
    return redirect(url_for("estudiantes"))


@app.post("/estudiantes/eliminar/<int:id_estudiante>")
def eliminar_estudiante(id_estudiante):
    try:
        ejecutar("DELETE FROM estudiantes WHERE id=%s", (id_estudiante,))
        flash("Estudiante eliminado correctamente.", "ok")
    except mysql.connector.IntegrityError:
        flash("No se puede eliminar un estudiante con asistencias registradas.", "error")
    except mysql.connector.Error:
        flash("No fue posible eliminar el estudiante.", "error")
    return redirect(url_for("estudiantes"))


@app.post("/estudiantes/registrar-consumo")
def registrar_consumo_formulario():
    """Registra manualmente el consumo desde el formulario de estudiantes."""
    documento = request.form.get("documento_consumo", "").strip()
    fecha = fecha_solicitada(request.form.get("fecha_consumo"))
    hora = request.form.get("hora_consumo", "").strip() or datetime.now().strftime("%H:%M")
    if not documento or fecha.weekday() > 4:
        flash("Seleccione un estudiante y una fecha de lunes a viernes.", "error")
        return redirect(url_for("estudiantes"))
    try:
        archivar_consumos_vencidos()
        conexion = obtener_conexion()
        cursor = conexion.cursor(dictionary=True)
        cursor.execute("SELECT id, nombre FROM estudiantes WHERE documento=%s", (documento,))
        estudiante = cursor.fetchone()
        if not estudiante:
            flash("El documento no corresponde a un estudiante registrado.", "error")
            return redirect(url_for("estudiantes"))
        cursor.execute(
            "SELECT id FROM asistencia WHERE estudiante_id=%s AND fecha=%s",
            (estudiante["id"], fecha),
        )
        if cursor.fetchone():
            flash("Ese estudiante ya aparece en los registros activos de esa fecha.", "error")
            return redirect(url_for("estudiantes"))
        cursor.execute(
            "SELECT id FROM historial_consumo WHERE estudiante_id=%s AND fecha_consumo=%s",
            (estudiante["id"], fecha),
        )
        if cursor.fetchone():
            flash("Ese estudiante ya está en el historial de esa fecha.", "error")
            return redirect(url_for("estudiantes"))
        cursor.execute(
            "INSERT INTO asistencia (estudiante_id, fecha, hora, tipo_consumo, consumido_en, metodo_registro) VALUES (%s,%s,%s,%s,%s,%s)",
            (estudiante["id"], fecha, hora, "almuerzo", datetime.combine(fecha, datetime.strptime(hora, "%H:%M").time()), "formulario"),
        )
        conexion.commit()
        flash(f"Consumo registrado para {estudiante['nombre']}.", "ok")
    except (mysql.connector.Error, ValueError):
        flash("No fue posible registrar el consumo. Revise la fecha y la hora.", "error")
    finally:
        try:
            cursor.close()
            conexion.close()
        except UnboundLocalError:
            pass
    return redirect(url_for("estudiantes"))


@app.get("/api/estado-estudiante/<documento>")
def api_estado_estudiante(documento):
    """Consulta el estado de hoy usando el documento como llave principal.
    Se usa para que el semáforo del panel no dependa del estado local del navegador."""
    documento = str(documento or "").strip()
    if not re.fullmatch(r"\d+", documento):
        return jsonify({"ok": False, "error": "Documento inválido."}), 400
    fecha = fecha_solicitada(request.args.get("fecha"))
    try:
        asegurar_columna_alimentacion()
        asegurar_columna_consumo()
        estudiante = consultar(
            "SELECT id, nombre, documento, grado FROM estudiantes WHERE documento=%s",
            (documento,), varios=False
        )
        if not estudiante:
            return jsonify({"ok": False, "error": "Estudiante no encontrado."}), 404
        registro = consultar(
            "SELECT tipo_consumo, hora FROM asistencia WHERE estudiante_id=%s AND fecha=%s ORDER BY id DESC LIMIT 1",
            (estudiante["id"], fecha), varios=False
        )
        return jsonify({
            "ok": True,
            "data": {
                **estudiante,
                "fecha": str(fecha),
                "tipo_consumo": (registro or {}).get("tipo_consumo", "ninguno"),
                "hora": (registro or {}).get("hora"),
                "estado": "consumio" if registro and registro.get("tipo_consumo") != "ninguno" else "no_consumio"
            }
        })
    except mysql.connector.Error:
        return error_db("No fue posible consultar el estado del estudiante.")

@app.get("/api/control-estudiantes")
def api_control_estudiantes():
    """Devuelve todo el padrón para una fecha, incluyendo no consumió."""
    fecha = fecha_solicitada(request.args.get("fecha"))
    try:
        asegurar_columna_alimentacion(); asegurar_columna_consumo(); archivar_consumos_vencidos()
        registros = consultar(
            """SELECT e.id, e.nombre, e.documento, e.grado, DATE(%s) AS fecha,
                      COALESCE(a.tipo_consumo, 'ninguno') AS tipo_consumo,
                      a.hora AS hora,
                      CASE WHEN a.estudiante_id IS NULL OR a.tipo_consumo='ninguno' THEN 'no_consumio' ELSE 'consumio' END AS estado
               FROM estudiantes e
               LEFT JOIN (
                    SELECT x.estudiante_id, x.fecha, x.tipo_consumo, x.hora
                    FROM asistencia x
                    INNER JOIN (
                        SELECT estudiante_id, fecha, MAX(id) AS max_id
                        FROM asistencia
                        WHERE fecha=%s
                        GROUP BY estudiante_id, fecha
                    ) ult ON ult.max_id=x.id
                    UNION ALL
                    SELECT h.estudiante_id, h.fecha_consumo AS fecha, h.tipo_consumo, h.hora_consumo AS hora
                    FROM historial_consumo h
                    WHERE h.fecha_consumo=%s
                      AND NOT EXISTS (
                          SELECT 1 FROM asistencia a2
                          WHERE a2.estudiante_id=h.estudiante_id
                            AND a2.fecha=h.fecha_consumo
                      )
               ) a ON a.estudiante_id=e.id AND a.fecha=%s
               ORDER BY e.nombre""",
            (fecha, fecha, fecha, fecha),
        )
        return jsonify({"ok": True, "data": registros})
    except mysql.connector.Error:
        return error_db("No fue posible consultar el control de estudiantes.")


@app.get("/api/estudiantes")
def api_estudiantes():
    try:
        asegurar_columna_alimentacion()
        asegurar_columna_consumo()
        archivar_consumos_vencidos()
        return jsonify({"ok": True, "data": consultar(
            """SELECT e.id, e.nombre, e.documento, e.grado, e.fecha_registro,
                      COALESCE(a.tipo_consumo, 'ninguno') AS tipo_consumo,
                      a.fecha AS fecha_consumo, a.hora AS hora_consumo
               FROM estudiantes e LEFT JOIN asistencia a
                 ON a.estudiante_id=e.id AND a.fecha=CURDATE()
               ORDER BY e.nombre"""
        )})
    except mysql.connector.Error:
        return error_db()


@app.post("/api/registro-estudiantes")
def api_registrar_estudiante_maestro():
    datos = request.get_json(silent=True) or {}
    nombre = " ".join(str(datos.get("nombre", "")).strip().split())
    documento = str(datos.get("documento", "")).strip()
    grado = str(datos.get("grado", "")).strip()
    tipo_alimentacion = str(datos.get("tipo_alimentacion", "PAE")).strip()
    if (not nombre or not re.fullmatch(r"[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+", nombre)
            or not re.fullmatch(r"\d+", documento) or not validar_grado_alimentacion(grado, tipo_alimentacion)):
        return jsonify({"ok": False, "error": "Ingrese nombre solo con letras, documento solo con números y un grado compatible con el servicio seleccionado."}), 400
    try:
        asegurar_columna_alimentacion()
        existente = consultar("SELECT id FROM estudiantes WHERE documento=%s", (documento,), varios=False)
        if existente:
            return jsonify({"ok": False, "error": "El documento ya está en Registro de estudiantes."}), 409
        sid = ejecutar("INSERT INTO estudiantes (nombre, documento, edad, grado, tipo_alimentacion, codigo_qr) VALUES (%s,%s,%s,%s,%s,%s)", (nombre, documento, 10, grado, tipo_alimentacion, crear_codigo_qr()))
        return jsonify({"ok": True, "data": {"id": sid, "nombre": nombre, "documento": documento, "grado": grado, "tipo_alimentacion": tipo_alimentacion, "tipo_consumo": "ninguno"}}), 201
    except mysql.connector.IntegrityError:
        return jsonify({"ok": False, "error": "El documento ya está registrado."}), 409
    except mysql.connector.Error:
        return error_db("No fue posible guardar el estudiante en MySQL.")


@app.post("/api/estudiantes")
def api_crear_estudiante():
    """Registra consumo SOLO si el nombre ya existe en el padrón de estudiantes."""
    datos = request.get_json(silent=True) or {}
    nombre = " ".join(str(datos.get("nombre", "")).strip().split())
    documento = str(datos.get("documento", "")).strip()
    tipo_consumo = str(datos.get("tipo_consumo", "")).strip()
    if (documento and not re.fullmatch(r"\d+", documento)) or (not documento and (not nombre or not re.fullmatch(r"[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+", nombre))) or tipo_consumo not in TIPOS_CONSUMO:
        return jsonify({"ok": False, "error": "Ingrese un nombre válido y seleccione Vaso de leche o Almuerzo."}), 400
    try:
        asegurar_columna_alimentacion(); asegurar_columna_consumo()
        # IMPORTANTE: nunca crear un estudiante desde este formulario.
        # La única fuente válida es Registro de estudiantes del index.html.
        existente = consultar(
            "SELECT id, nombre, documento, grado, codigo_qr FROM estudiantes "
            "WHERE documento=%s OR LOWER(TRIM(nombre))=LOWER(TRIM(%s))",
            (documento, nombre), varios=False
        )
        if not existente:
            return jsonify({
                "ok": False,
                "error": "Este nombre no existe en Registro de estudiantes. No se realizó ningún registro."
            }), 404

        fecha = date.today()
        ahora = datetime.now()
        previo = consultar(
            "SELECT id FROM asistencia WHERE estudiante_id=%s AND fecha=%s",
            (existente["id"], fecha), varios=False
        )
        if previo:
            ejecutar(
                "UPDATE asistencia SET tipo_consumo=%s, hora=%s, consumido_en=%s, metodo_registro='formulario' WHERE id=%s",
                (tipo_consumo, ahora.strftime("%H:%M:%S"), ahora, previo["id"])
            )
        else:
            ejecutar(
                "INSERT INTO asistencia (estudiante_id, fecha, hora, tipo_consumo, consumido_en, metodo_registro) "
                "VALUES (%s,%s,%s,%s,%s,%s)",
                (existente["id"], fecha, ahora.strftime("%H:%M:%S"), tipo_consumo, ahora, "formulario")
            )

        return jsonify({
            "ok": True,
            "mensaje": f"{existente['nombre']} se registró exitosamente.",
            "data": {**existente, "tipo_consumo": tipo_consumo, "fecha": str(fecha), "hora": ahora.strftime("%H:%M:%S")}
        }), 200
    except mysql.connector.Error:
        return error_db("No fue posible conectar con el Registro de estudiantes en MySQL.")


@app.delete("/api/registro-estudiantes/<int:id_estudiante>")
def api_eliminar_estudiante(id_estudiante):
    try:
        ejecutar("DELETE FROM estudiantes WHERE id=%s", (id_estudiante,))
        return jsonify({"ok": True})
    except mysql.connector.IntegrityError:
        return jsonify({"ok": False, "error": "No se puede borrar: el estudiante tiene registros de consumo asociados."}), 409
    except mysql.connector.Error:
        return error_db("No fue posible borrar el estudiante en la base de datos.")


@app.put("/api/estudiantes/<int:id_estudiante>")
def api_editar_estudiante(id_estudiante):
    asegurar_columna_alimentacion()
    datos = request.get_json(silent=True) or {}
    nombre = str(datos.get("nombre", "")).strip()
    documento = str(datos.get("documento", "")).strip()
    edad = datos.get("edad", 10) or 10
    grado = str(datos.get("grado", "")).strip()
    tipo_alimentacion = str(datos.get("tipo_alimentacion", "PAE")).strip()
    valido, nombre, documento, edad, tipo_alimentacion = validar_estudiante(nombre, documento, edad, grado, tipo_alimentacion)
    if not valido:
        return jsonify({"ok": False, "error": "Nombre solo con letras, documento numérico, edad entre 3 y 20 y un grado válido (5º, 7º, 8º, 9º, 10º u 11º, carpeta 1, 2 o 3)."}), 400
    try:
        duplicado = consultar("SELECT id FROM estudiantes WHERE (documento=%s OR LOWER(TRIM(nombre))=LOWER(TRIM(%s))) AND id<>%s", (documento, nombre, id_estudiante), varios=False)
        if duplicado:
            return jsonify({"ok": False, "error": "El documento o el nombre ya pertenece a otro estudiante."}), 409
        ejecutar("UPDATE estudiantes SET nombre=%s, documento=%s, edad=%s, grado=%s, tipo_alimentacion=%s WHERE id=%s", (nombre, documento, edad, grado, tipo_alimentacion, id_estudiante))
        return jsonify({"ok": True, "data": {"id": id_estudiante, "nombre": nombre, "documento": documento, "grado": grado}})
    except mysql.connector.IntegrityError:
        return jsonify({"ok": False, "error": "El documento ya está registrado."}), 409
    except mysql.connector.Error:
        return error_db("No fue posible actualizar el estudiante en MySQL.")


def asegurar_columna_menu_gramajes():
    """Agrega gramajes y grupo individual a bases existentes."""
    conexion = obtener_conexion()
    cursor = conexion.cursor()
    try:
        cursor.execute("ALTER TABLE menu_diario ADD COLUMN IF NOT EXISTS gramajes_grupo JSON NULL AFTER gramaje_total")
        cursor.execute("ALTER TABLE menu_componentes ADD COLUMN IF NOT EXISTS grado VARCHAR(20) NULL AFTER gramos")
        cursor.execute("ALTER TABLE menu_componentes ADD COLUMN IF NOT EXISTS unidad VARCHAR(5) NOT NULL DEFAULT 'g' AFTER gramos")
        try:
            cursor.execute("ALTER TABLE menu_componentes ADD KEY idx_menu_componentes_grado (grado)")
        except mysql.connector.Error:
            pass
        conexion.commit()
    finally:
        cursor.close()
        conexion.close()


@app.get("/api/menu")
def api_obtener_menu():
    fecha = fecha_solicitada(request.args.get("fecha"))
    try:
        asegurar_columna_menu_gramajes()
        menu = consultar(
            "SELECT id, fecha, nombre_menu, tipo_racion, gramaje_total, gramajes_grupo, ventana_inicio, ventana_fin, registro_habilitado, notas FROM menu_diario WHERE fecha=%s",
            (fecha,), varios=False,
        )
        if not menu:
            return jsonify({"ok": True, "data": None})
        if isinstance(menu.get("gramajes_grupo"), str):
            try:
                menu["gramajes_grupo"] = json.loads(menu["gramajes_grupo"])
            except (TypeError, ValueError):
                menu["gramajes_grupo"] = {}
        menu["componentes"] = consultar(
            "SELECT componente, alimento, gramos, unidad, grado, es_opcional FROM menu_componentes WHERE menu_id=%s ORDER BY id",
            (menu["id"],),
        )
        return jsonify({"ok": True, "data": menu})
    except mysql.connector.Error:
        return error_db()


@app.post("/api/menu")
def api_guardar_menu():
    datos = request.get_json(silent=True) or {}
    try:
        asegurar_columna_menu_gramajes()
        fecha_valida = fecha_solicitada(datos.get("fecha"))
        nombre_menu = str(datos.get("nombre_menu", "")).strip()
        tipo_racion = str(datos.get("tipo_racion", "caliente")).strip()
        gramaje = float(datos.get("gramaje_total") or 0)
        inicio = str(datos.get("ventana_inicio") or "09:30")[:5]
        fin = str(datos.get("ventana_fin") or "13:00")[:5]
        habilitado = 1 if datos.get("registro_habilitado", True) else 0
        if fecha_valida.weekday() > 4 or not nombre_menu or gramaje <= 0:
            raise ValueError
    except (TypeError, ValueError):
        return jsonify({"ok": False, "error": "El menú debe corresponder a un día hábil (lunes a viernes), con nombre y gramaje válido."}), 400
    reemplazar_componentes = "componentes" in datos  # ahora se registran en "Registro de proteínas y carbohidratos del almuerzo"
    componentes = datos.get("componentes") or []
    conexion = None
    cursor = None
    try:
        conexion = obtener_conexion()
        cursor = conexion.cursor()
        cursor.execute(
            """INSERT INTO menu_diario
               (fecha, nombre_menu, tipo_racion, gramaje_total, gramajes_grupo, ventana_inicio, ventana_fin, registro_habilitado, notas)
               VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)
               ON DUPLICATE KEY UPDATE nombre_menu=VALUES(nombre_menu), tipo_racion=VALUES(tipo_racion), gramaje_total=VALUES(gramaje_total), gramajes_grupo=VALUES(gramajes_grupo), ventana_inicio=VALUES(ventana_inicio), ventana_fin=VALUES(ventana_fin), registro_habilitado=VALUES(registro_habilitado), notas=VALUES(notas)""",
            (fecha_valida, nombre_menu, tipo_racion, gramaje, json.dumps(datos.get("gramajes_grupo") or {}), inicio, fin, habilitado, str(datos.get("notas", "")).strip()),
        )
        cursor.execute("SELECT id FROM menu_diario WHERE fecha=%s", (fecha_valida,))
        menu_id = cursor.fetchone()[0]
        if reemplazar_componentes:
            cursor.execute("DELETE FROM menu_componentes WHERE menu_id=%s", (menu_id,))
        filas = []
        for componente in componentes:
            alimento = str(componente.get("alimento", "")).strip()
            categoria = str(componente.get("componente", "")).strip() or "otros"
            gramos = float(componente.get("gramos") or 0)  # cantidad: gramos, o litros si es bebida
            unidad = "L" if categoria.lower() == "bebida" or str(componente.get("unidad") or "").upper() == "L" else "g"
            grado = str(componente.get("grado") or "").strip() or None
            if grado and grado not in GRADOS_ALMUERZO:
                return jsonify({"ok": False, "error": "Las proteínas y componentes del menú solo pueden asociarse a grados de almuerzo (quinto, décimo y once)."}), 400
            if alimento and gramos >= 0:
                filas.append((menu_id, categoria, alimento, gramos, unidad, grado, 1 if componente.get("es_opcional") else 0))
        if filas:
            cursor.executemany("INSERT INTO menu_componentes (menu_id, componente, alimento, gramos, unidad, grado, es_opcional) VALUES (%s,%s,%s,%s,%s,%s,%s)", filas)
        conexion.commit()
        return jsonify({"ok": True, "mensaje": "Menú guardado correctamente.", "id": menu_id})
    except (mysql.connector.Error, ValueError):
        if conexion:
            conexion.rollback()
        return error_db("No fue posible guardar el menú. Verifique la base de datos y los gramajes.")
    finally:
        if cursor:
            cursor.close()
        if conexion:
            conexion.close()


@app.post("/api/consumos")
def api_registrar_consumo():
    datos = request.get_json(silent=True) or {}
    codigo = str(datos.get("codigo") or datos.get("documento") or "").strip()
    fecha = fecha_solicitada(datos.get("fecha"))
    if not codigo:
        return jsonify({"ok": False, "error": "Ingrese el documento o escanee el código QR."}), 400
    if fecha.weekday() > 4:
        return jsonify({"ok": False, "error": "El registro de consumo está disponible de lunes a viernes."}), 400
    try:
        asegurar_columna_residuos_alimentacion()
        asegurar_columna_consumo()
        archivar_consumos_vencidos()
        menu = consultar("SELECT * FROM menu_diario WHERE fecha=%s", (fecha,), varios=False)
        if not menu:
            return jsonify({"ok": False, "error": "No hay un menú configurado para esta fecha."}), 409
        if not menu["registro_habilitado"]:
            return jsonify({"ok": False, "error": "El registro de consumo está cerrado por el docente."}), 403
        hora_actual = datetime.now().strftime("%H:%M")
        if not (str(menu["ventana_inicio"])[:5] <= hora_actual <= str(menu["ventana_fin"])[:5]):
            return jsonify({"ok": False, "error": f"El registro solo está habilitado entre {str(menu['ventana_inicio'])[:5]} y {str(menu['ventana_fin'])[:5]}."}), 403
        estudiante = consultar(
            "SELECT id, nombre, documento, grado, tipo_alimentacion, codigo_qr FROM estudiantes WHERE documento=%s OR codigo_qr=%s",
            (codigo, codigo), varios=False,
        )
        if not estudiante:
            return jsonify({"ok": False, "error": "No encontramos un estudiante con ese documento o código QR."}), 404
        previo = consultar("SELECT id FROM asistencia WHERE estudiante_id=%s AND fecha=%s", (estudiante["id"], fecha), varios=False)
        if previo:
            return jsonify({"ok": False, "error": f"{estudiante['nombre']} ya confirmó su consumo hoy."}), 409
        ejecutar("INSERT INTO asistencia (estudiante_id, fecha, hora, tipo_consumo, consumido_en, metodo_registro) VALUES (%s,%s,%s,%s,%s,%s)", (estudiante["id"], fecha, datetime.now().time(), str(datos.get("tipo_consumo", "almuerzo")), datetime.now(), str(datos.get("metodo", "documento"))))
        return jsonify({"ok": True, "mensaje": f"Consumo confirmado para {estudiante['nombre']}.", "data": estudiante})
    except mysql.connector.Error:
        return error_db("No fue posible registrar el consumo en MySQL.")


@app.get("/api/consumos")
def api_obtener_consumos():
    fecha = fecha_solicitada(request.args.get("fecha"))
    try:
        archivar_consumos_vencidos()
        registros = consultar(
            """SELECT a.fecha, a.hora, a.metodo_registro, e.nombre, e.documento,
                      e.grado, e.tipo_alimentacion, e.codigo_qr, a.tipo_consumo
               FROM asistencia a JOIN estudiantes e ON e.id=a.estudiante_id
               WHERE a.fecha=%s ORDER BY a.hora""",
            (fecha,),
        )
        return jsonify({"ok": True, "data": registros})
    except mysql.connector.Error:
        return error_db()


@app.get("/api/historial-consumos")
def api_historial_consumos():
    try:
        archivar_consumos_vencidos()
        desde = request.args.get("desde")
        parametros = ()
        filtro = ""
        if desde:
            filtro = " WHERE fecha_consumo >= %s"
            parametros = (fecha_solicitada(desde),)
        registros = consultar(
            f"""SELECT id, nombre_estudiante, documento, grado, fecha_consumo,
                       hora_consumo, tipo_consumo, consumido_en, archivado_en, metodo_registro
                FROM historial_consumo{filtro}
                ORDER BY consumido_en DESC LIMIT 1000""",
            parametros,
        )
        return jsonify({"ok": True, "data": registros})
    except mysql.connector.Error:
        return error_db()


@app.get("/api/residuos")
def api_obtener_residuos():
    fecha = fecha_solicitada(request.args.get("fecha"))
    try:
        asegurar_columna_residuos_alimentacion()
        registros = consultar(
            "SELECT fecha, peso_sobrante_gramos AS peso, grupo_tipo AS grupo, tipo_alimentacion, grado, nota FROM residuos WHERE fecha=%s ORDER BY id DESC",
            (fecha,),
        )
        return jsonify({"ok": True, "data": registros})
    except mysql.connector.Error:
        return error_db()


@app.post("/api/residuos")
def api_guardar_residuo():
    datos = request.get_json(silent=True) or {}
    try:
        asegurar_columna_residuos_alimentacion()
        fecha = fecha_solicitada(datos.get("fecha"))
        peso = float(datos.get("peso_sobrante_gramos") or 0)
        tipo_alimentacion = str(datos.get("tipo_alimentacion") or "PAE").strip()
        grado = str(datos.get("grado") or "").strip() or None
        if tipo_alimentacion not in ("PAE", "almuerzo"):
            return jsonify({"ok": False, "error": "Seleccione si el desperdicio corresponde al PAE o al almuerzo."}), 400
        if grado and grado not in GRADOS_PERMITIDOS:
            return jsonify({"ok": False, "error": "El grado no corresponde al tipo de alimentación seleccionado."}), 400
        if peso < 0:
            raise ValueError
    except (TypeError, ValueError):
        return jsonify({"ok": False, "error": "Ingrese un peso de desperdicio válido."}), 400
    try:
        ejecutar(
            "INSERT INTO residuos (fecha, peso_sobrante_gramos, grupo_tipo, tipo_alimentacion, grado, nota) VALUES (%s,%s,%s,%s,%s,%s)",
            (fecha, peso, str(datos.get("grupo_tipo") or "general"), tipo_alimentacion, grado, str(datos.get("nota") or "").strip()),
        )
        return jsonify({"ok": True, "mensaje": "Peso de desperdicio registrado."})
    except mysql.connector.Error:
        return error_db("No fue posible registrar el desperdicio en MySQL.")


COMPONENTES_PAE_VALIDOS = {"proteína", "carbohidrato", "legumbre", "ensalada", "fruta", "bebida", "otros"}


def normalizar_componentes_pae(lista, porcentaje):
    """Valida el detalle servido/entregado. Bebidas en litros, el resto en gramos.
    El entregado se calcula siempre en el servidor: servido x porcentaje entregado."""
    resultado = []
    for item in (lista if isinstance(lista, list) else []):
        if not isinstance(item, dict):
            continue
        tipo = str(item.get("componente") or "otros").strip().lower()
        if tipo not in COMPONENTES_PAE_VALIDOS:
            tipo = "otros"
        alimento = str(item.get("alimento") or "").strip()[:120]
        servido = float(item.get("servido") or 0)
        if not alimento or servido < 0:
            raise ValueError("Cada componente necesita nombre y una cantidad válida.")
        grado = str(item.get("grado") or "").strip()
        if grado and grado not in GRADOS_ALMUERZO:
            raise ValueError("El grado del componente debe ser de almuerzo.")
        resultado.append({
            "componente": tipo,
            "grado": grado,
            "alimento": alimento,
            "unidad": "L" if tipo == "bebida" else "g",
            "servido": round(servido, 3),
            "entregado": round(servido * porcentaje / 100, 3),
        })
    return resultado


def entrega_pae_a_dict(registro):
    if not registro:
        return None
    crudo = registro.get("componentes")
    try:
        componentes = json.loads(crudo) if isinstance(crudo, str) and crudo else (crudo or [])
    except (TypeError, ValueError):
        componentes = []
    registro["componentes"] = componentes
    return registro


def espejo_componentes_menu(fecha, componentes):
    """Mantiene menu_componentes igual al registro de proteínas y carbohidratos del almuerzo."""
    asegurar_columna_menu_gramajes()
    menu = consultar("SELECT id FROM menu_diario WHERE fecha=%s", (fecha,), varios=False)
    if not menu:
        return
    ejecutar("DELETE FROM menu_componentes WHERE menu_id=%s", (menu["id"],))
    filas = [(menu["id"], c["componente"], c["alimento"], c["servido"], c["unidad"], c.get("grado") or None, 0) for c in componentes]
    if filas:
        ejecutar("INSERT INTO menu_componentes (menu_id, componente, alimento, gramos, unidad, grado, es_opcional) VALUES (%s,%s,%s,%s,%s,%s,%s)", muchos=filas)


@app.get("/api/pae-entrega")
def api_obtener_entrega_pae():
    fecha = fecha_solicitada(request.args.get("fecha"))
    try:
        asegurar_tabla_entregas_pae()
        registro = consultar("SELECT id, fecha, porcentaje_entregado, porcentaje_disponible, observacion, componentes FROM pae_entregas WHERE fecha=%s", (fecha,), varios=False)
        return jsonify({"ok": True, "data": entrega_pae_a_dict(registro)})
    except mysql.connector.Error:
        return error_db("No fue posible consultar la entrega del PAE.")

@app.post("/api/pae-entrega")
def api_guardar_entrega_pae():
    datos = request.get_json(silent=True) or {}
    try:
        fecha = fecha_solicitada(datos.get("fecha"))
        entregado = float(datos.get("porcentaje_entregado") or 0)
        disponible = float(datos.get("porcentaje_disponible") or 0)
    except (TypeError, ValueError):
        return jsonify({"ok": False, "error": "Ingrese una fecha y porcentajes válidos."}), 400
    if not 0 <= entregado <= 100 or not 0 <= disponible <= 100:
        return jsonify({"ok": False, "error": "Los porcentajes deben estar entre 0 y 100."}), 400
    try:
        componentes = normalizar_componentes_pae(datos.get("componentes"), entregado)
    except (TypeError, ValueError):
        return jsonify({"ok": False, "error": "Revise los componentes: cada uno necesita alimento y una cantidad válida."}), 400
    observacion = str(datos.get("observacion") or "").strip()
    try:
        asegurar_tabla_entregas_pae()
        ejecutar("""INSERT INTO pae_entregas (fecha, porcentaje_entregado, porcentaje_disponible, observacion, componentes)
                   VALUES (%s,%s,%s,%s,%s)
                   ON DUPLICATE KEY UPDATE porcentaje_entregado=VALUES(porcentaje_entregado), porcentaje_disponible=VALUES(porcentaje_disponible), observacion=VALUES(observacion), componentes=VALUES(componentes)""", (fecha, entregado, disponible, observacion or None, json.dumps(componentes, ensure_ascii=False)))
        try:
            espejo_componentes_menu(fecha, componentes)
        except mysql.connector.Error:
            pass  # el registro principal ya quedó guardado
        return jsonify({"ok": True, "data": {"fecha": str(fecha), "porcentaje_entregado": entregado, "porcentaje_disponible": disponible, "observacion": observacion, "componentes": componentes}})
    except mysql.connector.Error:
        return error_db("No fue posible guardar el registro del almuerzo en MySQL.")

def entrega_grupos_a_dict(fecha, dia, filas):
    pct = float(dia["porcentaje_entregado"]) if dia else 0.0
    grupos = []
    for f in filas:
        servido = float(f["gramos_servidos"] or 0)
        grupos.append({"grado": f["grado"], "servido": servido, "entregado": round(servido * pct / 100, 2)})
    return {
        "fecha": str(fecha),
        "porcentaje_entregado": pct,
        "gramaje_total": float(dia["gramaje_total"]) if dia and dia.get("gramaje_total") is not None else None,
        "observacion": (dia or {}).get("observacion") or "",
        "grupos": grupos,
    }


@app.get("/api/entrega-pae-grupos")
def api_obtener_entrega_pae_grupos():
    """Entrega del PAE del día: porcentaje, observación y gramaje por grupo."""
    fecha = fecha_solicitada(request.args.get("fecha"))
    try:
        asegurar_tablas_entrega_pae_grupos()
        dia = consultar("SELECT fecha, porcentaje_entregado, gramaje_total, observacion FROM pae_entrega_dia WHERE fecha=%s", (fecha,), varios=False)
        filas = consultar("SELECT grado, gramos_servidos FROM pae_entrega_grupos WHERE fecha=%s", (fecha,))
        if not dia and not filas:
            return jsonify({"ok": True, "data": None})
        return jsonify({"ok": True, "data": entrega_grupos_a_dict(fecha, dia, filas)})
    except mysql.connector.Error:
        return error_db("No fue posible consultar la entrega del PAE por grupo.")


@app.post("/api/entrega-pae-grupos")
def api_guardar_entrega_pae_grupos():
    datos = request.get_json(silent=True) or {}
    try:
        fecha = fecha_solicitada(datos.get("fecha"))
        porcentaje = float(datos.get("porcentaje_entregado") or 0)
        total = datos.get("gramaje_total")
        total = float(total) if total not in (None, "") else None
        grupos = datos.get("grupos") or {}
        if not isinstance(grupos, dict):
            raise ValueError
        limpios = {}
        for grado, gramos in grupos.items():
            gramos = float(gramos or 0)
            if grado not in GRADOS_ENTREGA_PAE or gramos < 0:
                raise ValueError
            limpios[grado] = gramos
    except (TypeError, ValueError):
        return jsonify({"ok": False, "error": "Revise el porcentaje y el gramaje de cada grupo (sexto 1 a once 3)."}), 400
    if not 0 <= porcentaje <= 100:
        return jsonify({"ok": False, "error": "El porcentaje entregado debe estar entre 0 y 100."}), 400
    observacion = str(datos.get("observacion") or "").strip()[:500]
    conexion = None
    cursor = None
    try:
        asegurar_tablas_entrega_pae_grupos()
        conexion = obtener_conexion()
        cursor = conexion.cursor()
        cursor.execute("""INSERT INTO pae_entrega_dia (fecha, porcentaje_entregado, gramaje_total, observacion) VALUES (%s,%s,%s,%s)
                          ON DUPLICATE KEY UPDATE porcentaje_entregado=VALUES(porcentaje_entregado), gramaje_total=VALUES(gramaje_total), observacion=VALUES(observacion)""",
                       (fecha, porcentaje, total, observacion or None))
        cursor.execute("DELETE FROM pae_entrega_grupos WHERE fecha=%s", (fecha,))
        filas = [(fecha, g, v) for g, v in limpios.items() if v > 0]
        if filas:
            cursor.executemany("INSERT INTO pae_entrega_grupos (fecha, grado, gramos_servidos) VALUES (%s,%s,%s)", filas)
        conexion.commit()
        dia = {"porcentaje_entregado": porcentaje, "gramaje_total": total, "observacion": observacion}
        return jsonify({"ok": True, "data": entrega_grupos_a_dict(fecha, dia, [{"grado": g, "gramos_servidos": v} for _, g, v in filas])})
    except mysql.connector.Error:
        if conexion:
            conexion.rollback()
        return error_db("No fue posible guardar la entrega del PAE por grupo en MySQL.")
    finally:
        if cursor:
            cursor.close()
        if conexion:
            conexion.close()


@app.get("/api/componentes-pae")
def api_obtener_componentes_pae():
    fecha = fecha_solicitada(request.args.get("fecha"))
    try:
        asegurar_tabla_componentes_pae()
        registros = consultar(
            "SELECT id, fecha, platillo, descripcion, item, gramos_consumidos, es_esencial, creado_en FROM componentes_pae WHERE fecha=%s ORDER BY es_esencial DESC, id DESC",
            (fecha,),
        )
        return jsonify({"ok": True, "data": registros})
    except mysql.connector.Error:
        return error_db("No fue posible consultar los componentes del PAE.")


@app.post("/api/componentes-pae")
def api_guardar_componente_pae():
    datos = request.get_json(silent=True) or {}
    platillo = str(datos.get("platillo") or "").strip()
    descripcion = str(datos.get("descripcion") or "").strip()
    item = str(datos.get("item") or "").strip()
    try:
        fecha = fecha_solicitada(datos.get("fecha"))
        gramos = float(datos.get("gramos_consumidos") or 0)
    except (TypeError, ValueError):
        return jsonify({"ok": False, "error": "Ingrese una fecha y un gramaje válido."}), 400
    if not platillo or not item or gramos < 0:
        return jsonify({"ok": False, "error": "Complete el platillo, el ítem y un gramaje válido."}), 400
    try:
        asegurar_tabla_componentes_pae()
        nuevo_id = ejecutar(
            "INSERT INTO componentes_pae (fecha, platillo, descripcion, item, gramos_consumidos, es_esencial) VALUES (%s,%s,%s,%s,%s,%s)",
            (fecha, platillo, descripcion or None, item, gramos, bool(datos.get("es_esencial", True))),
        )
        return jsonify({"ok": True, "mensaje": "Componente del PAE registrado.", "data": {"id": nuevo_id, "fecha": str(fecha), "platillo": platillo, "descripcion": descripcion, "item": item, "gramos_consumidos": gramos, "es_esencial": bool(datos.get("es_esencial", True))}}), 201
    except mysql.connector.Error:
        return error_db("No fue posible guardar el componente del PAE en MySQL.")


@app.get("/api/dashboard")
def api_dashboard():
    fecha = fecha_solicitada(request.args.get("fecha"))
    grado = request.args.get("grado", "").strip()
    try:
        asegurar_columna_residuos_alimentacion()
        archivar_consumos_vencidos()
        menu = consultar("SELECT * FROM menu_diario WHERE fecha=%s", (fecha,), varios=False)
        filtro = " AND e.grado=%s" if grado else ""
        params = (fecha, grado) if grado else (fecha,)
        consumos = consultar(
            f"""SELECT e.grado, e.tipo_alimentacion, COUNT(a.id) AS consumos
                FROM asistencia a JOIN estudiantes e ON e.id=a.estudiante_id
                WHERE a.fecha=%s{filtro} GROUP BY e.grado, e.tipo_alimentacion ORDER BY e.grado""", params
        )
        desperdicios = consultar("SELECT tipo_alimentacion, grado, SUM(peso_sobrante_gramos) AS total FROM residuos WHERE fecha=%s GROUP BY tipo_alimentacion, grado", (fecha,))
        waste = {}
        for row in desperdicios:
            waste[(row["tipo_alimentacion"], row["grado"] or "general")] = float(row["total"] or 0)
        gramaje = float(menu["gramaje_total"]) if menu else 0
        gramajes = {}
        if menu and menu.get("gramajes_grupo"):
            try: gramajes = json.loads(menu["gramajes_grupo"]) if isinstance(menu["gramajes_grupo"], str) else menu["gramajes_grupo"]
            except (TypeError, ValueError): gramajes = {}
        grupos = []
        for item in consumos:
            g = item["grado"]; tipo = item["tipo_alimentacion"]
            servido_grupo = int(item["consumos"]) * float(gramajes.get(g) or gramaje)
            desperdicio_grupo = waste.get((tipo, g), waste.get((tipo, "general"), 0))
            grupos.append({"grupo": g, "tipo_alimentacion": tipo, "consumos": int(item["consumos"]), "servido": servido_grupo, "desperdicio": desperdicio_grupo})
        total_consumos = sum(int(item["consumos"]) for item in consumos)
        servido = sum(item["servido"] for item in grupos)
        sobrante = sum(waste.values())
        return jsonify({"ok": True, "data": {"fecha": str(fecha), "menu": menu, "consumos": total_consumos, "servido": servido, "desperdicio": sobrante, "consumo_efectivo": max(servido - sobrante, 0), "grupos": grupos, "desperdicios_por_tipo_grado": [{"tipo_alimentacion": k[0], "grado": k[1], "desperdicio": v} for k, v in waste.items()]}})
    except mysql.connector.Error:
        return error_db()


@app.get("/api/reportes")
def api_reportes():
    desde = fecha_solicitada(request.args.get("desde"))
    hasta = fecha_solicitada(request.args.get("hasta"))
    grado = request.args.get("grado", "").strip()
    try:
        asegurar_columna_residuos_alimentacion()
        archivar_consumos_vencidos()
        filtro = " AND e.grado=%s" if grado else ""
        params = (desde, hasta, grado) if grado else (desde, hasta)
        filas = consultar(
            f"""SELECT a.fecha, e.grado, e.tipo_alimentacion, COUNT(a.id) AS consumos,
                       COALESCE(m.gramaje_total,0) AS gramaje,
                       COALESCE(JSON_UNQUOTE(JSON_EXTRACT(m.gramajes_grupo, CONCAT('$.', e.grado))), m.gramaje_total, 0) AS gramaje_grupo,
                       COALESCE((SELECT SUM(r.peso_sobrante_gramos) FROM residuos r WHERE r.fecha=a.fecha AND r.tipo_alimentacion=e.tipo_alimentacion AND (r.grado=e.grado OR r.grado IS NULL)),0) AS desperdicio
                FROM asistencia a JOIN estudiantes e ON e.id=a.estudiante_id
                LEFT JOIN menu_diario m ON m.fecha=a.fecha
                WHERE a.fecha BETWEEN %s AND %s{filtro}
                GROUP BY a.fecha, e.grado, e.tipo_alimentacion, m.gramaje_total, m.gramajes_grupo ORDER BY a.fecha DESC, e.grado""", params
        )
        for fila in filas:
            fila["gramaje"] = float(fila.get("gramaje_grupo") or fila.get("gramaje") or 0)
            fila["servido"] = float(fila["consumos"]) * fila["gramaje"]
        return jsonify({"ok": True, "data": filas})
    except mysql.connector.Error:
        return error_db()


ARCHIVOS_PUBLICOS = {"html", "css", "js", "png", "jpg", "jpeg", "svg", "ico", "webmanifest", "txt"}


@app.get("/<path:archivo>")
def archivos_publicos(archivo):
    """Sirve los archivos estáticos del frontend (páginas, imágenes y scripts)."""
    extension = archivo.rsplit(".", 1)[-1].lower() if "." in archivo else ""
    if extension not in ARCHIVOS_PUBLICOS:
        abort(404)
    return send_from_directory(PUBLIC_DIR, archivo)


def iniciar_archivador_24_horas():
    """Cierra y archiva consumos vencidos aunque no haya una petición web activa."""
    def ciclo():
        while True:
            try:
                archivar_consumos_vencidos()
            except Exception as error:
                print(f"[Historial 24h] pendiente: {error}")
            time.sleep(300)
    hilo = threading.Thread(target=ciclo, name="historial-24h", daemon=True)
    hilo.start()


if __name__ == "__main__":
    iniciar_archivador_24_horas()
    app.run(host="0.0.0.0", port=int(os.getenv("PORT", "5000")), debug=True, use_reloader=False)
