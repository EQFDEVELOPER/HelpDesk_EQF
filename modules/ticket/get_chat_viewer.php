<?php
session_start();
require_once __DIR__ . '/../../config/connectionBD.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['ok' => false, 'message' => 'No autorizado.']);
  exit;
}

$pdo = Database::getConnection();

$userId = (int)($_SESSION['user_id'] ?? 0);
$rol    = (int)($_SESSION['user_rol'] ?? ($_SESSION['rol'] ?? 0));

$ticketId = (int)($_GET['ticket_id'] ?? 0);
if ($ticketId <= 0) {
  echo json_encode(['ok' => false, 'message' => 'Ticket inválido.']);
  exit;
}

try {
  // 1) Ticket + permisos
  $stmt = $pdo->prepare("SELECT id, estado, user_id, asignado_a FROM tickets WHERE id = ? LIMIT 1");
  $stmt->execute([$ticketId]);
  $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$ticket) {
    echo json_encode(['ok' => false, 'message' => 'El ticket no existe.']);
    exit;
  }

  $isOwner    = ((int)$ticket['user_id'] === $userId);
  $isAssignee = ((int)($ticket['asignado_a'] ?? 0) === $userId);

  // Admin/SA (ajusta si tus roles son otros)
  $isAdminish = in_array($rol, [1,2], true);

  // Analista puede ver si es asignado; usuario si es dueño; admin/sa todo
  if (!$isOwner && !$isAssignee && !$isAdminish) {
    echo json_encode(['ok' => false, 'message' => 'No tienes permisos para ver este chat.']);
    exit;
  }

  $estado = strtolower((string)$ticket['estado']);
  $locked = in_array($estado, ['cerrado','resuelto'], true);

  // 2) Mensajes (OJO: mensaje/sender_id)
  $stmt = $pdo->prepare("
    SELECT
      m.id,
      m.ticket_id,
      m.sender_id,
      m.sender_role,
      m.mensaje,
      m.created_at,
      CONCAT(u.name, ' ', u.last_name) AS sender_name
    FROM ticket_messages m
    LEFT JOIN users u ON u.id = m.sender_id
    WHERE m.ticket_id = ?
    ORDER BY m.id ASC
  ");
  $stmt->execute([$ticketId]);
  $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // 3) Adjuntos (si hay)
  $stmt = $pdo->prepare("
    SELECT message_id, file_name, file_path, file_type
    FROM ticket_message_files
    WHERE ticket_id = ?
    ORDER BY id ASC
  ");
  $stmt->execute([$ticketId]);
  $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $filesByMsg = [];
  foreach ($files as $f) {
    $mid = (int)$f['message_id'];
    $path = (string)$f['file_path'];

    $url = '/HelpDesk_EQF/' . ltrim($path, '/');

    $filesByMsg[$mid][] = [
      'name' => $f['file_name'],
      'type' => $f['file_type'],
      'url'  => $url
    ];
  }

  // 4) Empaquetar para el front
  $out = [];
  foreach ($messages as $m) {
    $mid = (int)$m['id'];
    $out[] = [
      'id'          => $mid,
      'sender_id'   => (int)$m['sender_id'],
      'sender_role' => $m['sender_role'],
      'sender_name' => $m['sender_name'] ?: $m['sender_role'],
      'message'     => $m['mensaje'],
      'created_at'  => $m['created_at'],
      'attachments' => $filesByMsg[$mid] ?? []
    ];
  }

  echo json_encode([
    'ok' => true,
    'locked' => $locked,
    'messages' => $out
  ]);

} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'message' => 'Error interno al consultar chat.']);
}
