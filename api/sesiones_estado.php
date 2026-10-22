<?php
require_once __DIR__ . '/_bootstrap.php';

$sesiones = cargarSesiones();
$activas  = count(array_filter($sesiones, fn($s) => (int)($s['activa'] ?? 1) === 1));

json_ok([
    'total'    => count($sesiones),
    'activas'  => $activas,
    'sesiones' => $sesiones,
]);
