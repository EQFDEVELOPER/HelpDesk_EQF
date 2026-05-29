<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Ruta absoluta blindada
require_once $_SERVER['DOCUMENT_ROOT'] . '/HelpDesk_EQF/config/connectionBD.php';

// Validar sesión de manera idéntica a analyst.php
$analystId = (int)($_SESSION['user_id'] ?? 0);
$rol = (int)($_SESSION['user_rol'] ?? ($_SESSION['rol'] ?? 0));

if (!$analystId || $rol !== 3) {
    echo json_encode(['ok' => false, 'msg' => 'Sesión no válida o expirada.']);
    exit;
}

$reportId = (int)($_GET['id'] ?? 0);

try {
    $pdo = Database::getConnection();

    // =========================================================================
    // CASO A: DETALLE DEL MODAL (Cuando das clic en "Ver")
    // =========================================================================
    if ($reportId > 0) {
        $stmt = $pdo->prepare("
            SELECT i.id, i.activity 
            FROM daily_activity_items i
            JOIN daily_activity_reports r ON r.id = i.report_id
            WHERE i.report_id = ? AND r.user_id = ?
            ORDER BY i.id ASC
        ");
        $stmt->execute([$reportId, $analystId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode(['ok' => true, 'activities' => $items]);
        exit;
    } 
    
    // =========================================================================
    // CASO B: LISTADO PARA LA DATATABLE
    // =========================================================================
    else {
        $stmt = $pdo->prepare("
            SELECT 
                r.id, 
                MAX(i.created_at) AS created_at, 
                COUNT(i.id) AS total_activities
            FROM daily_activity_reports r
            LEFT JOIN daily_activity_items i ON r.id = i.report_id
            WHERE r.user_id = ?
            GROUP BY r.id
            ORDER BY r.id DESC
        ");
        $stmt->execute([$analystId]);
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode(['ok' => true, 'activities' => $reports]);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => 'Error de consulta: ' . $e->getMessage()]);
}