<?php
session_start();
require_once __DIR__ . '/../../../../config/connectionBD.php';
require_once __DIR__ . '/../../../../helpers/Mailer.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'msg' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
  exit;
}

if (
    !isset($_SESSION['user_id']) ||
    !in_array((int)($_SESSION['user_rol'] ?? 0), [2, 3, 4])
) {
    header('Location: /HelpDesk_EQF/auth/login.php');
    exit;

  http_response_code(403);
  echo json_encode(['ok' => false, 'msg' => 'Sin permisos'], JSON_UNESCAPED_UNICODE);
  exit;
}

$pdo = Database::getConnection();
$userId = (int)$_SESSION['user_id'];

function cleanStr($v): string {
  $s = trim((string)$v);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s;
}

try {
  // Datos reales del usuario desde BD
  $stmt = $pdo->prepare("SELECT email, name, last_name, area FROM users WHERE id = ? LIMIT 1");
  $stmt->execute([$userId]);
  $me = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$me) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Usuario no encontrado'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $email = cleanStr($me['email'] ?? '');
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Email inválido en tu perfil'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $comments = cleanStr($_POST['comments'] ?? '');
  $extraReason = trim((string)($_POST['extra_reason'] ?? ''));
  if (mb_strlen($extraReason) > 2000) $extraReason = mb_substr($extraReason, 0, 2000);

  $types = $_POST['item_type'] ?? [];
  $qtys  = $_POST['quantity'] ?? [];
  $descs = $_POST['description'] ?? [];

  if (!is_array($types) || !is_array($qtys) || !is_array($descs) || count($types) < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Selecciona al menos 1 equipo'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Construir items válidos
  $items = [];
  $n = max(count($types), count($qtys), count($descs));
  for ($i = 0; $i < $n; $i++) {
    $t = cleanStr($types[$i] ?? '');
    if ($t === '') continue;

    $q = (int)($qtys[$i] ?? 1);
    if ($q < 1) $q = 1;
    if ($q > 3) $q = 3; // por UI

    $d = cleanStr($descs[$i] ?? '');
    if (mb_strlen($d) > 500) $d = mb_substr($d, 0, 500);

    $items[] = ['item_type' => $t, 'quantity' => $q, 'description' => $d];
  }

  if (!$items) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Agrega al menos 1 item válido'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Reglas para razón (server-side)
  $distinct = [];
  $anyMaxQty = false;
  foreach ($items as $it) {
    $distinct[strtoupper($it['item_type'])] = true;
    if ((int)$it['quantity'] === 3) $anyMaxQty = true;
  }
  $distinctCount = count($distinct);

  $needsReason = $anyMaxQty || ($distinctCount > 3);
  if ($needsReason) {
    $r = trim($extraReason);
    if (mb_strlen($r) < 10) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'msg' => 'Debes escribir una razón (mínimo 10 caracteres)'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  } else {
    $extraReason = '';
  }

  $requesterName = cleanStr(($me['name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
  $requesterArea = cleanStr($me['area'] ?? '');

  $pdo->beginTransaction();

  // INSERT: guarda extra_reason
  $stmtIns = $pdo->prepare("
    INSERT INTO equipment_requests (user_id, requester_email, requester_name, requester_area, status, comments, extra_reason)
    VALUES (?, ?, ?, ?, 'VERIFICANDO', ?, ?)
  ");
  $stmtIns->execute([
    $userId,
    $email,
    $requesterName !== '' ? $requesterName : null,
    $requesterArea !== '' ? $requesterArea : null,
    $comments !== '' ? $comments : null,
    $extraReason !== '' ? $extraReason : null,
  ]);

  $reqId = (int)$pdo->lastInsertId();

  $stmtItem = $pdo->prepare("
    INSERT INTO equipment_request_items (request_id, item_type, description, quantity)
    VALUES (?, ?, ?, ?)
  ");

  foreach ($items as $it) {
    $stmtItem->execute([
      $reqId,
      $it['item_type'],
      $it['description'] !== '' ? $it['description'] : null,
      $it['quantity']
    ]);
  }

  $pdo->commit();

  // =================== CORREO (TEXTO PLANO) ===================
  $gerenteEmail = 'gerente-ti@eqf.mx';
  $to = [$gerenteEmail => 'Gerente TI'];
  $subject = 'Requisicion';

  $lines = [];
  $lines[] = "Nueva requisición de equipo";
  $lines[] = "";
  $lines[] = "Enviada por: {$email}";
  if ($requesterName !== '') $lines[] = "Nombre: {$requesterName}";
  $lines[] = "ID Requisición: #{$reqId}";
  $lines[] = "";
  $lines[] = "Items:";
  foreach ($items as $it) {
    $x = "{$it['quantity']} x {$it['item_type']}";
    if ($it['description'] !== '') $x .= " - {$it['description']}";
    $lines[] = " - " . $x;
  }
  if ($comments !== '') {
    $lines[] = "";
    $lines[] = "Comentarios:";
    $lines[] = $comments;
  }
  if ($extraReason !== '') {
    $lines[] = "";
    $lines[] = "Razón:";
    $lines[] = $extraReason;
  }

  $bodyText = implode("\r\n", $lines);

  try {
    sendMailEQF($to, $subject, $bodyText);
  } catch (Throwable $e) { /* no romper */ }

  // =================== NOTIFICACIÓN INTERNA (si existe) ===================
  try {
    $stmtU = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmtU->execute([$gerenteEmail]);
    $mgr = $stmtU->fetch(PDO::FETCH_ASSOC);

    if ($mgr && isset($mgr['id'])) {
      $mgrId = (int)$mgr['id'];

      $hasNotif = $pdo->query("SHOW TABLES LIKE 'notifications'")->fetchColumn();
      if ($hasNotif) {
        $title = "Requisición de equipo #{$reqId}";
        $body  = "Nueva requisición enviada por {$email}.";
        $link  = "/HelpDesk_EQF/modules/dashboard/admin/equipment.php";

        $cols = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
        $cols = array_map('strtolower', $cols);

        $fields = [];
        $vals = [];
        $params = [];

        if (in_array('user_id', $cols, true)) { $fields[]='user_id'; $vals[]='?'; $params[]=$mgrId; }
        if (in_array('title', $cols, true))   { $fields[]='title';   $vals[]='?'; $params[]=$title; }
        if (in_array('body', $cols, true))    { $fields[]='body';    $vals[]='?'; $params[]=$body; }
        if (in_array('msg', $cols, true))     { $fields[]='msg';     $vals[]='?'; $params[]=$body; }
        if (in_array('link', $cols, true))    { $fields[]='link';    $vals[]='?'; $params[]=$link; }
        if (in_array('url', $cols, true))     { $fields[]='url';     $vals[]='?'; $params[]=$link; }
        if (in_array('is_read', $cols, true)) { $fields[]='is_read'; $vals[]='?'; $params[]=0; }
        if (in_array('created_at', $cols, true)) { $fields[]='created_at'; $vals[]='NOW()'; }

        if (count($fields) >= 2) {
          $sql = "INSERT INTO notifications (" . implode(',', $fields) . ") VALUES (" . implode(',', $vals) . ")";
          $stmtN = $pdo->prepare($sql);
          $stmtN->execute($params);
        }
      }
    }
  } catch (Throwable $e) { /* no romper */ }

  echo json_encode(['ok' => true, 'id' => $reqId, 'msg' => 'Requisición enviada correctamente'], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => 'Error al crear requisición'], JSON_UNESCAPED_UNICODE);
}
