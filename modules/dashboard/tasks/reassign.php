<?php
session_start();
require_once __DIR__ . '/../../../config/connectionBD.php';
require_once __DIR__ . '/helpers/TaskEvents.php';

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
$adminId = (int)($_SESSION['user_id'] ?? 0);

$taskId = (int)($_POST['task_id'] ?? 0);
$newAid = (int)($_POST['new_assigned_to_user_id'] ?? 0);

if ($taskId <= 0 || $newAid <= 0) {
  $_SESSION['flash_err'] = 'Datos inválidos.';
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/admin.php');
  exit;
}

$pdo->beginTransaction();

try {
  // 1) Validar tarea pertenece al admin + obtener asignado actual
  $stmt = $pdo->prepare("
    SELECT id, title, assigned_to_user_id, status
    FROM tasks
    WHERE id = ? AND created_by_admin_id = ?
    LIMIT 1
  ");
  $stmt->execute([$taskId, $adminId]);
  $task = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$task) {
    $pdo->rollBack();
    $_SESSION['flash_err'] = 'No autorizado.';
    header('Location: /HelpDesk_EQF/modules/dashboard/tasks/admin.php');
    exit;
  }

  $oldAid = (int)($task['assigned_to_user_id'] ?? 0);
  $taskTitle = (string)($task['title'] ?? '');
  $oldStatus = strtoupper(trim((string)($task['status'] ?? '')));

  if ($oldAid === $newAid) {
    $pdo->rollBack();
    $_SESSION['flash_err'] = 'La tarea ya está asignada a ese analista.';
    header('Location: /HelpDesk_EQF/modules/dashboard/tasks/admin.php');
    exit;
  }

  if ($oldStatus === 'FINALIZADA') {
    $pdo->rollBack();
    $_SESSION['flash_err'] = 'No se puede reasignar una tarea finalizada.';
    header('Location: /HelpDesk_EQF/modules/dashboard/tasks/admin.php');
    exit;
  }

  // 2) Actualizar asignación en tasks (FUENTE DE VERDAD)
  $upd = $pdo->prepare("
    UPDATE tasks
       SET assigned_to_user_id = ?,
           status = 'ASIGNADA'
     WHERE id = ?
     LIMIT 1
  ");
  $upd->execute([$newAid, $taskId]);

  // 3) Notificaciones (si tu tabla notifications existe)
  // Si no existe, comenta este bloque.
  $link = "/HelpDesk_EQF/modules/dashboard/tasks/view.php?id=" . (int)$taskId;

  $stmtN = $pdo->prepare("
    INSERT INTO notifications (user_ide, type, title, body, link, is_read, created_at)
    VALUES (?, 'task_reassigned', ?, ?, ?, 0, NOW())
  ");

  // notificar anterior
  if ($oldAid > 0) {
    $stmtN->execute([
      $oldAid,
      "Tarea reasignada (#{$taskId})",
      "Te retiraron la tarea: " . ($taskTitle ?: 'Sin título'),
      $link
    ]);
  }

  // notificar nuevo
  $stmtN->execute([
    $newAid,
    "Tarea reasignada (#{$taskId})",
    "Se te asignó la tarea: " . ($taskTitle ?: 'Sin título'),
    $link
  ]);

  // 4) Evento
  // Usa tu helper. Si tu helper se llama distinto, dime y lo ajusto.
  if (function_exists('logTaskEvent')) {
    logTaskEvent(
      $pdo,
      $taskId,
      $adminId,
      'REASSIGNED',
      'Tarea reasignada',
      ['old_analyst_id' => $oldAid, 'old_status' => $oldStatus],
      ['new_analyst_id' => $newAid, 'new_status' => 'ASIGNADA']
    );
  }

  $pdo->commit();
  $_SESSION['flash_ok'] = 'Tarea reasignada.';
} catch (Throwable $e) {
  $pdo->rollBack();

  // Log real del error (temporal)
  @file_put_contents(__DIR__ . '/reassign_error.log',
    date('c') . " task_id={$taskId} admin_id={$adminId} -> " . $e->getMessage() . "\n",
    FILE_APPEND
  );

  $_SESSION['flash_err'] = 'Error al reasignar.';
}

header('Location: /HelpDesk_EQF/modules/dashboard/tasks/admin.php');
exit;