<?php
// _bootstrap.php (panel) — carga config + sesión + verifica autenticación
require_once __DIR__ . '/_db.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

function json_ok(array $data = []): void {
    echo json_encode(['success' => true] + $data); exit;
}
function json_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]); exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/ban_manager.php';

if (!estaAutenticado()) {
    json_err('unauthorized', 401);
}
