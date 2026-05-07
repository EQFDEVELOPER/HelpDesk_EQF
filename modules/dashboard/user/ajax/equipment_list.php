<?php
// /HelpDesk_EQF/modules/dashboard/user/ajax/equipment_list.php
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

try {
  // Requisiciones del usuario
  $stmt = $pdo->prepare("
    SELECT
      r.id,
      r.status,
      r.comments,
      r.created_at
    FROM equipment_requests r
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
    LIMIT 30
  ");
  $stmt->execute([$userId]);
  $reqs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  if (!$reqs) {
    echo json_encode(['ok' => true, 'rows' => []], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $ids = array_map(fn($x) => (int)$x['id'], $reqs);
  $placeholders = implode(',', array_fill(0, count($ids), '?'));

  // Items de esas requisiciones
  $stmt2 = $pdo->prepare("
    SELECT
      request_id,
      item_type,
      description,
      quantity
    FROM equipment_request_items
    WHERE request_id IN ($placeholders)
    ORDER BY id ASC
  ");
  $stmt2->execute($ids);
  $items = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $byReq = [];
  foreach ($items as $it) {
    $rid = (int)$it['request_id'];
    if (!isset($byReq[$rid])) $byReq[$rid] = [];
    $byReq[$rid][] = [
      'item_type'   => (string)($it['item_type'] ?? ''),
      'description' => (string)($it['description'] ?? ''),
      'quantity'    => (int)($it['quantity'] ?? 1),
    ];
  }

  // Normalizar salida
  $rows = [];
  foreach ($reqs as $r) {
    $rid = (int)$r['id'];
    $rows[] = [
      'id'         => $rid,
      'status'     => (string)($r['status'] ?? ''),
      'comments'   => (string)($r['comments'] ?? ''),
      // string simple para UI
      'created_at' => (string)($r['created_at'] ?? ''),
      'items'      => $byReq[$rid] ?? [],
    ];
  }

  echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => 'Error al cargar requisiciones'], JSON_UNESCAPED_UNICODE);
}
