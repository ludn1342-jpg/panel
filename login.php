<?php
require_once __DIR__ . '/config/config.php';

if (estaAutenticado()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario    = $_POST['usuario'] ?? '';
    $contrasena = $_POST['contrasena'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verificarCsrfToken($csrf_token)) {
        $error = 'Token de seguridad inválido';
    } elseif (!checkRateLimit(getIp(), 5, 900)) {
        $error = 'Demasiados intentos. Espera 15 minutos.';
    } else {
        $rol = null; $usrValido = null;
        if ($usuario === SUPERADMIN_USER && $contrasena === SUPERADMIN_PASSWORD) {
            $rol = 'superadmin'; $usrValido = 'SuperAdmin';
        } elseif ($usuario === ADMIN_USER && $contrasena === ADMIN_PASSWORD) {
            $rol = 'admin'; $usrValido = 'Admin';
        }

        if ($rol !== null) {
            session_regenerate_id(true);
            $_SESSION['autenticado']   = true;
            $_SESSION['rol']           = $rol;
            $_SESSION['usuario']       = $usrValido;
            $_SESSION['ip']            = getIp();
            $_SESSION['user_agent']    = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $_SESSION['login_time']    = time();
            $_SESSION['last_activity'] = time();
            logLoginIntento($usuario, true, getIp());
            header('Location: index.php');
            exit;
        }
        logLoginIntento($usuario, false, getIp());
        $error = '❌ Credenciales incorrectas';
    }
}
$csrf_token = generarCsrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Panel de Control</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" />
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Inter',sans-serif; background:#0f0f1a; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
  .login-container { background:#1a1a2e; padding:40px; border-radius:16px; box-shadow:0 8px 32px rgba(0,0,0,0.4); max-width:400px; width:100%; border:1px solid #2a2a4e; }
  .login-container h1 { font-size:24px; font-weight:700; color:#fff; margin-bottom:8px; }
  .login-container p  { color:#8b8ba8; font-size:14px; margin-bottom:24px; }
  .form-group { margin-bottom:16px; }
  .form-group label { display:block; font-weight:600; font-size:14px; color:#c8c8e8; margin-bottom:4px; }
  .form-group input { width:100%; padding:12px 16px; background:#121224; border:2px solid #2a2a4e; border-radius:8px; font-size:16px; color:#fff; font-family:inherit; }
  .form-group input:focus { outline:none; border-color:#4a4a8a; }
  .btn { width:100%; padding:12px; background:#4a4a8a; color:white; border:none; border-radius:8px; font-size:16px; font-weight:600; cursor:pointer; }
  .btn:hover { background:#5a5aaa; }
  .error { color:#ff6b6b; font-size:14px; margin-bottom:16px; padding:12px; background:#2a1a1a; border-radius:8px; border:1px solid #4a2a2a; }
  .security-badge { font-size:12px; color:#6a6a8a; text-align:center; margin-top:20px; padding-top:20px; border-top:1px solid #2a2a4e; }
</style>
</head>
<body>
<div class="login-container">
  <h1>🔐 Panel de Control</h1>
  <p>Acceso restringido - Solo personal autorizado</p>
  <?php if (!empty($error)): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
    <div class="form-group"><label>Usuario</label><input type="text" name="usuario" required autofocus /></div>
    <div class="form-group"><label>Contraseña</label><input type="password" name="contrasena" required /></div>
    <button type="submit" class="btn">🔓 Entrar</button>
  </form>
  <div class="security-badge">🔒 CSRF · Rate Limiting · BD</div>
</div>
</body>
</html>
