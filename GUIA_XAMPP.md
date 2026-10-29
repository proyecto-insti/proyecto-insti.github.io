# Conectar Almuerzos Conscientes con XAMPP

## 1. Encender la base
1. Abra el **Panel de XAMPP** y pulse **Start** en **MySQL** (Apache no es necesario).
2. Abra http://localhost/phpmyadmin

## 2. Crear la base e importar las tablas
1. En phpMyAdmin > pestaña **SQL**, pegue el contenido de `database/00_crear_base_xampp.sql` > **Continuar**.
2. Seleccione la base `almuerzos_conscientes` (izquierda) > pestaña **Importar** > elija
   `database/crear_tablas_phpmyadmin.sql` > **Importar**. Deben aparecer 12 tablas.

## 3. Configuración (`.env`)
Ya viene listo para XAMPP: host `127.0.0.1`, puerto `3306`, usuario `root`, sin contraseña,
base `almuerzos_conscientes`. Si su root tiene contraseña, escríbala en `DB_PASSWORD`.
(Las credenciales anteriores de InfinityFree quedaron en `.env.infinityfree`.)

## 4. Ejecutar (elija UNA opción)
- **Flask (principal):** instale Python 3.10+ y haga doble clic en `iniciar_flask.bat` -> http://127.0.0.1:5000
- **PHP (sin Python):** doble clic en `iniciar_php.bat` (usa `C:\xampp\php\php.exe`) -> http://127.0.0.1:8080

## 5. Comprobar la conexión
Abra `/api/estado-bd` en el puerto que use. Si responde ok, la base está conectada.
Ingreso al panel: `admin` / `123456` (cámbiela en producción).

## Problemas frecuentes
| Síntoma | Solución |
|---|---|
| "No fue posible conectar" | MySQL apagado en XAMPP, o `.env` con datos distintos |
| "Unknown database" | Falta el paso 2.1 |
| Puerto 3306 ocupado | Cambie el puerto de MySQL en XAMPP y ponga el mismo en `DB_PORT` |
| `pip` o `python` no se reconoce | Reinstale Python marcando "Add Python to PATH" |
