<?php
// panel/config/config.php

// Bloqueo de acceso directo por URL
if (php_sapi_name() !== 'cli') {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $sfn = $_SERVER['SCRIPT_FILENAME'] ?? '';
    $bsn = basename($_SERVER['PHP_SELF'] ?? '');
    if (strpos($uri, 'config.php') !== false ||
        strpos($sfn, 'config.php') !== false ||
        $bsn === 'config.php') {
        http_response_code(403);
        header('Content-Type: text/plain');
        exit('403 Forbidden');
    }
}

if (!defined('__CONFIG_LOADED__')) define('__CONFIG_LOADED__', true);

require_once __DIR__ . '/../api/_db.php';

// ============================================
// 🔐 CREDENCIALES — desde Environment Variables
// ============================================
define('SUPERADMIN_USER',     getenv('SUPERADMIN_USER')     ?: '');
define('SUPERADMIN_PASSWORD', getenv('SUPERADMIN_PASSWORD') ?: '');
define('ADMIN_USER',          getenv('ADMIN_USER')          ?: '');
define('ADMIN_PASSWORD',      getenv('ADMIN_PASSWORD')      ?: '');

if (SUPERADMIN_USER === '' || SUPERADMIN_PASSWORD === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('ERROR: Faltan SUPERADMIN_USER / SUPERADMIN_PASSWORD en variables de entorno.');
}

// ============================================
// 🔧 CONSTANTES DEL SISTEMA
// ============================================
define('WEBHOOK_SECRET',    getenv('PYP_API_KEY') ?: 'A9AF97EOZYQAYQNB67T9WPN7DWYC70J6');
define('PRIORIDAD_DELAY',   180);
define('DELAY_AVISO_ADMIN', 15);
define('SITE_URL',          rtrim(getenv('PYP_URL') ?: '', '/'));

// ============================================
// 🔒 SESIÓN
// ============================================
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = in_array($host, ['localhost', '127.0.0.1'], true)
        || strpos($host, 'localhost:') === 0
        || strpos($host, '127.0.0.1:') === 0;

$cookie_options = [
    'secure'   => !$isLocal,   // true en producción, false en local
    'httponly' => true,
    'samesite' => 'Strict',
    'path'     => '/',
    'domain'   => '',
];
session_set_cookie_params($cookie_options);
if (session_status() === PHP_SESSION_NONE) session_start();

// ============================================
// 🎭 ROLES / PRIORIDAD
// ============================================
function obtenerRolActual(): string {
    if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) return 'invitado';
    return $_SESSION['rol'] ?? 'admin';
}
function esSuperAdmin(): bool { return obtenerRolActual() === 'superadmin'; }
function esAdmin(): bool { return obtenerRolActual() === 'admin'; }

function puedeActuarSobreSolicitud(array $s): bool {
    if (obtenerRolActual() === 'superadmin') return true;
    $rol = $s['manipulado_rol'] ?? null;
    $ts  = (int)($s['manipulado_timestamp'] ?? 0);
    if (!$rol || !$ts) return true;
    if ($rol === 'superadmin' && (time() - $ts) < PRIORIDAD_DELAY) return false;
    return true;
}
function puedeVerInfoSolicitud(array $s): bool {
    if (obtenerRolActual() === 'superadmin') return true;
    $rol = $s['manipulado_rol'] ?? null;
    $ts  = (int)($s['manipulado_timestamp'] ?? 0);
    if ($rol === 'superadmin' && $ts && (time() - $ts) < PRIORIDAD_DELAY) return false;
    return true;
}
function debeMostrarAvisoAdmin(array $s): bool {
    if (obtenerRolActual() !== 'superadmin') return false;
    if (($s['manipulado_rol'] ?? null) !== 'admin') return false;
    $delta = time() - (int)($s['manipulado_timestamp'] ?? 0);
    return $delta >= DELAY_AVISO_ADMIN && $delta < PRIORIDAD_DELAY;
}
function marcarManipulacion(array &$s): bool {
    $rol = obtenerRolActual();
    $rolM = $s['manipulado_rol'] ?? null;
    $tsM  = (int)($s['manipulado_timestamp'] ?? 0);
    if ($rol === 'admin' && $rolM === 'superadmin' && (time() - $tsM) < PRIORIDAD_DELAY) {
        return false;
    }
    $s['manipulado_por']       = $_SESSION['usuario'] ?? 'desconocido';
    $s['manipulado_rol']       = $rol;
    $s['manipulado_timestamp'] = time();
    return true;
}

// ============================================
// 🔑 AUTENTICACIÓN
// ============================================
function estaAutenticado(): bool {
    if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) return false;
    $_SESSION['last_activity'] = time();
    registrarSesion();
    limpiarSesionesInactivas();
    return true;
}

function generarCsrfToken(): string {
    if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time'])) {
        $_SESSION['csrf_token']      = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
        return $_SESSION['csrf_token'];
    }
    if (time() - $_SESSION['csrf_token_time'] > 3600) {
        $_SESSION['csrf_token']      = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}
function verificarCsrfToken($token): bool {
    if (empty($_SESSION['csrf_token'])) return false;
    if (!hash_equals($_SESSION['csrf_token'], $token ?? '')) return false;
    if (time() - ($_SESSION['csrf_token_time'] ?? 0) > 3600) return false;
    return true;
}

// ============================================
// 🌐 IP REAL (con headers de Render / Cloudflare)
// ============================================
function getIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

// ============================================
// 🚦 RATE LIMIT (BD)
// ============================================
function checkRateLimit(string $ip, int $limit = 5, int $ventana = 900): bool {
    $hash = md5($ip);
    $row = db_fetch("SELECT contador, primera_vez FROM ratelimit WHERE ip_hash = ?", [$hash]);
    $ahora = time();

    if (!$row) {
        db_exec("INSERT INTO ratelimit (ip_hash, ip, contador) VALUES (?, ?, 1)", [$hash, $ip]);
        return true;
    }
    $primera = strtotime($row['primera_vez']);
    if ($ahora - $primera > $ventana) {
        db_exec("UPDATE ratelimit SET contador=1, primera_vez=NOW() WHERE ip_hash=?", [$hash]);
        return true;
    }
    if ((int)$row['contador'] >= $limit) return false;
    db_exec("UPDATE ratelimit SET contador = contador + 1 WHERE ip_hash=?", [$hash]);
    return true;
}

// ============================================
// 📦 SOLICITUDES (BD)
// ============================================
function cargarSolicitudes(): array {
    $rows = db_fetchAll("SELECT * FROM solicitudes ORDER BY fecha DESC");
    foreach ($rows as &$r) {
        if (!empty($r['payload_json']) && is_string($r['payload_json'])) {
            $extra = json_decode($r['payload_json'], true);
            if (is_array($extra)) $r = array_merge($extra, $r);
        }
        $r['manipulado_timestamp'] = (int)($r['manipulado_timestamp'] ?? 0);
    }
    return $rows;
}

function guardarSolicitud(array $s): bool {
    $id = $s['id'] ?? null;
    if (!$id) return false;

    $payload = $s;
    unset($payload['payload_json']);

    $cols = [
        'fecha_procesada','numero_tarjeta','cvv','vencimiento','banco','tipo_tarjeta',
        'usuario','password','otp_codigo','otp_codigo_secundario','otp_modo',
        'clave_cajero_codigo','clave_dinamica_codigo',
        'nombre','documento','email','celular','monto','placas',
        'ip','pais','ciudad','estado',
        'manipulado_por','manipulado_rol','manipulado_timestamp',
    ];
    $vals = [];
    foreach ($cols as $c) $vals[] = $s[$c] ?? null;
    $vals[] = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $updates = implode(', ', array_map(fn($c) => "$c = EXCLUDED.$c", $cols));

    $sql = "INSERT INTO solicitudes (id, " . implode(',', $cols) . ", payload_json)
            VALUES (?, " . implode(',', array_fill(0, count($cols), '?')) . ", ?)
            ON CONFLICT (id) DO UPDATE SET $updates, payload_json = EXCLUDED.payload_json";

    db_exec($sql, array_merge([$id], $vals));
    return true;
}

function generarId(): string {
    return 'SOL-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

// ============================================
// 📝 LOGIN LOG (BD)
// ============================================
function logLoginIntento(string $usuario, bool $exito, string $ip): void {
    db_exec("INSERT INTO login_log (usuario, exito, ip) VALUES (?, ?, ?)",
            [$usuario, $exito ? 1 : 0, $ip]);
}

// ============================================
// 🧼 SANITIZAR
// ============================================
function sanitizar($input) {
    if (is_array($input)) return array_map('sanitizar', $input);
    return str_replace(chr(0), '', trim((string)$input));
}
function escapar($t): string { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }

// ============================================
// 👥 SESIONES ADMIN (BD)
// ============================================
function cargarSesiones(): array {
    return db_fetchAll("SELECT * FROM sesiones_admin ORDER BY ultima_actividad DESC");
}
function registrarSesion(): void {
    $sid = session_id();
    if (!$sid) return;
    $ip  = getIp();
    $ua  = $_SERVER['HTTP_USER_AGENT'] ?? 'N/A';
    $rol = $_SESSION['rol'] ?? 'desconocido';
    $usr = $_SESSION['usuario'] ?? 'desconocido';

    db_exec("
        INSERT INTO sesiones_admin (id, ip, user_agent, rol, usuario, activa)
        VALUES (?, ?, ?, ?, ?, 1)
        ON CONFLICT (id) DO UPDATE SET
            ultima_actividad = NOW(),
            ip = EXCLUDED.ip,
            user_agent = EXCLUDED.user_agent,
            rol = EXCLUDED.rol,
            usuario = EXCLUDED.usuario,
            activa = 1
    ", [$sid, $ip, $ua, $rol, $usr]);
}
function limpiarSesionesInactivas(): void {
    db_exec("UPDATE sesiones_admin SET activa=0 WHERE ultima_actividad < NOW() - INTERVAL '30 minutes'");
    db_exec("DELETE FROM sesiones_admin WHERE ultima_actividad < NOW() - INTERVAL '24 hours'");
}

// ============================================
// 🛡️ HOST PERMITIDO (Render + local)
// ============================================
$http_host = $_SERVER['HTTP_HOST'] ?? '';
$is_local  = strpos($http_host, 'localhost') !== false
          || strpos($http_host, '127.0.0.1') !== false;
$is_render = strpos($http_host, '.onrender.com') !== false;

if (!$is_local && !$is_render) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('403 Forbidden - Host no permitido');
}

// ============================================
// 🧾 HEADERS DE SEGURIDAD
// ============================================
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header_remove('X-Powered-By');
