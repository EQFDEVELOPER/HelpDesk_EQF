<?php

session_start();

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../../config/connectionBD.php';

$conn = Database::getConnection();

if (!isset($_SESSION['user_id'])) {

    http_response_code(401);

    echo json_encode([
        'ok' => false,
        'msg' => 'No autorizado'
    ]);

    exit;
}

$userEmail = strtolower(trim($_SESSION['user_email'] ?? ''));

$allowedEmails = [
    'proyectos@eqf.mx',
    'aux.proyectos@eqf.mx'
];

if (!in_array($userEmail, $allowedEmails, true)) {

    http_response_code(403);

    echo json_encode([
        'ok' => false,
        'msg' => 'Sin permisos'
    ]);

    exit;
}
$requestId = (int)($_POST['request_id'] ?? 0);

$status = strtoupper(trim($_POST['status'] ?? ''));

$cancelReason = trim($_POST['cancel_reason'] ?? '');

$validStatus = [
    'PENDIENTE',
    'EN_PROCESO',
    'FINALIZADO',
    'CANCELADO'
];
if ($requestId <= 0 || !in_array($status, $validStatus, true)) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'msg' => 'Datos inválidos'
    ]);

    exit;
}

/* =========================================
   VALIDAR MOTIVO CANCELACIÓN
========================================= */

if (
    $status === 'CANCELADO' &&
    $cancelReason === ''
) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'msg' => 'Debes escribir un motivo de cancelación'
    ]);

    exit;
}

try {
/* =========================================
   OBTENER USUARIO DE LA SOLICITUD
========================================= */

$stmtReq = $conn->prepare("
    SELECT
    id,
    requester_email,
    status
FROM maintenance_requests
    WHERE id = ?
    LIMIT 1
");

$stmtReq->execute([$requestId]);

$requestData = $stmtReq->fetch(PDO::FETCH_ASSOC);

if (!$requestData) {

    throw new Exception('Solicitud no encontrada');
}

$requesterEmail = trim((string)$requestData['requester_email']);

/* =========================================
   BUSCAR USER_ID POR EMAIL
========================================= */

$stmtUser = $conn->prepare("
    SELECT id
    FROM users
    WHERE email = ?
    LIMIT 1
");

$stmtUser->execute([$requesterEmail]);

$userData = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$userData) {

    throw new Exception('Usuario no encontrado para encuesta');
}

$requestUserId = (int)$userData['id'];
$currentStatus = strtoupper(trim($requestData['status']));
    $sql = "
    UPDATE maintenance_requests
    SET
        status = :status,
        cancel_reason = :cancel_reason
    WHERE id = :id
    LIMIT 1
";

$stmt = $conn->prepare($sql);

$stmt->execute([
    ':status'        => $status,
    ':cancel_reason' => $cancelReason ?: null,
    ':id'            => $requestId
]);
/* =========================================
   GENERAR ENCUESTA AL FINALIZAR
========================================= */

if (
    $status === 'FINALIZADO' &&
    $currentStatus !== 'FINALIZADO'
) {

    // evitar duplicados
    $stmtCheck = $conn->prepare("
        SELECT id
        FROM maintenance_feedback
        WHERE maintenance_request_id = ?
        LIMIT 1
    ");

    $stmtCheck->execute([$requestId]);

    $existsFeedback = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$existsFeedback) {

        $token = bin2hex(random_bytes(32));

        $stmtFeedback = $conn->prepare("
            INSERT INTO maintenance_feedback (
                maintenance_request_id,
                user_id,
                token
            )
            VALUES (?, ?, ?)
        ");

        $stmtFeedback->execute([
            $requestId,
            $requestUserId,
            $token
        ]);

        /* =========================================
           LINK ENCUESTA
        ========================================== */

        $feedbackLink =
            'http://localhost/HelpDesk_EQF/modules/feedback/feedback_maintenance.php?token='
            . $token;

        // DEBUG TEMPORAL
        error_log('ENCUESTA MANTENIMIENTO: ' . $feedbackLink);

        /*
        AQUÍ DESPUÉS:
        - correo
        - notificación
        - whatsapp
        etc
        */
    }
}
    echo json_encode([
        'ok' => true,
        'msg' => 'Estatus actualizado correctamente'
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'msg' => $e->getMessage()
    ]);
}
