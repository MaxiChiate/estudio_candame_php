-- Portal de seguimiento de tramites (SAS). Primera migracion del proyecto: hasta
-- aca el sitio no usaba base de datos.
--
-- Se aplica A MANO: no hay framework de migraciones ni acceso SSH al hosting.
--   local: mysql candame_local < database/migrations/001_seguimiento.sql
--   prod:  pegar en phpMyAdmin (cPanel), sobre la base del portal
--
-- Este archivo vive en la raiz del repo, fuera de app/, asi que NO viaja en el
-- deploy (deploy.sh sincroniza app/ y public/ solamente) y nunca queda expuesto
-- por HTTP.
--
-- Produccion corre MariaDB en hosting compartido: el esquema se limita a tipos y
-- sintaxis que soportan tanto MariaDB como MySQL 8 (nada de CHECK con expresiones,
-- funciones de ventana ni indices funcionales sobre JSON).

CREATE TABLE tramite (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo           VARCHAR(20)  NOT NULL UNIQUE,   -- PRES-2026-0001
  tipo             VARCHAR(10)  NOT NULL DEFAULT 'SAS',
  ruta_estatuto    ENUM('MODELO','LIBRE') NOT NULL DEFAULT 'MODELO',
  denominacion     VARCHAR(255) NOT NULL,
  etapa_actual     VARCHAR(30)  NOT NULL,
  observado        TINYINT(1)   NOT NULL DEFAULT 0,
  nota_observacion TEXT NULL,                      -- publica, la ve el cliente
  creado_el        DATETIME     NOT NULL,
  actualizado_el   DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tramite_evento (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tramite_id   INT UNSIGNED NOT NULL,
  etapa        VARCHAR(30)  NOT NULL,
  ocurrido_el  DATETIME     NOT NULL,
  nota_publica TEXT NULL,
  nota_interna TEXT NULL,                          -- NUNCA se expone en vistas publicas
  CONSTRAINT fk_evento_tramite FOREIGN KEY (tramite_id)
    REFERENCES tramite(id) ON DELETE CASCADE,
  INDEX idx_evento_tramite (tramite_id, ocurrido_el)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tramite_acceso (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tramite_id       INT UNSIGNED NOT NULL,
  token_hash       CHAR(64)     NOT NULL UNIQUE,   -- sha256 hex; el token en claro no se guarda nunca
  etiqueta         VARCHAR(120) NOT NULL,          -- "cliente Juan Perez"
  creado_el        DATETIME     NOT NULL,
  revocado_el      DATETIME     NULL,
  ultimo_acceso_el DATETIME     NULL,
  accesos          INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_acceso_tramite FOREIGN KEY (tramite_id)
    REFERENCES tramite(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
