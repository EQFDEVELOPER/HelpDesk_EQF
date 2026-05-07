<?php
// /HelpDesk_EQF/modules/dashboard/user/ajax/equipment_file.php
session_start();
require_once __DIR__ . '/../../../../config/connectionBD.php';

if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo "No autenticado";
  exit;
}

$userId = (int)$_SESSION['user_id'];
$userRol = (int)($_SESSION['user_rol'] ?? 0);
if (!$userId || !in_array($userRol, [2, 3, 4])) {
    header('Location: /HelpDesk_EQF/auth/login.php');
    exit;
}
$fileId = (int)($_GET['id'] ?? 0);
if ($fileId <= 0) {
  http_response_code(400);
  echo "ID inválido";
  exit;
}

$pdo = Database::getConnection();

try {
  // Traer archivo + owner
  $stmt = $pdo->prepare("
    SELECT
      f.id,
      f.request_id,
      f.original_name,
      f.stored_name,
      f.mime,
      f.size_bytes,
      r.user_id AS owner_id
    FROM equipment_request_files f
    INNER JOIN equipment_requests r ON r.id = f.request_id
    WHERE f.id = ?
    LIMIT 1
  ");
  $stmt->execute([$fileId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    http_response_code(404);
    echo "Archivo no encontrado";
    exit;
  }

  $ownerId = (int)($row['owner_id'] ?? 0);

  // Permisos: owner OR rol 2/3
  $isOwner = ($ownerId === $userId);
  $isPriv  = in_array($userRol, [2,3], true);

  if (!$isOwner && !$isPriv) {
    http_response_code(403);
    echo "Sin permisos";
    exit;
  }

  // Ruta física /HelpDesk_EQF/uploads/equipment/
  $root = realpath(__DIR__ . '/../../../../'); // .../HelpDesk_EQF
  if ($root === false) {
    http_response_code(500);
    echo "Ruta inválida";
    exit;
  }

  $stored = basename((string)($row['stored_name'] ?? ''));
  if ($stored === '') {
    http_response_code(500);
    echo "Archivo inválido";
    exit;
  }

  $path = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'equipment' . DIRECTORY_SEPARATOR . $stored;

  if (!is_file($path)) {
    http_response_code(404);
    echo "Archivo no disponible";
    exit;
  }

  $mime = (string)($row['mime'] ?? '');
  if ($mime === '') {
    // fallback
    $mime = 'application/octet-stream';
  }

  $downloadName = (string)($row['original_name'] ?? 'archivo');
  if ($downloadName === '') $downloadName = 'archivo';

  header('Content-Description: File Transfer');
  header('Content-Type: ' . $mime);
  header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
  header('Content-Length: ' . filesize($path));
  header('Cache-Control: no-store, no-cache, must-revalidate');
  header('Pragma: no-cache');

  readfile($path);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo "Error";
  exit;
}
