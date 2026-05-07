<?php
session_start();
require_once __DIR__ . '/../../../config/connectionBD.php';
require_once __DIR__ . '/helpers/TaskEvents.php';

$rol = (int)($_SESSION['user_rol'] ?? ($_SESSION['rol'] ?? 0));
if (!isset($_SESSION['user_id']) || $rol !== 3) {
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

$pdo->beginTransaction();

try {
  // 1) Leer tarea actual
  $stmt = $pdo->prepare("
    SELECT status, finished_at
    FROM tasks
    WHERE id = ?
      AND assigned_to_user_id = ?
    LIMIT 1
  ");
  $stmt->execute([$taskId, $analystId]);
  $task = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$task || ($task['status'] ?? '') !== 'EN_PROCESO') {
    throw new RuntimeException('No puedes finalizar esta tarea.');
  }

  // 2) Actualizar a FINALIZADA
  $now = date('Y-m-d H:i:s');

  $upd = $pdo->prepare("
    UPDATE tasks
    SET status = 'FINALIZADA',
        finished_at = ?
    WHERE id = ?
      AND assigned_to_user_id = ?
      AND status = 'EN_PROCESO'
    LIMIT 1
  ");
  $upd->execute([$now, $taskId, $analystId]);

  if ($upd->rowCount() <= 0) {
    throw new RuntimeException('No se pudo finalizar la tarea.');
  }

  // 3) Log dentro de transacción
  logTaskEvent(
    $pdo,
    $taskId,
    $analystId,
    'FINISHED',
    'Analista finalizó tarea',
    ['status' => ($task['status'] ?? null), 'finished_at' => ($task['finished_at'] ?? null)],
    ['status' => 'FINALIZADA', 'finished_at' => $now]
  );

  // 4) Commit UNA sola vez
  $pdo->commit();

  // 5) Notificar fuera de transacción (si falla, NO revierte el status)
  try {
    $st = $pdo->prepare("
      SELECT t.created_by_admin_id,
             CONCAT(u.name,' ',u.last_name) AS analyst_name
      FROM tasks t
      JOIN users u ON u.id = t.assigned_to_user_id
      WHERE t.id = ?
      LIMIT 1
    ");
    $st->execute([$taskId]);
    $infoNotify = $st->fetch(PDO::FETCH_ASSOC);

    if ($infoNotify) {
      $adminId     = (int)$infoNotify['created_by_admin_id'];
      $analystName = trim($infoNotify['analyst_name'] ?? 'Analista');

      notifyUser(
        $pdo,
        $adminId,
        "Tarea finalizada",
        "{$analystName} finalizó la tarea (#{$taskId})",
        "/HelpDesk_EQF/modules/dashboard/tasks/view.php?id={$taskId}"
      );
    }
  } catch (Throwable $e) {
    // opcional: error_log($e->getMessage());
  }

  $_SESSION['flash_ok'] = 'Tarea finalizada.';
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/analyst.php');
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash_err'] = $e->getMessage();
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/analyst.php');
  exit;
}
