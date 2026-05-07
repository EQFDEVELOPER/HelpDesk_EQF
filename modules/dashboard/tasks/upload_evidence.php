<?php
session_start();
require_once __DIR__ . '/../../../config/connectionBD.php';
require_once __DIR__ . '/helpers/TaskEvents.php';
require_once __DIR__ . '/helpers/TaskFiles.php';

function currentRole(): int {
  if (isset($_SESSION['user_rol'])) return (int)$_SESSION['user_rol'];
  if (isset($_SESSION['rol']))      return (int)$_SESSION['rol'];
  return 0;
}

if (!isset($_SESSION['user_id']) || currentRole() !== 3) {
  header('Location: /HelpDesk_EQF/auth/login.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/analyst.php');
  exit;
}

$pdo = Database::getConnection();
$analystId = (int)$_SESSION['user_id'];
$taskId    = (int)($_POST['task_id'] ?? 0);

if ($taskId <= 0) {
  $_SESSION['flash_err'] = 'Tarea inválida.';
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/analyst.php');
  exit;
}

// validar que sea su tarea y esté EN_PROCESO
$stmt = $pdo->prepare("
  SELECT status
  FROM tasks
  WHERE id = ?
    AND assigned_to_user_id = ?
  LIMIT 1
");
$stmt->execute([$taskId, $analystId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || ($row['status'] ?? '') !== 'EN_PROCESO') {
  $_SESSION['flash_err'] = 'No puedes subir evidencia en esta tarea.';
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/analyst.php');
  exit;
}

if (empty($_FILES['evidence_files']) || empty($_FILES['evidence_files']['name'][0])) {
  $_SESSION['flash_err'] = 'Selecciona al menos un archivo.';
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/analyst.php');
  exit;
}

$baseDir = __DIR__ . '/../../../uploads/tasks/evidence/';
if (!is_dir($baseDir)) {
  @mkdir($baseDir, 0775, true);
}

$allowed = ['pdf','png','jpg','jpeg','webp','doc','docx','xls','xlsx','ppt','pptx','txt','zip','rar'];

// Avisar al admin (solo si sí se guardaron archivos)
if (!empty($savedNames)) { // usa tu variable real (array) de guardados
  $st = $pdo->prepare("
    SELECT t.created_by_admin_id,
           CONCAT(u.name,' ',u.last_name) AS analyst_name
    FROM tasks t
    JOIN users u ON u.id = t.assigned_to_user_id
    WHERE t.id = ?
    LIMIT 1
  ");
  $st->execute([$taskId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if ($row) {
    $adminId = (int)$row['created_by_admin_id'];
    $analystName = trim($row['analyst_name'] ?? 'Analista');
    $count = count($savedNames);

    notifyUser(
      $pdo,
      $adminId,
      "Evidencia agregada",
      "{$analystName} adjuntó {$count} evidencia(s) en la tarea (#{$taskId})",
      "/HelpDesk_EQF/modules/dashboard/tasks/view.php?id={$taskId}"
    );
  }
}


try {
  $pdo->beginTransaction();

  $saved = saveUploadedFiles($_FILES['evidence_files'], $baseDir, $allowed);

  if (empty($saved)) {
    throw new RuntimeException('No se pudo guardar ningún archivo.');
  }

  $stmtF = $pdo->prepare("
    INSERT INTO task_files
      (task_id, task_assignee_id, uploaded_by_user_id, file_type, original_name, stored_name, mime, size_bytes, created_at, is_deleted)
    VALUES
      (?, NULL, ?, 'EVIDENCE', ?, ?, ?, ?, NOW(), 0)
  ");

  foreach ($saved as $s) {
    $stmtF->execute([
      $taskId,
      $analystId,
      $s['original_name'],
      $s['stored_name'],
      $s['mime'],
      $s['size_bytes']
    ]);

    logTaskEvent(
      $pdo,
      $taskId,
      $analystId,
      'EVIDENCE_ADDED',
      'Evidencia agregada',
      null,
      ['file' => $s['original_name']]
    );
  }

  $pdo->commit();

  $_SESSION['flash_ok'] = 'Evidencia subida.';
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/analyst.php');
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash_err'] = 'Error al subir evidencia.';
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/analyst.php');
  exit;
}
