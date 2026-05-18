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

    $userEmail = strtolower(trim($_SESSION['user_email'] ?? ''));

    // Correos de proyectos
    $allowedEmails = [
        'proyectos@eqf.mx',
        'aux.proyectos@eqf.mx'
    ];

    // PROYECTOS VE TODO
    if (in_array($userEmail, $allowedEmails, true)) {

        $sql = "
            SELECT
                id,
                requester_email,
                title,
                description,
                status,
                created_at
            FROM maintenance_requests
            ORDER BY id DESC
        ";

        $stmt = $conn->prepare($sql);

    } else {

        // USUARIO NORMAL → SOLO SUS SOLICITUDES
        $sql = "
            SELECT
                id,
                requester_email,
                title,
                description,
                status,
                created_at
            FROM maintenance_requests
            WHERE LOWER(requester_email) = :email
            ORDER BY id DESC
        ";

        $stmt = $conn->prepare($sql);

        $stmt->bindValue(':email', $userEmail);
    }

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