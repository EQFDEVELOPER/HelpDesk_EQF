<?php
// /HelpDesk_EQF/modules/dashboard/admin/ajax/equipment_list.php
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

$pdo = Database::getConnection();

try {
  $stmt = $pdo->query("
    SELECT
      id,
      requester_email,
      status,
      created_at
    FROM equipment_requests
    ORDER BY created_at DESC
    LIMIT 60
  ");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Normalizar salida
  $out = [];
  foreach ($rows as $r) {
    $out[] = [
      'id' => (int)($r['id'] ?? 0),
      'requester_email' => (string)($r['requester_email'] ?? ''),
      'status' => (string)($r['status'] ?? ''),
      'created_at' => (string)($r['created_at'] ?? ''),
    ];
  }

  echo json_encode(['ok' => true, 'rows' => $out], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => 'Error al cargar requisiciones'], JSON_UNESCAPED_UNICODE);
}
