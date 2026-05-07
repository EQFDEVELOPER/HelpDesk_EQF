<?php
session_start();
require_once __DIR__ . '/../../../../config/connectionBD.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'msg' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
  exit;
}

// USER (rol = 4)
$rol = (int)($_SESSION['user_rol'] ?? 0);
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

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$userId = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => 'ID inválido'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  // IMPORTANTE: que el usuario solo vea su requisición
  $stmt = $pdo->prepare("
    SELECT
      r.id,
      r.user_id,
      r.requester_email,
      r.requester_name,
      r.requester_area,
      r.status,
      r.comments,
      r.extra_reason,
      r.created_at,
      r.shipped_at,
      r.shipped_by_admin_id,
      r.delivered_at,
      r.delivered_by_user_id
    FROM equipment_requests r
    WHERE r.id = ? AND r.user_id = ?
    LIMIT 1
  ");
  $stmt->execute([$id, $userId]);
  $req = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$req) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Requisición no encontrada'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $stmt2 = $pdo->prepare("
    SELECT item_type, description, quantity
    FROM equipment_request_items
    WHERE request_id = ?
    ORDER BY id ASC
  ");
  $stmt2->execute([$id]);
  $items = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $stmt3 = $pdo->prepare("
    SELECT id, original_name, uploaded_at
    FROM equipment_request_files
    WHERE request_id = ?
    ORDER BY uploaded_at DESC
  ");
  $stmt3->execute([$id]);
  $files = $stmt3->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $status = strtoupper((string)($req['status'] ?? ''));
  $statusLabel = match ($status) {
    'VERIFICANDO' => 'Verificando',
    'ENVIADO_DHL' => 'Enviado (DHL)',
    'ENVIADO_LEVIC' => 'Enviado (Levic)',
    'ENTREGADO' => 'Entregado',
    default => $status ?: '—'
  };

  // (Opcional) nombres de quien marcó enviado/entregado
  $shippedBy = '';
  if (!empty($req['shipped_by_admin_id'])) {
    $aid = (int)$req['shipped_by_admin_id'];
    $stA = $pdo->prepare("SELECT name, last_name, email FROM users WHERE id = ? LIMIT 1");
    $stA->execute([$aid]);
    $a = $stA->fetch(PDO::FETCH_ASSOC);
    if ($a) {
      $shippedBy = trim(($a['name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
      if ($shippedBy === '') $shippedBy = (string)($a['email'] ?? '');
    }
  }

  $deliveredBy = '';
  if (!empty($req['delivered_by_user_id'])) {
    $uid = (int)$req['delivered_by_user_id'];
    $stU = $pdo->prepare("SELECT name, last_name, email FROM users WHERE id = ? LIMIT 1");
    $stU->execute([$uid]);
    $u = $stU->fetch(PDO::FETCH_ASSOC);
    if ($u) {
      $deliveredBy = trim(($u['name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
      if ($deliveredBy === '') $deliveredBy = (string)($u['email'] ?? '');
    }
  }

  $extraReason = trim((string)($req['extra_reason'] ?? ''));

  ob_start();
  ?>
  <div class="task-card">
    <div class="task-row">
      <div class="task-label">Requisición</div>
      <div class="task-value">#<?php echo (int)$req['id']; ?></div>
    </div>

    <div class="task-row">
      <div class="task-label">Estatus</div>
      <div class="task-value"><?php echo h($statusLabel); ?></div>
    </div>

    <div class="task-row">
      <div class="task-label">Creada</div>
      <div class="task-value"><?php echo h($req['created_at'] ?? ''); ?></div>
    </div>

    <?php if (!empty($req['shipped_at'])): ?>
      <div class="task-row">
        <div class="task-label">Enviada</div>
        <div class="task-value">
          <?php echo h($req['shipped_at']); ?>
          <?php if ($shippedBy !== ''): ?>
            <span class="muted">· por <?php echo h($shippedBy); ?></span>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($req['delivered_at'])): ?>
      <div class="task-row">
        <div class="task-label">Entregada</div>
        <div class="task-value">
          <?php echo h($req['delivered_at']); ?>
          <?php if ($deliveredBy !== ''): ?>
            <span class="muted">· confirmada por <?php echo h($deliveredBy); ?></span>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="panel-divider"></div>

    <div class="panel-mini-title">Solicitante</div>
    <div class="panel-mini-note">
      <b><?php echo h($req['requester_email'] ?? ''); ?></b>
      <?php if (!empty($req['requester_name'])): ?> · <?php echo h($req['requester_name']); ?><?php endif; ?>
      <?php if (!empty($req['requester_area'])): ?> · <?php echo h($req['requester_area']); ?><?php endif; ?>
    </div>

    <?php if (!empty($req['comments'])): ?>
      <div class="task-desc"><?php echo nl2br(h($req['comments'])); ?></div>
    <?php endif; ?>

    <?php if ($extraReason !== ''): ?>
      <div class="panel-divider"></div>
      <div class="panel-mini-title">Razón</div>
      <div class="task-desc"><?php echo nl2br(h($extraReason)); ?></div>
    <?php endif; ?>

    <div class="panel-divider"></div>

    <div class="panel-mini-title">Items</div>
    <ul class="panel-mini-list">
      <?php foreach ($items as $it): ?>
        <li>
          <span><b><?php echo (int)($it['quantity'] ?? 1); ?>×</b></span>
          <span><?php echo h($it['item_type'] ?? ''); ?></span>
          <?php if (!empty($it['description'])): ?>
            <span class="muted">· <?php echo h($it['description']); ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <div class="panel-divider"></div>

    <div class="panel-mini-title">Evidencias</div>

    <?php if (!$files): ?>
      <div class="panel-mini-note">Aún no hay archivos adjuntos.</div>
    <?php else: ?>
      <div class="panel-table-wrap">
        <table class="panel-table">
          <thead>
            <tr>
              <th>Archivo</th>
              <th>Fecha</th>
              <th class="ta-right">Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($files as $f): ?>
              <tr>
                <td><?php echo h($f['original_name'] ?? ''); ?></td>
                <td><?php echo h($f['uploaded_at'] ?? ''); ?></td>
                <td class="ta-right">
                  <a class="panel-link" href="/HelpDesk_EQF/modules/dashboard/user/ajax/equipment_file.php?id=<?php echo (int)$f['id']; ?>">Descargar</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
  <?php
  $html = ob_get_clean();

  echo json_encode(['ok' => true, 'html' => $html], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => 'Error al cargar detalle'], JSON_UNESCAPED_UNICODE);
}
