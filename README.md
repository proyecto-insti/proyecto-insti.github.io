# Almuerzos Conscientes 2026

Sistema web para la gestión del PAE (Programa de Alimentación Escolar): control de asistencia y consumo de estudiantes, menú diario con gramajes, seguimiento de la entrega del PAE y medición del desperdicio de alimentos a través de registros con formulario QR.

## Funcionalidades

- **Acceso institucional al panel** con sesiones (roles `administracion` y `docente`), contraseñas almacenadas en hash PBKDF2 en la tabla `usuarios`.
- **Padrón de estudiantes** con CRUD completo, validación de documento, grado y tipo de alimentación (PAE / almuerzo).
- **Registro de consumo** por QR, documento o formulario, con ventana horaria habilitada por el menú del día y validación de duplicados.
- **Control diario**: estado de cada estudiante (consumió / no consumió) y semáforo por grado.
- **Menú diario y gramajes**: componentes por ración (proteína, carbohidrato, legumbre, ensalada, fruta, bebida, postre), gramaje por grupo y ventana de registro.
- **Entrega del PAE**: porcentaje entregado por día y gramaje servido por grupo (sexto 1 a once 3).
- **Desperdicio PAE**: formulario público abierto por QR (`/qr-estudiantes`) que registra gramajes por grado con reintentos offline y anti-duplicados (`uid_envio`).
- **Historial automático**: un hilo de fondo archiva los consumos en `historial_consumo` pasadas 24 horas y marca como no consumido al padrón sin registro.
- **Dashboard y reportes** con consumos, servido estimado y desperdicio por fecha y grado.
- **Diagnóstico de conexión**: `GET /api/estado-bd` informa si la base responde y con qué parámetros.

## Stack tecnológico

| Capa | Tecnología |
|------|------------|
| Backend principal | Python 3 · Flask 3.1.2 · mysql-connector-python 9.4.0 |
| Base de datos | MySQL 8 / MariaDB 10.2+ (InnoDB) |
| Frontend | HTML/CSS/JS estático servido por Flask (`src/public`) |
| Módulo CRUD (opcional) | PHP 8 + PDO (`estudiantes/index.php`, `config/db.php`) — pensado para XAMPP |
| Prototipo legado | Node.js/Express (`src/server.js`, `src/db.js`) — no participa en el flujo principal |

## Estructura del proyecto

```
├── app.py                        # Servidor Flask (backend principal)
├── requirements.txt
├── .env / .env.ejemplo           # Configuración de la base (XAMPP por defecto)
├── iniciar_flask.bat             # Lanza Flask en Windows
├── iniciar_php.bat               # Lanza el backend PHP con el PHP de XAMPP
├── GUIA_XAMPP.md                 # Paso a paso para conectar con XAMPP
├── api.php · router.php · .htaccess · lib/ · config/ · estudiantes/   # Backend PHP
├── database/
│   ├── 00_crear_base_xampp.sql   # Crea la base vacía
│   ├── crear_tablas_phpmyadmin.sql  # Crea las 12 tablas
│   └── almuerzos_conscientes.sql    # Script original con migraciones
├── docs/modulo-desperdicio-pae.md   # Notas funcionales del desperdicio PAE
├── legacy/node/                  # Prototipo Express (no se usa)
└── src/public/                   # Frontend estático (HTML)
```

## Requisitos

- Python 3.10 o superior con `pip`.
- Git.
- MySQL 8 **o** MariaDB 10.2+ (XAMPP ya incluye MariaDB).
- Opcional: PHP 8 + Apache para usar el módulo `estudiantes/index.php`.

## Instalación local

```bash
# 1. Clonar el repositorio
git clone https://github.com/AldairManchego/proyecto_almuerzo_concientes.git
cd proyecto_almuerzo_concientes

# 2. Crear el archivo de configuración (no viene en el repositorio por seguridad)
#    Windows:
copy .env.ejemplo .env
#    Linux / macOS:
#    cp .env.ejemplo .env

# 3. Editar .env con los datos de su servidor MySQL (ver tabla siguiente)

# 4. Instalar dependencias
pip install -r requirements.txt

# 5. Crear la base de datos e importar el script (ver sección siguiente)

# 6. Ejecutar
python app.py
```

El servidor queda en **http://127.0.0.1:5000**. Para comprobar la conexión a la base: **http://127.0.0.1:5000/api/estado-bd**.

## Variables de entorno (`.env`)

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `DB_HOST` | Host del servidor MySQL/MariaDB | `127.0.0.1` |
| `DB_PORT` | Puerto | `3306` |
| `DB_USER` | Usuario de la base | `root` |
| `DB_PASSWORD` | Contraseña (vacía en XAMPP por defecto) | |
| `DB_NAME` | Nombre de la base | `almuerzos_conscientes` |
| `PORT` | Puerto del servidor Flask | `5000` |
| `SECRET_KEY` | (Opcional) clave de sesiones | |
| `PUBLIC_BASE_URL` | (Opcional) URL pública para el QR, ej. IP en la red del colegio | `http://192.168.1.20:5000` |

> Este `.env` lo leen **tanto `app.py` (Flask) como `config/db.php` (módulo PHP)**: es la única fuente de configuración de la base. El archivo **no viaja en Git** (contiene credenciales), así que cada equipo o servidor debe crearlo desde `.env.ejemplo`.

## Base de datos

Crear la base y ejecutar **`database/crear_tablas_phpmyadmin.sql`** (validado en MariaDB, se puede re-ejecutar sin dañar datos). El archivo `database/almuerzos_conscientes.sql` es el script original con sentencias de migración.

Las 12 tablas y sus relaciones:

| Tabla | Contenido |
|-------|-----------|
| `estudiantes` | Padrón (documento, grado, tipo de alimentación, código QR) |
| `asistencia` | Consumo del día → **FK a `estudiantes`** (`ON DELETE RESTRICT`) |
| `historial_consumo` | Archivo de consumos mayores a 24 horas |
| `menu_diario` | Menú, gramaje y ventana de registro por fecha |
| `menu_componentes` | Componentes del menú → **FK a `menu_diario`** (`ON DELETE CASCADE`) |
| `residuos` | Desperdicio registrado (PAE / almuerzo) |
| `pae_entregas` | Registro de proteínas y carbohidratos servidos |
| `pae_entrega_dia` | Porcentaje de entrega PAE por día |
| `pae_entrega_grupos` | Gramaje servido por grupo (sexto 1 a once 3) |
| `componentes_pae` | Detalle de componentes del PAE |
| `usuarios` | Cuentas del panel (hash PBKDF2) |
| `accesos_qr` | Tokens de accesos públicos por QR |

> Nota: el backend ejecuta migraciones `ALTER TABLE ... IF NOT EXISTS` al arrancar; esa sintaxis requiere **MariaDB** (incluido en XAMPP). En MySQL puro, importe el script SQL y evite esas rutas de auto-migración.

### Cuentas del panel

| Usuario | Contraseña | Rol |
|---------|------------|-----|
| `admin` | `123456` | administracion |
| `docente` | `123456` | docente |

Las contraseñas se almacenan en hash PBKDF2 (nunca en texto plano) y las cuentas se crean automáticamente al intentar iniciar sesión o desde el script SQL. **En producción, cambie estas contraseñas**:

```bash
python -c "from werkzeug.security import generate_password_hash; print(generate_password_hash('NuevaClave', method='pbkdf2:sha256:1000000'))"
```

Luego actualice la fila correspondiente (`UPDATE usuarios SET contrasena_hash='...' WHERE usuario='admin';`).

## API principal

**Sesión y diagnóstico**

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/login` | Inicia sesión |
| POST | `/api/logout` | Cierra sesión |
| GET | `/api/sesion` | Estado de la sesión |
| GET | `/api/estado-bd` | Diagnóstico de conexión a la base |

**Estudiantes y consumo**

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET/POST | `/api/estudiantes` | Lista / registra consumo |
| POST | `/api/registro-estudiantes` | Crea estudiante en el padrón |
| PUT/DELETE | `/api/estudiantes/<id>` | Edita / elimina |
| GET | `/api/control-estudiantes` | Estado del padrón por fecha |
| GET | `/api/estado-estudiante/<documento>` | Estado del día por documento |
| GET/POST | `/api/consumos` | Consulta / registra consumo (ventana horaria) |
| GET | `/api/historial-consumos` | Histórico archivado |

**Menú, PAE y reportes**

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET/POST | `/api/menu` | Menú diario y componentes |
| GET/POST | `/api/residuos` | Desperdicio registrado |
| POST | `/api/formulario-desperdicio-pae` | Formulario público QR |
| GET/POST | `/api/pae-entrega` | Entrega PAE del día |
| GET/POST | `/api/entrega-pae-grupos` | Gramaje por grupo |
| GET/POST | `/api/componentes-pae` | Componentes del PAE |
| GET | `/api/dashboard` · `/api/reportes` | Indicadores y reportes |

**Páginas:** `/` (acceso), `/index.html` (panel), `/estudiantes`, `/qr-estudiantes`, `/acceso-formulario/<token>`, `/desperdicio-pae`.

## Copiar el proyecto a otro computador

```bash
git clone https://github.com/AldairManchego/proyecto_almuerzo_concientes.git
cd proyecto_almuerzo_concientes
cp .env.ejemplo .env        # Windows: copy .env.ejemplo .env
pip install -r requirements.txt
python app.py
```

El archivo `.env` **no se clona** (contiene credenciales): cada equipo debe crearlo desde `.env.ejemplo` con sus propios datos de base de datos.

## Notas de despliegue

- **Desarrollo local:** XAMPP (MySQL/MariaDB en `127.0.0.1:3306`) es el entorno habitual.
- **InfinityFree (hosting gratuito):** solo permite alojar el módulo **PHP** y la base de datos (nombre con prefijo `if0_<cuenta>_`), y **bloquea conexiones MySQL externas**; además no soporta Python, por lo que el backend Flask debe desplegarse en otro proveedor (PythonAnywhere, Render, VPS, etc.). Al subir el módulo PHP a InfinityFree, lleve también el archivo `.env` (no viaja en Git): `config/db.php` lo lee para conectarse a la base.

## Autor

Proyecto **Almuerzos Conscientes — Promoción 2026**.
Repositorio: https://github.com/AldairManchego/proyecto_almuerzo_concientes
