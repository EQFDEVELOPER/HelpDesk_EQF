<?php
session_start();

require_once __DIR__ . '/../../../config/connectionBD.php';

header('Content-Type: application/json; charset=utf-8');

try {

    if (!isset($_SESSION['user_id'])) {
        echo json_encode([
            'ok' => false,
            'msg' => 'Sesión inválida'
        ]);
        exit;
    }

    $pdo = Database::getConnection();

    $reportId = (int)($_GET['id'] ?? 0);
    $userId   = (int)$_SESSION['user_id'];

    // Validar que el reporte sea del usuario
    $stmt = $pdo->prepare("
        SELECT id
        FROM daily_activity_reports
        WHERE id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $stmt->execute([$reportId, $userId]);

    if (!$stmt->fetch()) {

        echo json_encode([
            'ok' => false,
            'msg' => 'Reporte no encontrado'
        ]);

        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            activity,
            created_at
        FROM daily_activity_items
        WHERE report_id = ?
        ORDER BY id ASC
    ");

    $stmt->execute([$reportId]);

    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'activities' => $activities
    ]);

} catch (Throwable $e) {

    echo json_encode([
        'ok' => false,
        'msg' => $e->getMessage()
    ]);

}