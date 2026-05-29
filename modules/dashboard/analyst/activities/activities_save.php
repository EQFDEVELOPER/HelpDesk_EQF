<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Ruta absoluta blindada
require_once $_SERVER['DOCUMENT_ROOT'] . '/HelpDesk_EQF/config/connectionBD.php';
require_once __DIR__ . '/activities_send.php';

$analystId = (int)($_SESSION['user_id'] ?? 0);
$rol = (int)($_SESSION['user_rol'] ?? ($_SESSION['rol'] ?? 0));

if (!$analystId || $rol !== 3) {
    echo json_encode(['ok' => false, 'msg' => 'Sesión inválida.']);
    exit;
}

$activities = $_POST['activities'] ?? [];
if (!is_array($activities) || empty(array_filter($activities))) {
    echo json_encode(['ok' => false, 'msg' => 'No enviaste ninguna actividad válida.']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $pdo->beginTransaction();

    // 1. Insertar el reporte padre
    $stmt = $pdo->prepare("INSERT INTO daily_activity_reports (user_id) VALUES (?)");
    $stmt->execute([$analystId]);
    $reportId = $pdo->lastInsertId();

    // 2. Insertar cada una de las actividades en el bloque
    $stmtItem = $pdo->prepare("INSERT INTO daily_activity_items (report_id, activity) VALUES (?, ?)");
    foreach ($activities as $activity) {
        $cleanActivity = trim($activity);
        if ($cleanActivity !== '') {
            $stmtItem->execute([$reportId, $cleanActivity]);
        }
    }

    $pdo->commit();

    // 3. Enviar correo usando PHPMailer
    $userData = [
        'user_name'  => $_SESSION['user_name'] ?? 'Analista Soporte',
        'user_email' => $_SESSION['user_email'] ?? 'soporte@eqf.mx',
        'user_area'  => 'Mesa de Ayuda / TI',
        'activities' => array_filter($activities)
    ];
    
    sendDailyActivitiesEmail($userData);

    echo json_encode(['ok' => true, 'msg' => 'Guardado con éxito']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'msg' => 'Error al procesar: ' . $e->getMessage()]);
}