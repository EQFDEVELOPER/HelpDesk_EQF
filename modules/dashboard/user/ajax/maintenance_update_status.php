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
if ($status === 'CANCELADO' && $cancelReason === '') {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'msg' => 'Debes escribir un motivo de cancelación'
    ]);

    exit;
}
    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'msg' => 'Datos inválidos'
    ]);

    exit;
}

try {

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
