<?php
// panel/sessions.php
require_once __DIR__ . '/config/config.php';

if (!estaAutenticado()) {
    header('Location: login.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001');
    exit;
}

// ============================================
// EXPULSAR UNA SESIÓN ESPECÍFICA
// ============================================
if (isset($_GET['expulsar']) && !empty($_GET['expulsar'])) {
    $sessionId = $_GET['expulsar'];
    $sesiones = cargarSesiones();
    $nuevasSesiones = [];
    
    foreach ($sesiones as $s) {
        if ($s['id'] !== $sessionId) {
            $nuevasSesiones[] = $s;
        }
    }
    
    guardarSesiones($nuevasSesiones);
    
    // Registrar en log
    $logFile = __DIR__ . '/logs/sesiones.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - SESIÓN EXPULSADA: $sessionId - IP: " . $_SERVER['REMOTE_ADDR'] . "\n", FILE_APPEND);
    
    header('Location: sessions.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001&mensaje=expulsado');
    exit;
}

// ============================================
// EXPULSAR TODAS LAS SESIONES (excepto la actual)
// ============================================
if (isset($_GET['expulsar_todas']) && $_GET['expulsar_todas'] === 'si') {
    $sesiones = cargarSesiones();
    $sesionActual = session_id();
    $nuevasSesiones = [];
    
    foreach ($sesiones as $s) {
        if ($s['id'] === $sesionActual) {
            $nuevasSesiones[] = $s;
        }
    }
    
    guardarSesiones($nuevasSesiones);
    
    $logFile = __DIR__ . '/logs/sesiones.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - TODAS LAS SESIONES EXPULSADAS (excepto actual) - IP: " . $_SERVER['REMOTE_ADDR'] . "\n", FILE_APPEND);
    
    header('Location: sessions.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001&mensaje=todas_expulsadas');
    exit;
}

// ============================================
// CARGAR SESIONES
// ============================================
$sesiones = cargarSesiones();
$sesionActual = session_id();

// Obtener estadísticas
$total = count($sesiones);
$activas = count(array_filter($sesiones, function($s) {
    return $s['activa'] ?? true;
}));

// Ordenar por fecha (más reciente primero)
usort($sesiones, function($a, $b) {
    return strtotime($b['ultima_actividad'] ?? $b['fecha'] ?? 'now') - strtotime($a['ultima_actividad'] ?? $a['fecha'] ?? 'now');
});

// CSRF Token
$csrf_token = generarCsrfToken();

// Función para obtener información del navegador
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

// Función para obtener icono del navegador
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Gestión de Sesiones</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: #0f0f1a; 
            padding: 20px; 
            min-height: 100vh; 
        }
        .container { max-width: 1200px; margin: 0 auto; }
        
        .header {
            display: flex; justify-content: space-between; align-items: center;
            background: #1a1a2e; padding: 20px 24px; border-radius: 12px;
            margin-bottom: 24px; border: 1px solid #2a2a4e;
            flex-wrap: wrap;
            gap: 12px;
        }
        .header h1 { color: #fff; font-size: 22px; }
        .header h1 span { color: #60a5fa; }
        .header .user { color: #8b8ba8; font-size: 14px; }
        .header .user a { color: #ff6b6b; text-decoration: none; margin-left: 12px; }
        .header .user a.back { color: #60a5fa; }
        
        .header-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn-volver {
            background: #2a2a4e;
            color: #60a5fa;
            border: 1px solid #3a3a6e;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-family: inherit;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-volver:hover {
            background: #3a3a6e;
            border-color: #5a5a8e;
        }
        
        .btn-expulsar-todas {
            background: #3a1a1a;
            color: #ff6b6b;
            border: 1px solid #ff6b6b;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-family: inherit;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-expulsar-todas:hover {
            background: #ff6b6b;
            color: #0f0f1a;
        }
        
        .stats {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px; margin-bottom: 24px;
        }
        .stat-card {
            background: #1a1a2e; padding: 20px; border-radius: 12px;
            border: 1px solid #2a2a4e; text-align: center;
        }
        .stat-card .number { font-size: 28px; font-weight: 700; }
        .stat-card .label { font-size: 14px; color: #8b8ba8; margin-top: 4px; }
        .stat-card .number.total { color: #60a5fa; }
        .stat-card .number.active { color: #4ade80; }
        .stat-card .number.inactive { color: #8b8ba8; }
        
        .sesiones {
            background: #1a1a2e; border-radius: 12px; border: 1px solid #2a2a4e; overflow: hidden;
        }
        .sesiones .title {
            padding: 20px 24px; font-weight: 600; color: #fff;
            border-bottom: 1px solid #2a2a4e;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .sesiones .title .badge {
            background: #2a2a4e; padding: 4px 12px; border-radius: 20px;
            font-size: 12px; color: #8b8ba8;
        }
        .sesiones .title .auto-update {
            font-size: 12px;
            color: #4ade80;
            font-weight: 400;
        }
        
        .sesion {
            padding: 16px 24px;
            border-bottom: 1px solid #1f1f36;
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: background 0.3s;
        }
        .sesion:hover { background: #22223a; }
        .sesion.actual {
            border-left: 3px solid #4ade80;
            background: #1a2a1a;
        }
        .sesion.actual:hover { background: #1f2f1f; }
        .sesion.nueva {
            animation: highlight-new 1s ease;
        }
        @keyframes highlight-new {
            0% { background: #2a2a4a; border-left: 3px solid #f7c948; }
            100% { background: transparent; border-left: 3px solid transparent; }
        }
        
        .sesion .sesion-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 20px;
        }
        
        .sesion .sesion-id {
            font-family: monospace;
            font-size: 13px;
            color: #8b8ba8;
            background: #1f1f36;
            padding: 4px 10px;
            border-radius: 4px;
            border: 1px solid #2a2a4e;
        }
        
        .sesion .sesion-info {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px 24px;
            font-size: 14px;
            color: #c8c8e8;
        }
        .sesion .sesion-info .label {
            color: #6a6a8a;
            font-weight: 500;
        }
        .sesion .sesion-info .valor {
            color: #a78bfa;
            font-weight: 600;
        }
        .sesion .sesion-info .ip {
            color: #f7c948;
            font-weight: 600;
        }
        
        .sesion .sesion-browser {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #8b8ba8;
        }
        .sesion .sesion-browser .browser-icon {
            font-size: 20px;
        }
        
        .sesion .sesion-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 16px;
            padding-top: 8px;
            border-top: 1px solid #1f1f36;
        }
        
        .sesion .estado {
            font-size: 12px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
            text-align: center;
        }
        .estado.activa { background: #1a3a2a; color: #4ade80; }
        .estado.inactiva { background: #3a1a1a; color: #ff6b6b; }
        .estado.actual { background: #1a2a3a; color: #60a5fa; }
        
        .btn-expulsar {
            background: #dc2626;
            color: #fff;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            border: none;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .btn-expulsar:hover { opacity: 0.8; }
        .btn-expulsar:disabled {
            background: #3a1a1a;
            color: #6a6a6a;
            cursor: not-allowed;
        }
        
        .empty {
            padding: 60px 20px;
            text-align: center;
            color: #6a6a8a;
        }
        .empty .icon { font-size: 48px; margin-bottom: 16px; display: block; }
        
        .mensaje {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: 500;
        }
        .mensaje.success {
            background: #1a3a2a;
            color: #4ade80;
            border: 1px solid #4ade80;
        }
        .mensaje.error {
            background: #3a1a1a;
            color: #ff6b6b;
            border: 1px solid #ff6b6b;
        }
        
        @media (max-width: 768px) {
            .stats { grid-template-columns: 1fr 1fr; }
            .header { flex-direction: column; text-align: center; }
            .header-buttons { justify-content: center; }
            .sesion { padding: 12px 16px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔐 <span>Sesiones</span> Activas</h1>
            <div class="header-buttons">
                <a href="index.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001" class="btn-volver">
                    ← Volver al Panel
                </a>
                <button class="btn-expulsar-todas" onclick="expulsarTodas()">
                    🚫 Expulsar todas
                </button>
                <div class="user">
                    👤 Admin
                    <a href="logout.php?llave=0b2c4e6c3bcb7209f5ef20632b6ff001">🚪 Salir</a>
                </div>
            </div>
        </div>
        
        <?php if (isset($_GET['mensaje'])): ?>
            <?php if ($_GET['mensaje'] === 'expulsado'): ?>
                <div class="mensaje success">✅ Sesión expulsada correctamente</div>
            <?php elseif ($_GET['mensaje'] === 'todas_expulsadas'): ?>
                <div class="mensaje success">✅ Todas las sesiones han sido expulsadas (excepto la tuya)</div>
            <?php endif; ?>
        <?php endif; ?>
        
        <div class="stats" id="statsContainer">
            <div class="stat-card">
                <div class="number total" id="statTotal"><?php echo $total; ?></div>
                <div class="label">📊 Total de sesiones</div>
            </div>
            <div class="stat-card">
                <div class="number active" id="statActivas"><?php echo $activas; ?></div>
                <div class="label">🟢 Activas</div>
            </div>
            <div class="stat-card">
                <div class="number inactive" id="statInactivas"><?php echo $total - $activas; ?></div>
                <div class="label">🔴 Inactivas</div>
            </div>
        </div>
        
        <div class="sesiones">
            <div class="title">
                <span>📋 Lista de sesiones</span>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <span class="auto-update" id="updateCounter">🔄 Actualizando...</span>
                    <span class="badge" id="totalBadge"><?php echo $total; ?> sesiones</span>
                </div>
            </div>
            
            <div id="sesionesList">
                <?php if (empty($sesiones)): ?>
                    <div class="empty">
                        <span class="icon">🔐</span>
                        No hay sesiones activas
                    </div>
                <?php else: ?>
                    <?php foreach ($sesiones as $s): 
                        $esActual = ($s['id'] === $sesionActual);
                        $browserInfo = getBrowserInfo($s['user_agent'] ?? '');
                        $icono = getBrowserIcon($browserInfo['browser']);
                    ?>
                        <div class="sesion <?php echo $esActual ? 'actual' : ''; ?>" data-id="<?php echo $s['id']; ?>">
                            <div class="sesion-row">
                                <span class="sesion-id">🆔 <?php echo htmlspecialchars(substr($s['id'], 0, 20)) . '...'; ?></span>
                                <span class="estado <?php echo $esActual ? 'actual' : ($s['activa'] ?? true ? 'activa' : 'inactiva'); ?>">
                                    <?php echo $esActual ? '🔵 ACTUAL' : (($s['activa'] ?? true) ? '🟢 Activa' : '🔴 Inactiva'); ?>
                                </span>
                            </div>
                            
                            <div class="sesion-info">
                                <span>
                                    <span class="label">📅 Inicio:</span>
                                    <span class="valor"><?php echo htmlspecialchars($s['fecha'] ?? $s['creada'] ?? 'N/A'); ?></span>
                                </span>
                                <span>
                                    <span class="label">🔄 Última actividad:</span>
                                    <span class="valor"><?php echo htmlspecialchars($s['ultima_actividad'] ?? 'N/A'); ?></span>
                                </span>
                                <span>
                                    <span class="label">🌐 IP:</span>
                                    <span class="ip"><?php echo htmlspecialchars($s['ip'] ?? 'N/A'); ?></span>
                                </span>
                            </div>
                            
                            <div class="sesion-browser">
                                <span class="browser-icon"><?php echo $icono; ?></span>
                                <span><?php echo htmlspecialchars($browserInfo['browser']); ?></span>
                                <span style="color:#6a6a8a;">|</span>
                                <span><?php echo htmlspecialchars($browserInfo['platform']); ?></span>
                            </div>
                            
                            <div class="sesion-actions">
                                <?php if (!$esActual): ?>
                                    <a href="?expulsar=<?php echo $s['id']; ?>&llave=0b2c4e6c3bcb7209f5ef20632b6ff001" 
                                       onclick="return confirm('¿Expulsar esta sesión? El usuario será desconectado.')" 
                                       class="btn-expulsar">
                                        🚫 Expulsar
                                    </a>
                                <?php else: ?>
                                    <span class="btn-expulsar" disabled style="background:#2a2a4e;color:#6a6a8a;cursor:not-allowed;">
                                        👤 Tú (no puedes expulsarte)
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // ============================================
        // EXPULSAR TODAS LAS SESIONES
        // ============================================
        function expulsarTodas() {
            if (confirm('⚠️ ¿Expulsar TODAS las sesiones excepto la tuya?')) {
                if (confirm('¿Estás SEGURO? Todos los demás usuarios serán desconectados.')) {
                    window.location.href = '?expulsar_todas=si&llave=0b2c4e6c3bcb7209f5ef20632b6ff001';
                }
            }
        }

        // ============================================
        // AUTO-ACTUALIZACIÓN CON LA API
        // ============================================
        let updateCounter = 0;
        let isUpdating = false;

        async function actualizarSesiones() {
            if (isUpdating) return;
            isUpdating = true;
            
            try {
                // Usar ruta relativa a la API
                const response = await fetch('api/sesiones_estado.php');
                
                if (!response.ok) throw new Error('Error en la respuesta');
                
                const data = await response.json();
                
                if (!data.success) throw new Error('Error en los datos');
                
                // Actualizar estadísticas
                document.getElementById('statTotal').textContent = data.total;
                document.getElementById('statActivas').textContent = data.activas;
                document.getElementById('statInactivas').textContent = data.total - data.activas;
                
                document.getElementById('totalBadge').textContent = data.total + ' sesiones';
                
                updateCounter++;
                document.getElementById('updateCounter').textContent = `🔄 Actualizado (${updateCounter})`;
                
            } catch (error) {
                console.error('Error al actualizar sesiones:', error);
                document.getElementById('updateCounter').textContent = '❌ Error de conexión';
            } finally {
                isUpdating = false;
            }
        }

        // ============================================
        // INICIALIZAR AUTO-ACTUALIZACIÓN
        // ============================================
        window.addEventListener('load', function() {
            // Actualizar después de 2 segundos
            setTimeout(() => {
                actualizarSesiones();
            }, 2000);
            
            // Actualizar cada 10 segundos
            setInterval(() => {
                actualizarSesiones();
            }, 10000);
            
            console.log('🔄 Auto-actualización de sesiones iniciada (cada 10 segundos)');
        });
    </script>
</body>
</html>