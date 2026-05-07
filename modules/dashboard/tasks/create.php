<?php
session_start();
require_once __DIR__ . '/../../../config/connectionBD.php';

$rol = (int)($_SESSION['user_rol'] ?? ($_SESSION['rol'] ?? 0));
if (!isset($_SESSION['user_id']) || $rol !== 2) {
  header('Location: /HelpDesk_EQF/auth/login.php');
  exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/admin.php');
  exit;
}

$pdo = Database::getConnection();

$adminId   = (int)($_SESSION['user_id'] ?? 0);
$adminArea = trim((string)($_SESSION['user_area'] ?? ($_SESSION['area'] ?? '')));

$assignedTo = (int)($_POST['assigned_to_user_id'] ?? 0);
$dueAt      = trim((string)($_POST['due_at'] ?? ''));
$title      = trim((string)($_POST['title'] ?? ''));
$priorityId = (int)($_POST['priority_id'] ?? 0);
$desc       = trim((string)($_POST['description'] ?? ''));

if ($assignedTo <= 0 || $priorityId <= 0 || $dueAt === '' || $title === '' || $desc === '') {
  $_SESSION['flash_err'] = 'Completa todos los campos obligatorios.';
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/admin.php');
  exit;
}

$dt = DateTime::createFromFormat('d/m/Y H:i', $dueAt);
if (!$dt) {
  $_SESSION['flash_err'] = 'Fecha/hora inválida.';
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/admin.php');
  exit;
}
$dueAtDB = $dt->format('Y-m-d H:i:s');

// validar que el analista exista y sea de MI área
$stmtCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND rol = 3 AND area = ? LIMIT 1");
$stmtCheck->execute([$assignedTo, $adminArea]);
if (!$stmtCheck->fetchColumn()) {
  $_SESSION['flash_err'] = 'El analista seleccionado no es válido o no pertenece a tu área.';
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/admin.php');
  exit;
}

try {
  $pdo->beginTransaction();

  // crear tarea
  $stmt = $pdo->prepare("
    INSERT INTO tasks
      (created_by_admin_id, assigned_to_user_id, priority_id, title, description, due_at, status, created_at)
    VALUES
      (?, ?, ?, ?, ?, ?, 'ASIGNADA', NOW())
  ");
  $stmt->execute([$adminId, $assignedTo, $priorityId, $title, $desc, $dueAtDB]);

  $taskId = (int)$pdo->lastInsertId();

$link = "/HelpDesk_EQF/modules/dashboard/tasks/view.php?id=" . (int)$taskId;

$stmtN = $pdo->prepare("
  INSERT INTO notifications (user_ide, type, title, body, link, is_read, created_at)
  VALUES (?, 'task_assigned', ?, ?, ?, 0, NOW())
");
$stmtN->execute([
  (int)$assignedTo,
  "Nueva tarea (#{$taskId})",
  (string)$title,
  $link
]);

// Notificar al analista asignado
$assignedId = (int)($_POST['$assignedTo'] ?? 0); // o de donde lo tomes
if ($assignedId > 0) {
  notifyUser(
    $pdo,
    $assignedId,
    "Nueva tarea asignada",
    "Tienes una nueva tarea (#{$taskId})",
    "/HelpDesk_EQF/modules/dashboard/tasks/analyst.php"
  );
}


  // adjuntos admin (si vienen)
  if (!empty($_FILES['admin_files']) && !empty($_FILES['admin_files']['name'][0])) {
    $uploadDir = __DIR__ . '/../../../uploads/tasks/admin/';
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

    $stmtFile = $pdo->prepare("
      INSERT INTO task_files
        (task_id, uploaded_by_user_id, file_type, original_name, stored_name, mime, size_bytes, created_at, is_deleted)
      VALUES
        (?, ?, 'ADMIN_ATTACHMENT', ?, ?, ?, ?, NOW(), 0)
    ");

    $filesCount = count($_FILES['admin_files']['name']);
    for ($i = 0; $i < $filesCount; $i++) {
      if (($_FILES['admin_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

      $orig = (string)$_FILES['admin_files']['name'][$i];
      $tmp  = (string)$_FILES['admin_files']['tmp_name'][$i];
      $mime = (string)($_FILES['admin_files']['type'][$i] ?? 'application/octet-stream');
      $size = (int)($_FILES['admin_files']['size'][$i] ?? 0);

      $ext = pathinfo($orig, PATHINFO_EXTENSION);
      $stored = 'admin_' . $taskId . '_' . bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');

      if (!move_uploaded_file($tmp, $uploadDir . $stored)) continue;

      $stmtFile->execute([$taskId, $adminId, $orig, $stored, $mime, $size]);
    }
  }

  $pdo->commit();

  $_SESSION['flash_ok'] = 'Tarea creada y asignada.';
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/admin.php');
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash_err'] = 'Error al crear la tarea.';
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/admin.php');
  exit;
}
