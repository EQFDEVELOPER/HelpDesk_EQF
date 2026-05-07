<?php
// /HelpDesk_EQF/modules/dashboard/admin/ajax/equipment_update_status.php
session_start();
require_once __DIR__ . '/../../../../config/connectionBD.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'msg' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
  exit;
}

$rol = (int)($_SESSION['user_rol'] ?? 0);
if (!in_array($rol, [2,3], true)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'msg' => 'Sin permisos'], JSON_UNESCAPED_UNICODE);
  exit;
}

$adminId = (int)$_SESSION['user_id'];
$pdo = Database::getConnection();

$id = (int)($_POST['id'] ?? 0);
$mode = strtoupper(trim((string)($_POST['mode'] ?? '')));

if ($id <= 0 || !in_array($mode, ['DHL','LEVIC'], true)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => 'Parámetros inválidos'], JSON_UNESCAPED_UNICODE);
  exit;
}

$newStatus = $mode === 'DHL' ? 'ENVIADO_DHL' : 'ENVIADO_LEVIC';

try {
  $pdo->beginTransaction();

  // Bloquear registro
  $stmt = $pdo->prepare("
    SELECT status
    FROM equipment_requests
    WHERE id = ?
    FOR UPDATE
  ");
  $stmt->execute([$id]);
  $cur = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$cur) {
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Requisición no encontrada'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $currentStatus = strtoupper((string)($cur['status'] ?? ''));

if ($currentStatus !== 'VERIFICANDO') {
  $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => 'Esta requisición ya fue procesada'], JSON_UNESCAPED_UNICODE);
  exit;
}


  // Actualizar
  $stmt2 = $pdo->prepare("
    UPDATE equipment_requests
    SET status = ?,
        shipped_at = NOW(),
        shipped_by_admin_id = ?
    WHERE id = ?
  ");
  $stmt2->execute([$newStatus, $adminId, $id]);

  $pdo->commit();

  echo json_encode([
    'ok'  => true,
    'msg' => 'Estatus actualizado a ' . ($mode === 'DHL' ? 'ENVIADO DHL' : 'ENVIADO LEVIC')
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => 'Error al actualizar estatus'], JSON_UNESCAPED_UNICODE);
}
