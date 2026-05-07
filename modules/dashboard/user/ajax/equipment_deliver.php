<?php
// /HelpDesk_EQF/modules/dashboard/user/ajax/equipment_deliver.php
session_start();
require_once __DIR__ . '/../../../../config/connectionBD.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'msg' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
  exit;
}

$userId = (int)$_SESSION['user_id'];
$pdo = Database::getConnection();

$requestId = (int)($_POST['request_id'] ?? 0);
if ($requestId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => 'ID inválido'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo->beginTransaction();

  // bloquear registro
  $stmt = $pdo->prepare("
    SELECT id, status
    FROM equipment_requests
    WHERE id = ? AND user_id = ?
    FOR UPDATE
  ");
  $stmt->execute([$requestId, $userId]);
  $req = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$req) {
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Requisición no encontrada'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $status = strtoupper((string)($req['status'] ?? ''));
  if (!in_array($status, ['ENVIADO_DHL', 'ENVIADO_LEVIC'], true)) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Solo puedes marcar entregado cuando el estatus sea ENVIADO'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Debe existir evidencia
  $stmt2 = $pdo->prepare("
    SELECT COUNT(*) c
    FROM equipment_request_files
    WHERE request_id = ?
  ");
  $stmt2->execute([$requestId]);
  $count = (int)($stmt2->fetchColumn() ?: 0);

  if ($count < 1) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Adjunta evidencia antes de marcar como entregado'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // actualizar
  $stmt3 = $pdo->prepare("
    UPDATE equipment_requests
    SET status = 'ENTREGADO',
        delivered_at = NOW(),
        delivered_by_user_id = ?
    WHERE id = ? AND user_id = ?
  ");
  $stmt3->execute([$userId, $requestId, $userId]);

  $pdo->commit();

  echo json_encode(['ok' => true, 'msg' => 'Requisición marcada como ENTREGADO'], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => 'Error al marcar entregado'], JSON_UNESCAPED_UNICODE);
}
