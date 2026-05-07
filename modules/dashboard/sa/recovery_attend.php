<?php
session_start();
require_once __DIR__ . '/../../../config/connectionBD.php';
require_once __DIR__ . '/../../../config/audit.php';
require_once __DIR__ . '/../../../helpers/Mailer.php';
$pdo = Database::getConnection();

if (!isset($_SESSION['user_id']) || (int)($_SESSION['user_rol'] ?? 0) !== 1) {
    header('Location: /HelpDesk_EQF/auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /HelpDesk_EQF/modules/dashboard/sa/sa.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /HelpDesk_EQF/modules/dashboard/sa/sa.php');
    exit;
}

$pdo = Database::getConnection();

function sendRecoveryEmailToUser(string $to): bool {
    $subject = "Recuperación de contraseña - HELP DESK EQF";

    // Texto plano (por si el cliente de correo bloquea HTML)
    $text =
"Buen día,\n\n".
"Se realizó la recuperación de contraseña del sistema Help Desk.\n".
"Recuerde que al iniciar sesión por primera vez deberá cambiarla.\n\n".
"Usuario: {$to}\n".
"Contraseña temporal: 12345a\n\n".
"Servicio de soporte\nHelpDesk EQF\n";

   
    $logoUrl = "HelpDesk_EQF/assets/img/capsulin_login.png";

    $tempPass = "12345a"; 
    $html = '
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>'.$subject.'</title>
</head>
<body style="margin:0; padding:0; background:#f4f6fb; font-family: Arial, Helvetica, sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6fb; padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:640px; max-width:640px; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 6px 22px rgba(16,24,40,0.08);">
          
          <!-- Header -->
          <tr>
            <td style="padding:22px 26px; background:#ffffff;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td style="vertical-align:middle;">
                    <div style="font-size:28px; letter-spacing:2px; font-weight:800; color:#0b2a6f;">
                      AVISO
                    </div>
                    <div style="font-size:12px; color:#667085; margin-top:4px;">
                      HelpDesk EQF · Recuperación de contraseña
                    </div>
                  </td>
                  <td align="right" style="vertical-align:middle;">
                    <img src="HelpDesk_EQF/assets/img/MESA_DE_AYUDA.png" alt="Equilibrio Farmacéutico" style="height:44px; display:block;">
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Divider -->
          <tr>
            <td style="height:1px; background:#e6eaf2;"></td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:26px;">
              <div style="font-size:18px; font-weight:700; color:#101828; margin-bottom:10px;">
                Estimado(a) colaborador(a),
              </div>

              <div style="font-size:14px; line-height:1.6; color:#344054; margin-bottom:18px;">
                Se realizó la <b>recuperación de contraseña</b> del sistema Help Desk.
                Por seguridad, al iniciar sesión por primera vez deberá <b>cambiarla</b>.
              </div>

              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc; border:1px solid #e6eaf2; border-radius:12px;">
                <tr>
                  <td style="padding:16px;">
                    <div style="font-size:12px; color:#667085; margin-bottom:8px;">Credenciales temporales</div>
                    <div style="font-size:14px; color:#101828; margin-bottom:6px;">
                      <b>Usuario:</b> '.$to.'
                    </div>
                    <div style="font-size:14px; color:#101828;">
                      <b>Contraseña temporal:</b> '.$tempPass.'
                    </div>
                  </td>
                </tr>
              </table>

              <div style="font-size:14px; line-height:1.6; color:#344054; margin-top:18px;">
                Si usted no solicitó esta acción, favor de reportarlo de inmediato al área de soporte.
              </div>

              <div style="font-size:14px; color:#101828; font-weight:700; margin-top:22px;">
                Agradecemos su atención y colaboración.
              </div>

              <div style="font-size:14px; color:#344054; margin-top:18px; text-align:right;">
                Atentamente,<br>
                <b>Servicio de soporte</b><br>
                HelpDesk EQF
              </div>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding:14px 26px; background:#0b2a6f; color:#ffffff; font-size:12px;">
              Este mensaje fue generado automáticamente. No responda a este correo.
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>';

    return sendMailEQF([$to => $to], $subject, $text, $html);
}


try {
    $pdo->beginTransaction();

    // Bloquear registro para evitar doble click
    $st = $pdo->prepare("SELECT requester_email, status FROM password_recovery_requests WHERE id=? FOR UPDATE");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row || ($row['status'] ?? '') !== 'PENDIENTE') {
        $pdo->rollBack();
        header('Location: /HelpDesk_EQF/modules/dashboard/sa/sa.php');
        exit;
    }

       $email = trim((string)$row['requester_email']);

    // marcar atendido
    $stU = $pdo->prepare("
      UPDATE password_recovery_requests
      SET status='ATENDIDO', attended_at=NOW(), attended_by=?
      WHERE id=?
    ");
    $stU->execute([(int)$_SESSION['user_id'], $id]);

    audit_log($pdo, 'RECOVERY_REQUEST_ATTENDED', 'password_recovery_requests', $id, [
      'requester_email' => $email
      
    ]);

    $pdo->commit();

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendRecoveryEmailToUser($email);
    }


} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

header('Location: /HelpDesk_EQF/modules/dashboard/sa/sa.php');
exit;
