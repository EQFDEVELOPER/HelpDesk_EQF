<?php
// /HelpDesk_EQF/modules/dashboard/user/ajax/equipment_upload.php
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

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => 'Archivo requerido'], JSON_UNESCAPED_UNICODE);
  exit;
}

$f = $_FILES['file'];

if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => 'Error al subir archivo'], JSON_UNESCAPED_UNICODE);
  exit;
}

$maxBytes = 10 * 1024 * 1024; // 10MB
$size = (int)($f['size'] ?? 0);
if ($size <= 0 || $size > $maxBytes) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => 'Archivo demasiado grande (máx 10MB)'], JSON_UNESCAPED_UNICODE);
  exit;
}

$originalName = (string)($f['name'] ?? 'archivo');
$originalName = trim($originalName);
if ($originalName === '') $originalName = 'archivo';

$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

// Validar ext
$allowedExt = ['jpg','jpeg','png','pdf'];
if (!in_array($ext, $allowedExt, true)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => 'Formato no permitido. Usa JPG, PNG o PDF.'], JSON_UNESCAPED_UNICODE);
  exit;
}

// Validar MIME real con finfo
$tmpPath = (string)($f['tmp_name'] ?? '');
$mime = '';
if (is_file($tmpPath)) {
  $fi = new finfo(FILEINFO_MIME_TYPE);
  $mime = (string)$fi->file($tmpPath);
}

$allowedMime = [
  'jpg'  => ['image/jpeg'],
  'jpeg' => ['image/jpeg'],
  'png'  => ['image/png'],
  'pdf'  => ['application/pdf','application/x-pdf'],
];

if (!isset($allowedMime[$ext]) || ($mime !== '' && !in_array($mime, $allowedMime[$ext], true))) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => 'El archivo no coincide con el formato permitido'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  // Validar que la requisición pertenece al usuario y está en ENVIADO_*
  $stmt = $pdo->prepare("
    SELECT id, status
    FROM equipment_requests
    WHERE id = ? AND user_id = ?
    LIMIT 1
  ");
  $stmt->execute([$requestId, $userId]);
  $req = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$req) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Requisición no encontrada'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $status = strtoupper((string)($req['status'] ?? ''));
  if (!in_array($status, ['ENVIADO_DHL','ENVIADO_LEVIC'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Solo puedes adjuntar evidencia cuando el estatus sea ENVIADO'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Ruta física => /HelpDesk_EQF/uploads/equipment/
  // Este archivo está en /modules/dashboard/user/ajax/ => subir 4 niveles a raíz /HelpDesk_EQF
  $root = realpath(__DIR__ . '/../../../../'); // debería ser .../HelpDesk_EQF
  if ($root === false) throw new RuntimeException('No se encontró la ruta raíz');

  $dir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'equipment';
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  if (!is_dir($dir) || !is_writable($dir)) {
    throw new RuntimeException('No hay permisos de escritura en /uploads/equipment');
  }

  // Nombre seguro
  $safeBase = preg_replace('/[^a-zA-Z0-9_\-\.]+/', '_', pathinfo($originalName, PATHINFO_FILENAME));
  if ($safeBase === '' || $safeBase === '_') $safeBase = 'evidencia';

  $storedName = 'req_' . $requestId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
  $dest = $dir . DIRECTORY_SEPARATOR . $storedName;

  if (!move_uploaded_file($tmpPath, $dest)) {
    throw new RuntimeException('No se pudo guardar el archivo');
  }

  // Guardar en BD
  $stmtIns = $pdo->prepare("
    INSERT INTO equipment_request_files
      (request_id, uploaded_by_user_id, kind, original_name, stored_name, mime, size_bytes)
    VALUES
      (?, ?, 'EVIDENCIA_ENTREGA', ?, ?, ?, ?)
  ");
  $stmtIns->execute([
    $requestId,
    $userId,
    $originalName,
    $storedName,
    $mime !== '' ? $mime : null,
    $size
  ]);

  echo json_encode(['ok' => true, 'msg' => 'Evidencia subida correctamente'], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => $e->getMessage() ?: 'Error al subir archivo'], JSON_UNESCAPED_UNICODE);
}
