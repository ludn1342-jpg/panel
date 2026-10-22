<?php
require_once __DIR__ . '/../api/_db.php';

if (!defined('BAN_GEO_TTL')) define('BAN_GEO_TTL', 86400);

function ban_obtenerIPReal(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function ban_listar(): array {
    return db_fetchAll("SELECT ip, ciudad, fecha, id_solicitud FROM banned_ips ORDER BY fecha DESC");
}

function ban_estaBaneado(string $ip): bool {
    if (!$ip) return false;
    return (bool)db_fetch("SELECT 1 FROM banned_ips WHERE ip=? LIMIT 1", [$ip]);
}

function ban_agregar(string $ip, string $ciudad='Desconocida', string $idSolicitud=''): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    try {
        db_exec(
            "INSERT INTO banned_ips (ip, ciudad, id_solicitud) VALUES (?, ?, ?)
             ON CONFLICT (ip) DO UPDATE SET ciudad=EXCLUDED.ciudad, id_solicitud=EXCLUDED.id_solicitud",
            [$ip, $ciudad ?: 'Desconocida', $idSolicitud]
        );
        return true;
    } catch (Throwable $e) { return false; }
}

function ban_quitar(string $ip): bool {
    return db_exec("DELETE FROM banned_ips WHERE ip=?", [$ip]) > 0;
}

function ban_obtenerGeo(string $ip): array {
    $r = ['ciudad'=>'Desconocida','pais'=>'Desconocido'];
    if (!$ip || $ip === '0.0.0.0') return $r;
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['ciudad'=>'Local','pais'=>'Local'];
    }
    $c = db_fetch("SELECT ciudad, pais, ts FROM geo_cache WHERE ip=?", [$ip]);
    if ($c && (time() - (int)$c['ts']) < BAN_GEO_TTL) {
        return ['ciudad'=>$c['ciudad'], 'pais'=>$c['pais']];
    }
    $ciudad = 'Desconocida'; $pais = 'Desconocido';
    $ctx = stream_context_create(['http'=>['timeout'=>3,'user_agent'=>'Panel/1.0']]);
    $resp = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,city,regionName", false, $ctx);
    if ($resp) {
        $j = json_decode($resp, true);
        if (($j['status'] ?? '') === 'success') {
            $partes = array_filter([$j['city'] ?? '', $j['regionName'] ?? '']);
            $ciudad = implode(', ', $partes) ?: 'Desconocida';
            $pais   = $j['country'] ?? 'Desconocido';
        }
    }
    db_exec("
        INSERT INTO geo_cache (ip, ciudad, pais, ts) VALUES (?, ?, ?, ?)
        ON CONFLICT (ip) DO UPDATE SET ciudad=EXCLUDED.ciudad, pais=EXCLUDED.pais, ts=EXCLUDED.ts
    ", [$ip, $ciudad, $pais, time()]);
    return ['ciudad'=>$ciudad, 'pais'=>$pais];
}
function ban_obtenerCiudad(string $ip): string { return ban_obtenerGeo($ip)['ciudad']; }
function ban_obtenerPais(string $ip): string   { return ban_obtenerGeo($ip)['pais']; }

function ban_enriquecerSolicitud(array &$s): array {
    if (empty($s['ip']) || $s['ip'] === 'Desconocida') {
        $s['ip'] = $s['ip_cliente'] ?? $s['ip_usuario'] ?? 'Desconocida';
    }
    if (empty($s['pais']) || $s['pais'] === 'Desconocido') {
        $s['pais'] = filter_var($s['ip'], FILTER_VALIDATE_IP)
            ? ban_obtenerPais($s['ip']) : 'Desconocido';
    }
    if (empty($s['ciudad']) || $s['ciudad'] === 'Desconocida') {
        $s['ciudad'] = filter_var($s['ip'], FILTER_VALIDATE_IP)
            ? ban_obtenerCiudad($s['ip']) : 'Desconocida';
    }
    $s['baneado'] = ban_estaBaneado($s['ip']);
    return $s;
}

function ban_init(string $modo = '403'): void {
    $ip = ban_obtenerIPReal();
    if (!ban_estaBaneado($ip)) return;
    switch ($modo) {
        case '403':
            http_response_code(403);
            header('Content-Type: text/html; charset=UTF-8');
            $f = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/403.html';
            if (file_exists($f)) { readfile($f); exit; }
            echo '403 Forbidden'; exit;
        case '404':
            http_response_code(404);
            $f = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/404.html';
            if (file_exists($f)) { readfile($f); exit; }
            echo '404 Not Found'; exit;
        case 'json':
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success'=>false,'error'=>'not_found']); exit;
        case 'json403':
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success'=>false,'error'=>'forbidden']); exit;
        case 'redirect':
            header('Location: https://www.google.com/'); exit;
        default:
            http_response_code(403); echo '403'; exit;
    }
}
