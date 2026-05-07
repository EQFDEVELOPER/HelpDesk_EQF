<?php
session_start();
require_once __DIR__ . '/../config/connectionBD.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../helpers/Mailer.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'Método no permitido.']);
    exit;
}

$email = trim($_POST['email'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'message' => 'Correo inválido.']);
    exit;
}

$domain = strtolower(substr(strrchr($email, "@"), 1));

if ($domain !== 'eqf.mx') {
    echo json_encode([
        'ok' => false,
        'message' => 'Correo invalido'
    ]);
    exit;
}


$pdo = Database::getConnection();

try {
    // 1) Guardar solicitud
    $st = $pdo->prepare("INSERT INTO password_recovery_requests (requester_email) VALUES (?)");
    $st->execute([$email]);

    $requestId = (int)$pdo->lastInsertId();

    // 2) Auditar creación
    audit_log($pdo, 'RECOVERY_REQUEST_CREATED', 'password_recovery_requests', $requestId, [
        'requester_email' => $email
    ]);

    // 3) Enviar correo a SA
    $subject = "Solicitud de recuperación de contraseña - HelpDesk EQF";
    $bodyText =
        "Buen día,\n\n" .
        "Se recibió una solicitud de recuperación de contraseña.\n\n" .
        "Usuario: {$email}\n\n" .
        "Servicio de soporte\n" .
        "HelpDesk EQF\n";

    $ok = sendMailEQF(
        ["soporte@eqf.mx" => "HelpDesk"],
        $subject,
        $bodyText
    );

    // 4) Auditar resultado del envío
    audit_log($pdo, $ok ? 'RECOVERY_EMAIL_SENT' : 'RECOVERY_EMAIL_FAILED', 'password_recovery_requests', $requestId, [
        'to' => 'soporte@eqf.mx',
        'requester_email' => $email
    ]);

    echo json_encode([
        'ok' => $ok,
        'message' => $ok
            ? 'Listo. Se registró tu solicitud y se notificó al Administrador.'
            : 'Se registró la solicitud, pero no se pudo enviar el correo. (Revisar SMTP)'
    ]);
    exit;

} catch (Throwable $e) {
    // Si algo falla (BD / audit / etc)
    error_log('RECOVERY REQUEST ERROR: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'No se pudo registrar la solicitud.']);
    exit;
}
