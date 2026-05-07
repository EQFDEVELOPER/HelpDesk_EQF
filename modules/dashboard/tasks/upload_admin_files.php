<?php
// /HelpDesk_EQF/modules/dashboard/tasks/upload_admin_files.php
session_start();
require_once __DIR__ . '/../../../config/connectionBD.php';
require_once __DIR__ . '/helpers/TaskEvents.php';
require_once __DIR__ . '/helpers/TaskFiles.php';

if (!isset($_SESSION['user_id']) || (int)($_SESSION['user_rol'] ?? 0) !== 2) {
    header('Location: /HelpDesk_EQF/auth/login.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /HelpDesk_EQF/modules/dashboard/tasks/admin.php'); exit;
}

$pdo = Database::getConnection();
$adminId = (int)$_SESSION['user_id'];

$taskId = (int)($_POST['task_id'] ?? 0);
if ($taskId <= 0) {
    header('Location: /HelpDesk_EQF/modules/dashboard/tasks/admin.php'); exit;
}

// valida que la tarea es del admin
$stmt = $pdo->prepare("SELECT id FROM tasks WHERE id=? AND created_by_admin_id=?");
$stmt->execute([$taskId, $adminId]);
if (!$stmt->fetch()) {
    header('Location: /HelpDesk_EQF/modules/dashboard/tasks/admin.php'); exit;
}

try {
    if (empty($_FILES['admin_files']['name'][0])) {
        header('Location: /HelpDesk_EQF/modules/dashboard/tasks/view.php?id=' . $taskId); exit;
    }

    $baseDir = __DIR__ . '/../../../uploads/tasks/admin/';
    $allowed = ['pdf','png','jpg','jpeg','webp','doc','docx','xls','xlsx','ppt','pptx','txt','zip','rar'];
    $saved = saveUploadedFiles($_FILES['admin_files'], $baseDir, $allowed);

    $stmtF = $pdo->prepare("
        INSERT INTO task_files (task_id, uploaded_by_user_id, file_type, original_name, stored_name, mime, size_bytes)
        VALUES (?, ?, 'ADMIN_ATTACHMENT', ?, ?, ?, ?)
    ");

    foreach ($saved as $s) {
        $stmtF->execute([$taskId, $adminId, $s['original_name'], $s['stored_name'], $s['mime'], $s['size_bytes']]);
        logTaskEvent($pdo, $taskId, $adminId, 'ADMIN_FILE_ADDED', 'Adjunto agregado', null, ['file' => $s['original_name']]);
    }

    header('Location: /HelpDesk_EQF/modules/dashboard/tasks/view.php?id=' . $taskId);
    exit;

} catch (Throwable $e) {
    header('Location: /HelpDesk_EQF/modules/dashboard/tasks/view.php?id=' . $taskId);
    exit;
}
// Notificar al analista SOLO si se guardaron archivos admin
if (!empty($savedNames)) { // tu array real de guardados
  $st = $pdo->prepare("SELECT assigned_to_user_id FROM tasks WHERE id = ? LIMIT 1");
  $st->execute([$taskId]);
  $assignedId = (int)($st->fetchColumn() ?: 0);

  if ($assignedId > 0) {
    $count = count($savedNames);

    notifyUser(
      $pdo,
      $assignedId,
      "Adjunto del admin",
      "El admin adjuntó {$count} archivo(s) en la tarea (#{$taskId})",
      "/HelpDesk_EQF/modules/dashboard/tasks/analyst.php"
    );
  }
}

