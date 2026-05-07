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
  // Status previo (para log)
  $stmtPrev = $pdo->prepare("
    SELECT status
    FROM tasks
    WHERE id = ? AND assigned_to_user_id = ?
    LIMIT 1
  ");
  $stmtPrev->execute([$taskId, $analystId]);
  $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);

  // Cambiar estatus: ASIGNADA -> EN_PROCESO
  $stmt = $pdo->prepare("
    UPDATE tasks
    SET status = 'EN_PROCESO',
        acknowledged_at = COALESCE(acknowledged_at, NOW())
    WHERE id = ?
      AND assigned_to_user_id = ?
      AND status = 'ASIGNADA'
    LIMIT 1
  ");
  $stmt->execute([$taskId, $analystId]);

  if ($stmt->rowCount() <= 0) {
    throw new RuntimeException('No se pudo marcar como EN PROCESO (quizá ya fue tomada o no es tuya).');
  }

  // Log dentro de transacción
  logTaskEvent(
    $pdo,
    $taskId,
    $analystId,
    'ACKNOWLEDGED',
    'Analista marcó como EN PROCESO',
    ['status' => ($prev['status'] ?? null)],
    ['status' => 'EN_PROCESO']
  );

  // Commit una sola vez
  $pdo->commit();

  // Notificación FUERA de transacción (si falla, no revierte status)
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
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if ($row) {
      $adminId     = (int)$row['created_by_admin_id'];
      $analystName = trim($row['analyst_name'] ?? 'Analista');

      notifyUser(
        $pdo,
        $adminId,
        "Tarea en proceso",
        "{$analystName} está atendiendo la tarea (#{$taskId})",
        "/HelpDesk_EQF/modules/dashboard/tasks/view.php?id={$taskId}"
      );
    }
  } catch (Throwable $e) {
    // opcional: error_log($e->getMessage());
  }

  $_SESSION['flash_ok'] = 'Tarea marcada como EN PROCESO';
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/analyst.php');
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash_err'] = $e->getMessage();
  header('Location: /HelpDesk_EQF/modules/dashboard/tasks/analyst.php');
  exit;
}
