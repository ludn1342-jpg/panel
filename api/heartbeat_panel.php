<?php
require_once __DIR__ . '/_bootstrap.php';

$rows = db_fetchAll("
    SELECT solicitud_id, estado, ts,
           EXTRACT(EPOCH FROM (NOW() - ts)) AS segundos
    FROM heartbeats
    WHERE ts > NOW() - INTERVAL '30 seconds'
");

$online = [];
foreach ($rows as $r) {
    $online[$r['solicitud_id']] = [
        'online' => $r['estado'] === 'activo' && (int)$r['segundos'] < 20,
        'ts'     => $r['ts'],
    ];
}

json_ok(['online' => $online]);
