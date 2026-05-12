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

try {

    $sql = "
        SELECT
            id,
            requester_email,
            title,
            description,
            status
        FROM maintenance_requests
        ORDER BY id DESC
    ";

    $stmt = $conn->prepare($sql);

    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'rows' => $rows
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'msg' => $e->getMessage()
    ]);
}