<?php
session_start();
require_once __DIR__ . '/../config/connectionBD.php';
require_once __DIR__ . '/remember.php';

require_once __DIR__ . '/../config/audit.php';


$pdo = Database::getConnection();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Por favor, completa todos los campos.';
    } else {
        $stmt = $pdo->prepare('
    SELECT id, number_sap, name, last_name, email, password, rol, area, must_change_password
    FROM users
    WHERE email = :email
    LIMIT 1
');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Opcional pero recomendado: regenerar ID de sesión
            session_regenerate_id(true);

            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_last']  = $user['last_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_rol']   = $user['rol'];
            $_SESSION['user_area']  = $user['area'];
            $_SESSION['number_sap'] = $user['number_sap'];
audit_log($pdo, 'AUTH_LOGIN_OK', 'users', (int)$user['id'], [
  'email' => $user['email'],
  'rol'   => (int)$user['rol'],
  'area'  => $user['area'] ?? null
]);

 // Mantener sesión iniciada REAL
if (!empty($_POST['session_open']) && $_POST['session_open'] === '1') {
    remember_create($pdo, (int)$user['id'], 30); // 30 días
} else {
    remember_clear_cookie();
}



    if ((int)$user['must_change_password'] === 1) {
        audit_log($pdo, 'AUTH_FORCE_PASSWORD_CHANGE', 'users', (int)$user['id'], [
      'email' => $user['email']
    ]);
    header('Location: /HelpDesk_EQF/auth/change_password.php');
    exit;
}
            // Redirección automática según el rol guardado en la BD
            switch ((int)$user['rol']) {
                case 1:
                    header('Location: ../modules/dashboard/sa/sa.php');
                    break;
                case 2:
                    header('Location: ../modules/dashboard/admin/admin.php');
                    break;
                case 3:
                    header('Location: ../modules/dashboard/analyst/analyst.php');
                    break;
                case 4:
                default:
                    header('Location: ../modules/dashboard/user/user.php');
                    break;
            }
            exit;
        } else {
            audit_log($pdo, 'AUTH_LOGIN_FAIL', 'users', null, [
  'email_attempt' => $email
]);
            $error = 'Credenciales incorrectas.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login | Mesa de Ayuda EQF</title>
    <link rel="stylesheet" href="/HelpDesk_EQF/assets/css/style.css?v=<?php echo time(); ?>">
</head>
<body class="login-body">
    <div class="login-wrapper">
        <div class="login-card">

            <div class="login-avatar">
                <img src="../assets/img/capsulin_login.png" alt="Capsulin LOGIN">
            </div>

            <div class="login-title">
                <p class="login-subtitle">BIENVENIDO A TU HelpDesk</p>
                <p class="login-brand">
                    <span class="eqf-e">E</span><span class="eqf-q">Q</span><span class="eqf-f">F</span>
                </p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <!-- Formulario -->
            <form method="POST" class="login-form" autocomplete="off">
                <!-- Usuario (email) -->
                <div class="form-group input-line">
                    <span class="input-icon">&#128100;</span>
                    <input
                        type="email"
                        name="email"
                        placeholder="Usuario"
                        required
                    >
                </div>

                <!-- Contraseña -->
                <div class="form-group input-line">
                    <span class="input-icon">&#128274;</span>
                    <input
                        type="password"
                        name="password"
                        placeholder="Contraseña"
                        required
                    >
                </div>
    <div class="forgot-password">
        <span id="openForgotModal">Olvidé mi contraseña</span>
    </div>
                <label for="session_open" class="remember-label">
                    <input type="checkbox" id="session_open" name="session_open" value="1">
                    Mantener sesión iniciada
                </label>

                <!-- Botón -->
                <button type="submit" class="btn-login">
                    Inicia sesión
                </button>
            </form>
        </div>
    </div>
<div class="extra-links">
    <a href="https://portal-interno-eqf.com"
       target="_blank"
       rel="noopener noreferrer"
       class="portal-link">
        Portal interno →
    </a>
</div>

<div class="user-modal-backdrop" id="forgotModal" style="display:none;">
  <div class="user-modal">
    <header class="user-modal-header">
      <h2>Recuperación de contraseña</h2>
      <button type="button" class="user-modal-close" id="closeForgotModal">✕</button>
    </header>

    <form id="forgotForm">
      <label class="form-label">Correo electrónico</label>
      <input class="user-input" type="email" name="email" id="forgotEmail" placeholder="ejemplo@eqf.mx" required>

      <!-- ALERTA (usa tu mismo estilo de alerts) -->
      <div id="forgotAlert" class="alert" style="display:none;"></div>

      <div class="modal-actions">
        <button type="submit" class="btn-primary">Enviar</button>
      </div>

      <p class="muted" style="margin-top:10px;">
        Se enviará una notificación al Administrador para atender tu solicitud.
      </p>
    </form>
  </div>
</div>
</body>
<script>
const forgotModal = document.getElementById('forgotModal');
document.getElementById('openForgotModal')?.addEventListener('click', () => forgotModal.style.display = 'flex');
document.getElementById('closeForgotModal')?.addEventListener('click', () => forgotModal.style.display = 'none');

document.getElementById('forgotForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const alertBox = document.getElementById('forgotAlert');
  alertBox.style.display = 'none';

  const email = document.getElementById('forgotEmail').value.trim();
  if (!email) return;

  const formData = new FormData();
  formData.append('email', email);

  const res = await fetch('/HelpDesk_EQF/auth/forgot_password_request.php', {
    method: 'POST',
    body: formData
  });

  const data = await res.json().catch(() => ({}));

  alertBox.style.display = 'block';
  alertBox.className = 'alert ' + (data.ok ? 'alert-success' : 'alert-error');
  alertBox.textContent = data.message || (data.ok ? 'Enviado con éxito.' : 'Ocurrió un error.');

  if (data.ok) {
    document.getElementById('forgotEmail').value = '';
  }
});
</script>

    <script>
document.getElementById('openForgotModal')?.addEventListener('click', () => {
    const modal = document.getElementById('forgotModal');
    if (modal) modal.style.display = 'flex';
});
</script>
</html>

