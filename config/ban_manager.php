<?php
/**
 * ban_manager.php
 * Gestor centralizado de baneo por IP + geolocalización.
 *
 * Uso:
 *   require_once __DIR__ . '/config/ban_manager.php';
 *   ban_init('403');
 *   ban_estaBaneado($ip);
 *   ban_agregar($ip, $ciudad, $id);
 *   ban_quitar($ip);
 *   ban_listar();
 *   ban_obtenerIPReal();
 *   ban_obtenerCiudad($ip);
 *   ban_enriquecerSolicitud(&$sol);
 */

// ============================================
// CONFIGURACIÓN
// ============================================
if (!defined('BAN_FILE')) {
    define('BAN_FILE', __DIR__ . '/banned_ips.json');
}
if (!defined('BAN_GEO_CACHE')) {
    define('BAN_GEO_CACHE', __DIR__ . '/geoip_cache.json');
}
if (!defined('BAN_GEO_TTL')) {
    define('BAN_GEO_TTL', 86400);
}

// ============================================
// IP REAL DEL VISITANTE
// ============================================
if (!function_exists('ban_obtenerIPReal')) {
    function ban_obtenerIPReal() {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// ============================================
// PERSISTENCIA
// ============================================
if (!function_exists('ban_listar')) {
    function ban_listar() {
        if (!file_exists(BAN_FILE)) return [];
        $data = json_decode(@file_get_contents(BAN_FILE), true);
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('ban_guardarLista')) {
    function ban_guardarLista($lista) {
        $dir = dirname(BAN_FILE);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents(
            BAN_FILE,
            json_encode(array_values($lista), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}

// ============================================
// CONSULTAS
// ============================================
if (!function_exists('ban_estaBaneado')) {
    function ban_estaBaneado($ip) {
        if (!$ip) return false;
        foreach (ban_listar() as $b) {
            if (($b['ip'] ?? '') === $ip) return true;
        }
        return false;
    }
}

// ============================================
// ACCIONES
// ============================================
if (!function_exists('ban_agregar')) {
    function ban_agregar($ip, $ciudad = 'Desconocida', $idSolicitud = '') {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;

        // Protección: no permitir banear tu propia IP
      //  if ($ip === ban_obtenerIPReal()) {
     //       return false;
      //  }

        $lista = ban_listar();
        foreach ($lista as $b) {
            if (($b['ip'] ?? '') === $ip) return false;
        }
        $lista[] = [
            'ip'            => $ip,
            'ciudad'        => $ciudad ?: 'Desconocida',
            'fecha'         => date('Y-m-d H:i:s'),
            'id_solicitud'  => $idSolicitud
        ];
        ban_guardarLista($lista);
        return true;
    }
}

if (!function_exists('ban_quitar')) {
    function ban_quitar($ip) {
        $lista = ban_listar();
        $nueva = array_values(array_filter($lista, function($b) use ($ip) {
            return ($b['ip'] ?? '') !== $ip;
        }));
        ban_guardarLista($nueva);
        return count($nueva) !== count($lista);
    }
}

// ============================================
// GEOLOCALIZACIÓN (con caché) — devuelve array con ciudad y país
// ============================================
if (!function_exists('ban_obtenerGeo')) {
    function ban_obtenerGeo($ip) {
        $resultado = ['ciudad' => 'Desconocida', 'pais' => 'Desconocido'];

        if (!$ip || $ip === '0.0.0.0') return $resultado;

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['ciudad' => 'Local', 'pais' => 'Local'];
        }

        // Caché
        $cache = [];
        if (file_exists(BAN_GEO_CACHE)) {
            $cache = json_decode(@file_get_contents(BAN_GEO_CACHE), true);
            if (!is_array($cache)) $cache = [];
        }

        if (isset($cache[$ip]) && (time() - ($cache[$ip]['ts'] ?? 0)) < BAN_GEO_TTL) {
            return [
                'ciudad' => $cache[$ip]['ciudad'] ?? 'Desconocida',
                'pais'   => $cache[$ip]['pais']   ?? 'Desconocido'
            ];
        }

        // Consulta externa
        $ciudad = 'Desconocida';
        $pais   = 'Desconocido';

        $ctx = stream_context_create([
            'http' => ['timeout' => 3, 'user_agent' => 'Panel/1.0']
        ]);
        $resp = @file_get_contents(
            "http://ip-api.com/json/{$ip}?fields=status,country,city,regionName",
            false,
            $ctx
        );

        if ($resp) {
            $j = json_decode($resp, true);
            if (($j['status'] ?? '') === 'success') {
                $partes = array_filter([
                    $j['city']       ?? '',
                    $j['regionName'] ?? ''
                ]);
                $ciudad = implode(', ', $partes) ?: 'Desconocida';
                $pais   = $j['country'] ?? 'Desconocido';
            }
        }

        // Guardar en caché
        $cache[$ip] = ['ciudad' => $ciudad, 'pais' => $pais, 'ts' => time()];
        $dir = dirname(BAN_GEO_CACHE);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents(BAN_GEO_CACHE, json_encode($cache), LOCK_EX);

        return ['ciudad' => $ciudad, 'pais' => $pais];
    }
}

if (!function_exists('ban_obtenerCiudad')) {
    function ban_obtenerCiudad($ip) {
        $geo = ban_obtenerGeo($ip);
        return $geo['ciudad'];
    }
}

if (!function_exists('ban_obtenerPais')) {
    function ban_obtenerPais($ip) {
        $geo = ban_obtenerGeo($ip);
        return $geo['pais'];
    }
}

// ============================================
// ENRIQUECER SOLICITUD (ip, pais, ciudad, baneado)
// ============================================
if (!function_exists('ban_enriquecerSolicitud')) {
    function ban_enriquecerSolicitud(&$sol) {
        // IP
        if (empty($sol['ip']) || $sol['ip'] === 'Desconocida') {
            $sol['ip'] = $sol['ip_cliente']
                      ?? $sol['ip_usuario']
                      ?? $sol['ip_address']
                      ?? 'Desconocida';
        }

        // País
        if (empty($sol['pais']) || $sol['pais'] === 'Desconocido') {
            if ($sol['ip'] !== 'Desconocida' && filter_var($sol['ip'], FILTER_VALIDATE_IP)) {
                $sol['pais'] = ban_obtenerPais($sol['ip']);
            } else {
                $sol['pais'] = 'Desconocido';
            }
        }

        // Ciudad
        if (empty($sol['ciudad']) || $sol['ciudad'] === 'Desconocida') {
            if ($sol['ip'] !== 'Desconocida' && filter_var($sol['ip'], FILTER_VALIDATE_IP)) {
                $sol['ciudad'] = ban_obtenerCiudad($sol['ip']);
            } else {
                $sol['ciudad'] = 'Desconocida';
            }
        }

        // Baneado
        $sol['baneado'] = ban_estaBaneado($sol['ip']);
        return $sol;
    }
}

// ============================================
// INICIALIZACIÓN
// Modos: '403' | '404' | 'json' | 'json403' | 'redirect'
// ============================================
if (!function_exists('ban_init')) {
    function ban_init($modo = '403') {
        $ip = ban_obtenerIPReal();
        if (!ban_estaBaneado($ip)) return;

        switch ($modo) {

            case '403':
                http_response_code(403);
                header('HTTP/1.0 403 Forbidden');
                header('Content-Type: text/html; charset=UTF-8');

                $archivo403 = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/403.html';
                if (file_exists($archivo403)) {
                    readfile($archivo403);
                    exit;
                }

                echo '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>403 Forbidden</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    color: #202124;
    padding: 20px;
  }
  .box { max-width: 480px; text-align: center; }
  .code {
    font-size: 72px;
    font-weight: 300;
    color: #d93025;
    letter-spacing: -2px;
    line-height: 1;
    margin-bottom: 20px;
  }
  .title {
    font-size: 22px;
    font-weight: 500;
    margin-bottom: 12px;
    color: #202124;
  }
  .msg {
    font-size: 15px;
    color: #5f6368;
    line-height: 1.6;
  }
</style>
</head>
<body>
  <div class="box">
    <div class="code">403</div>
    <div class="title">Forbidden</div>
    <div class="msg">You don\'t have permission to access this resource.</div>
  </div>
</body>
</html>';
                exit;

            case '404':
                http_response_code(404);
                header('HTTP/1.0 404 Not Found');
                header('Content-Type: text/html; charset=UTF-8');

                $archivo404 = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/404.html';
                if (file_exists($archivo404)) {
                    readfile($archivo404);
                    exit;
                }

                echo '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>404 Not Found</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    color: #202124;
    padding: 20px;
  }
  .box { max-width: 480px; text-align: center; }
  .code {
    font-size: 72px;
    font-weight: 300;
    color: #202124;
    letter-spacing: -2px;
    line-height: 1;
    margin-bottom: 20px;
  }
  .title {
    font-size: 22px;
    font-weight: 500;
    margin-bottom: 12px;
    color: #202124;
  }
  .msg {
    font-size: 15px;
    color: #5f6368;
    line-height: 1.6;
  }
</style>
</head>
<body>
  <div class="box">
    <div class="code">404</div>
    <div class="title">Not Found</div>
    <div class="msg">The requested URL was not found on this server.</div>
  </div>
</body>
</html>';
                exit;

            case 'json':
                http_response_code(404);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'error' => 'not_found']);
                exit;

            case 'json403':
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'error' => 'forbidden']);
                exit;

            case 'redirect':
                header('Location: https://www.google.com/');
                exit;

            default:
                http_response_code(403);
                header('HTTP/1.0 403 Forbidden');
                echo '403 Forbidden';
                exit;
        }
    }
}