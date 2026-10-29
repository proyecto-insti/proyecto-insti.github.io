
CREATE TABLE IF NOT EXISTS accesos_qr (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  token VARCHAR(120) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_uso DATETIME NULL,
  UNIQUE KEY uq_accesos_qr_nombre (nombre),
  UNIQUE KEY uq_accesos_qr_token (token)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS estudiantes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  documento VARCHAR(30) NOT NULL,
  edad TINYINT UNSIGNED NOT NULL,
  grado VARCHAR(20) NOT NULL,
  tipo_alimentacion ENUM('PAE','almuerzo') NOT NULL DEFAULT 'PAE',
  codigo_qr VARCHAR(100) NOT NULL,
  fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_estudiantes_documento (documento),
  UNIQUE KEY uq_estudiantes_codigo_qr (codigo_qr),
  KEY idx_estudiantes_grado (grado),
  CONSTRAINT chk_estudiante_edad CHECK (edad BETWEEN 3 AND 20),
  CONSTRAINT chk_estudiante_documento CHECK (documento REGEXP '^[0-9]+$'),
  CONSTRAINT chk_estudiante_grado CHECK (grado IN ('5-1','5-2','5-3','6-1','6-2','6-3','7-1','7-2','7-3','8-1','8-2','8-3','9-1','9-2','9-3','10-1','10-2','10-3','11-1','11-2','11-3'))
) ENGINE=InnoDB;



-- Migración segura para bases creadas con una versión anterior.
ALTER TABLE estudiantes ADD COLUMN IF NOT EXISTS tipo_alimentacion ENUM('PAE','almuerzo') NOT NULL DEFAULT 'PAE' AFTER grado;

-- Migración: si la base ya existía con el CHECK antiguo (solo 5º, 10º y 11º),
-- se reemplaza por uno que también permite 7º, 8º y 9º.
SET @chk_existe = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'estudiantes' AND CONSTRAINT_NAME = 'chk_estudiante_grado'
);
SET @sql_drop_chk = IF(@chk_existe > 0, 'ALTER TABLE estudiantes DROP CHECK chk_estudiante_grado', 'SELECT 1');




ALTER TABLE estudiantes
  ADD CONSTRAINT chk_estudiante_grado
  CHECK (grado IN ('5-1','5-2','5-3','6-1','6-2','6-3','7-1','7-2','7-3','8-1','8-2','8-3','9-1','9-2','9-3','10-1','10-2','10-3','11-1','11-2','11-3'));

CREATE TABLE IF NOT EXISTS menu_diario (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  nombre_menu VARCHAR(180) NOT NULL,
  tipo_racion ENUM('caliente','contingencia','industrializada') NOT NULL DEFAULT 'caliente',
  gramaje_total DECIMAL(10,2) UNSIGNED NOT NULL,
  gramajes_grupo JSON NULL,
  ventana_inicio TIME NOT NULL DEFAULT '09:30:00',
  ventana_fin TIME NOT NULL DEFAULT '13:00:00',
  registro_habilitado TINYINT(1) NOT NULL DEFAULT 1,
  notas VARCHAR(500) NULL,
  UNIQUE KEY uq_menu_diario_fecha (fecha)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS menu_componentes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_id INT UNSIGNED NOT NULL,
  componente ENUM('proteína','carbohidrato','legumbre','ensalada','fruta','bebida','postre','otros') NOT NULL DEFAULT 'otros',
  alimento VARCHAR(150) NOT NULL,
  gramos DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0,
  unidad VARCHAR(5) NOT NULL DEFAULT 'g',
  grado VARCHAR(20) NULL,
  es_opcional TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_menu_componentes_menu (menu_id),
  KEY idx_menu_componentes_grado (grado),
  CONSTRAINT fk_menu_componentes_menu FOREIGN KEY (menu_id) REFERENCES menu_diario(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asistencia (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  estudiante_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  hora TIME NOT NULL,
  tipo_consumo ENUM('ninguno','vaso_leche','almuerzo') NOT NULL DEFAULT 'ninguno',
  consumido_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  metodo_registro ENUM('qr','documento','formulario') NOT NULL DEFAULT 'documento',
  UNIQUE KEY uq_asistencia_estudiante_fecha (estudiante_id, fecha),
  KEY idx_asistencia_fecha (fecha),
  KEY idx_asistencia_consumido_en (consumido_en),
  CONSTRAINT fk_asistencia_estudiante FOREIGN KEY (estudiante_id) REFERENCES estudiantes(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

ALTER TABLE menu_diario ADD COLUMN IF NOT EXISTS gramajes_grupo JSON NULL AFTER gramaje_total;
ALTER TABLE menu_componentes ADD COLUMN IF NOT EXISTS grado VARCHAR(20) NULL AFTER gramos;
ALTER TABLE menu_componentes ADD COLUMN IF NOT EXISTS unidad VARCHAR(5) NOT NULL DEFAULT 'g' AFTER gramos;

ALTER TABLE asistencia ADD COLUMN IF NOT EXISTS tipo_consumo ENUM('ninguno','vaso_leche','almuerzo') NOT NULL DEFAULT 'ninguno' AFTER fecha;

CREATE TABLE IF NOT EXISTS historial_consumo (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asistencia_id BIGINT UNSIGNED NULL,
  estudiante_id INT UNSIGNED NOT NULL,
  nombre_estudiante VARCHAR(150) NOT NULL,
  documento VARCHAR(30) NOT NULL,
  grado VARCHAR(20) NOT NULL,
  fecha_consumo DATE NOT NULL,
  hora_consumo TIME NOT NULL,
  tipo_consumo ENUM('ninguno','vaso_leche','almuerzo') NOT NULL DEFAULT 'ninguno',
  consumido_en DATETIME NOT NULL,
  metodo_registro ENUM('qr','documento','formulario') NOT NULL DEFAULT 'formulario',
  archivado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_historial_fecha (fecha_consumo),
  KEY idx_historial_grado (grado),
  KEY idx_historial_estudiante (estudiante_id)
) ENGINE=InnoDB;

ALTER TABLE historial_consumo ADD COLUMN IF NOT EXISTS tipo_consumo ENUM('ninguno','vaso_leche','almuerzo') NOT NULL DEFAULT 'ninguno' AFTER hora_consumo;

CREATE TABLE IF NOT EXISTS residuos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  peso_sobrante_gramos DECIMAL(10,2) UNSIGNED NOT NULL,
  grupo_tipo ENUM('primaria','superior','general','individual') NOT NULL DEFAULT 'general',
  tipo_alimentacion ENUM('PAE','almuerzo') NOT NULL DEFAULT 'PAE',
  grado VARCHAR(20) NULL,
  nota VARCHAR(500) NULL,
  KEY idx_residuos_fecha_grupo (fecha, grupo_tipo),
  KEY idx_residuos_fecha_grado (fecha, grado)
) ENGINE=InnoDB;

-- Migración para bases de datos creadas antes del registro explícito del desperdicio PAE.
ALTER TABLE residuos ADD COLUMN IF NOT EXISTS tipo_alimentacion ENUM('PAE','almuerzo') NOT NULL DEFAULT 'PAE' AFTER grupo_tipo;

CREATE TABLE IF NOT EXISTS pae_entregas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL UNIQUE,
  porcentaje_entregado DECIMAL(5,2) NOT NULL DEFAULT 0,
  porcentaje_disponible DECIMAL(5,2) NOT NULL DEFAULT 0,
  observacion VARCHAR(500) NULL,
  componentes LONGTEXT NULL COMMENT 'JSON: servido/entregado por componente (g; bebidas en L)',
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
-- Identificador de envío del formulario QR: evita duplicar un pesaje si el celular reintenta.
ALTER TABLE residuos ADD COLUMN IF NOT EXISTS uid_envio VARCHAR(40) NULL;
ALTER TABLE residuos ADD UNIQUE KEY IF NOT EXISTS uq_residuos_uid_envio (uid_envio);

-- Entrega del PAE: porcentaje y observación del día + gramaje por grupo (sexto 1 a once 3).
-- Nota: pae_entregas (arriba) guarda el "Registro de proteínas y carbohidratos del almuerzo".
CREATE TABLE IF NOT EXISTS pae_entrega_dia (
  fecha DATE NOT NULL PRIMARY KEY,
  porcentaje_entregado DECIMAL(5,2) NOT NULL DEFAULT 0,
  gramaje_total DECIMAL(12,2) NULL,
  observacion VARCHAR(500) NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pae_entrega_grupos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  grado VARCHAR(10) NOT NULL,
  gramos_servidos DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_pae_entrega_grupo (fecha, grado)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS componentes_pae (
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
) ENGINE=InnoDB;

-- Datos de ejemplo opcionales para visualizar el formulario inmediatamente.
-- Se pueden eliminar sin afectar la estructura.
INSERT INTO menu_diario
  (fecha, nombre_menu, tipo_racion, gramaje_total, gramajes_grupo, ventana_inicio, ventana_fin, registro_habilitado, notas)
VALUES
  (CURDATE(), 'Arroz con pollo y ensalada', 'caliente', 450, NULL, '09:30:00', '13:00:00', 1, 'Menú de prueba editable por el docente.')
ON DUPLICATE KEY UPDATE nombre_menu=VALUES(nombre_menu);

INSERT INTO menu_componentes (menu_id, componente, alimento, gramos)
SELECT m.id, 'proteína', 'Pollo guisado', 120 FROM menu_diario m
WHERE m.fecha=CURDATE()
  AND NOT EXISTS (SELECT 1 FROM menu_componentes c WHERE c.menu_id=m.id AND c.alimento='Pollo guisado');
INSERT INTO menu_componentes (menu_id, componente, alimento, gramos)
SELECT m.id, 'carbohidrato', 'Arroz', 180 FROM menu_diario m
WHERE m.fecha=CURDATE()
  AND NOT EXISTS (SELECT 1 FROM menu_componentes c WHERE c.menu_id=m.id AND c.alimento='Arroz');
INSERT INTO menu_componentes (menu_id, componente, alimento, gramos)
SELECT m.id, 'ensalada', 'Ensalada fresca', 80 FROM menu_diario m
WHERE m.fecha=CURDATE()
  AND NOT EXISTS (SELECT 1 FROM menu_componentes c WHERE c.menu_id=m.id AND c.alimento='Ensalada fresca');
INSERT INTO menu_componentes (menu_id, componente, alimento, gramos)
SELECT m.id, 'bebida', 'Jugo natural', 70 FROM menu_diario m
WHERE m.fecha=CURDATE()
  AND NOT EXISTS (SELECT 1 FROM menu_componentes c WHERE c.menu_id=m.id AND c.alimento='Jugo natural');


-- Usuarios autorizados para entrar desde index1.html al panel index.html.
-- Las contraseñas se almacenan como hashes PBKDF2, nunca en texto plano.
CREATE TABLE IF NOT EXISTS usuarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(60) NOT NULL,
  nombre VARCHAR(120) NOT NULL,
  rol ENUM('administracion','docente') NOT NULL,
  contrasena_hash VARCHAR(255) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_acceso DATETIME NULL,
  UNIQUE KEY uq_usuarios_usuario (usuario),
  KEY idx_usuarios_rol (rol)
) ENGINE=InnoDB;

-- Cuentas: admin/123456 y docente/123456 (hash PBKDF2; cambiar en producción).
INSERT INTO usuarios (usuario, nombre, rol, contrasena_hash)
VALUES
  ('admin', 'Personal de administración', 'administracion', 'pbkdf2:sha256:1000000$6wnwtEOzWFqdG5HG$7854634f6d66a31754e586b87bf6cba0f7bbdb5b840c5f387597d4a0d75aa746'),
  ('docente', 'Personal docente', 'docente', 'pbkdf2:sha256:1000000$GpWMXIGBzlV3wpOw$b5f0cb9ad59e6904693e24a75e340536e54a82dc3d3d72cf8a1d75c5b91aea0e')
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), rol=VALUES(rol), contrasena_hash=VALUES(contrasena_hash), activo=1;
