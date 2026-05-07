<?php
session_start();
require_once __DIR__ . '/../config/connectionBD.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'msg'=>'No autenticado'], JSON_UNESCAPED_UNICODE);
  exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (
  !$data ||
  empty($data['endpoint']) ||
  empty($data['keys']['p256dh']) ||
  empty($data['keys']['auth'])
) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'msg'=>'Datos inválidos'], JSON_UNESCAPED_UNICODE);
  exit;
}

$userId   = (int)$_SESSION['user_id'];
$endpoint = (string)$data['endpoint'];
$p256dh   = (string)$data['keys']['p256dh'];
$auth     = (string)$data['keys']['auth'];

$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

// si tu área está en sesión:
$area = (string)($_SESSION['user_area'] ?? '');
if ($area === '') $area = 'N/A';

$pdo = Database::getConnection();

/**
 * IMPORTANTE:
 * Para que el ON DUPLICATE funcione bien, tu tabla debe tener un UNIQUE KEY
 * por ejemplo (user_id, endpoint) o al menos endpoint.
 */
$sql = "
  INSERT INTO push_subscriptions (user_id, area, endpoint, p256dh, auth, user_agent, created_at, updated_at)
  VALUES (:user_id, :area, :endpoint, :p256dh, :auth, :ua, NOW(), NOW())
  ON DUPLICATE KEY UPDATE
    area      = VALUES(area),
    p256dh    = VALUES(p256dh),
    auth      = VALUES(auth),
    user_agent= VALUES(user_agent),
    updated_at= NOW()
";
$st = $pdo->prepare($sql);
$st->execute([
  ':user_id' => $userId,
  ':area'    => $area,
  ':endpoint'=> $endpoint,
  ':p256dh'  => $p256dh,
  ':auth'    => $auth,
  ':ua'      => $ua,
]);

echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
