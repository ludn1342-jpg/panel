<?php
// panel/api/sesiones_estado.php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

// Verificar autenticación
if (!estaAutenticado()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

// Cargar sesiones
$sesiones = cargarSesiones();
$total = count($sesiones);
$activas = count(array_filter($sesiones, function($s) {
    return $s['activa'] ?? true;
}));

echo json_encode([
    'success' => true,
    'total' => $total,
    'activas' => $activas,
    'timestamp' => time()
]);