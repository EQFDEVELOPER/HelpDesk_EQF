<?php
session_start();
require_once __DIR__ . '/../../../config/connectionBD.php';
require_once __DIR__ . '/../../../config/notify.php';

if (!isset($_SESSION['user_id']) || (int)($_SESSION['user_rol'] ?? 0) !== 2) {
    header('Location: /HelpDesk_EQF/auth/login.php');
    exit;
}

$pdo       = Database::getConnection();
$userId    = (int)($_SESSION['user_id'] ?? 0);
$areaAdmin = trim($_SESSION['user_area'] ?? '');

// -----------------------------
// Endpoint AJAX para obtener detalles específicos del ticket
// -----------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_ticket_details') {
    header('Content-Type: application/json');
    $tId = (int)($_GET['ticket_id'] ?? 0);
    
    $stmtView = $pdo->prepare("
        SELECT t.id, t.email, t.descripcion, t.fecha_envio, t.estado, t.asignado_a,
               a.name AS analyst_name, a.last_name AS analyst_last
        FROM tickets t
        LEFT JOIN users a ON a.id = t.asignado_a AND a.rol = 3
        WHERE t.id = :id AND t.area = :area LIMIT 1
    ");
    $stmtView->execute([':id' => $tId, ':area' => $areaAdmin]);
    $ticketDetails = $stmtView->fetch(PDO::FETCH_ASSOC);
    
    if ($ticketDetails) {
        // Procesamos la sucursal desde aquí para enviarla limpia
        $sucursal = '—';
        if (!empty($ticketDetails['email'])) {
            $partes = explode('@', $ticketDetails['email']);
            $sucursal = strtoupper($partes[0]);
        }
        $ticketDetails['sucursal'] = $sucursal;
    }
    
    echo json_encode($ticketDetails ? $ticketDetails : ['error' => 'No encontrado']);
    exit;
}

// -----------------------------
// Flash messages (PRG)
// -----------------------------
$mensajeExito = $_SESSION['flash_ok'] ?? '';
$mensajeError = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

// -----------------------------
// Helpers
// -----------------------------
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function problemaLabel(string $p): string {
    return match ($p) {
        'cierre_dia'  => 'Cierre del día',
        'no_legado'   => 'Sin acceso a legado/legacy',
        'no_internet' => 'Sin internet',
        'no_checador' => 'No funciona checador',
        'rastreo'     => 'Rastreo de checada',
        'otro'        => 'Otro',
        default       => $p,
    };
}
function prioridadLabel(?string $p): string {
    $p = strtolower($p ?? '');
    return match ($p) {
        'alta'  => 'Alta',
        'media' => 'Media',
        'baja'  => 'Baja',
        default => ($p !== '' ? ucfirst($p) : '—'),
    };
}
function estadoLabel(?string $e): string {
    $e = strtolower($e ?? '');
    return match ($e) {
        'abierto'    => 'Abierto',
        'en_proceso' => 'En proceso',
        'resuelto'   => 'Resuelto',
        'cerrado'    => 'Cerrado',
        default      => ($p !== '' ? ucfirst($e) : '—'),
    };
}

function redirectSelf(): void {
    header('Location: /HelpDesk_EQF/modules/dashboard/admin/tickets_area.php');
    exit;
}

// -----------------------------
// POST actions
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion   = $_POST['accion'] ?? '';
    $ticketId = (int)($_POST['ticket_id'] ?? 0);

    if ($ticketId <= 0) {
        $_SESSION['flash_err'] = "Ticket inválido.";
        redirectSelf();
    }

    $stmtCheck = $pdo->prepare("
        SELECT id, area, asignado_a, problema, estado, user_id
        FROM tickets
        WHERE id = :id AND area = :area
        LIMIT 1
    ");
    $stmtCheck->execute([':id' => $ticketId, ':area' => $areaAdmin]);
    $ticketBase = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$ticketBase) {
        $_SESSION['flash_err'] = "No tienes permiso para modificar este ticket.";
        redirectSelf();
    }

    $ticketOwnerId = (int)($ticketBase['user_id'] ?? 0);

    // Asignar / Reasignar (o cambios globales desde Modal Ver)
    if ($accion === 'asignar' || $accion === 'actualizar_desde_ver') {
        $analystId = (int)($_POST['analyst_id'] ?? 0);
        $motivo    = trim($_POST['motivo'] ?? '');
        $nuevoEstado = $_POST['estado'] ?? null;

        try {
            $pdo->beginTransaction();

            // Si se procesa cambio de estado
            if ($nuevoEstado && $nuevoEstado !== $ticketBase['estado']) {
                $permitidos = ['abierto','en_proceso','resuelto','cerrado'];
                if (in_array($nuevoEstado, $permitidos, true)) {
                    if ($nuevoEstado === 'resuelto' || $nuevoEstado === 'cerrado') {
                        $stmtUpEst = $pdo->prepare("UPDATE tickets SET estado = :estado, fecha_resolucion = COALESCE(fecha_resolucion, NOW()) WHERE id = :tid");
                    } else {
                        $stmtUpEst = $pdo->prepare("UPDATE tickets SET estado = :estado WHERE id = :tid");
                    }
                    $stmtUpEst->execute([':estado' => $nuevoEstado, ':tid' => $ticketId]);
                }
            }

            // Si se procesa asignación/reasignación de analista
            if ($analystId > 0 && $analystId !== (int)$ticketBase['asignado_a']) {
                if (in_array(($ticketBase['estado'] ?? ''), ['cerrado'], true) && !$nuevoEstado) {
                    $_SESSION['flash_err'] = "No puedes asignar/reasignar un ticket cerrado.";
                    redirectSelf();
                }

                $stmtA = $pdo->prepare("SELECT id, name, last_name FROM users WHERE id = :id AND rol = 3 AND area = :area LIMIT 1");
                $stmtA->execute([':id' => $analystId, ':area' => $areaAdmin]);
                $analista = $stmtA->fetch(PDO::FETCH_ASSOC);

                if ($analista) {
                    $fromAnalyst = (int)($ticketBase['asignado_a'] ?? 0);

                    $stmtUp = $pdo->prepare("
                        UPDATE tickets
                        SET asignado_a = :aid, fecha_asignacion = COALESCE(fecha_asignacion, NOW())
                        WHERE id = :tid AND area = :area
                    ");
                    $stmtUp->execute([':aid' => $analystId, ':tid' => $ticketId, ':area' => $areaAdmin]);

                    $body = "Se te asignó el ticket #{$ticketId}.";
                    if ($motivo !== '') $body .= " Motivo: " . mb_substr($motivo, 0, 180);

                    notify_user($pdo, $analystId, 'ticket_assigned', 'Ticket asignado', $body, "/HelpDesk_EQF/modules/dashboard/analyst/analyst.php?open_ticket={$ticketId}");

                    if ($fromAnalyst > 0 && $fromAnalyst !== $analystId) {
                        notify_user($pdo, $fromAnalyst, 'ticket_reassigned', 'Ticket reasignado', "El ticket #{$ticketId} fue reasignado a otro analista.", "/HelpDesk_EQF/modules/dashboard/analyst/analyst.php");
                    }
                }
            }

            $pdo->commit();
            $_SESSION['flash_ok'] = "Ticket #{$ticketId} actualizado con éxito.";
            redirectSelf();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash_err'] = "Error al actualizar: " . $e->getMessage();
            redirectSelf();
        }
    }

    // Cambiar estado independiente (Clic en el Estatus de la Tabla)
    if ($accion === 'estado') {
        $estado = $_POST['estado'] ?? '';
        $permitidos = ['abierto','en_proceso','resuelto','cerrado'];

        if (!in_array($estado, $permitidos, true)) {
            $_SESSION['flash_err'] = "Estado inválido.";
            redirectSelf();
        }

        try {
            $pdo->beginTransaction();

            if ($estado === 'resuelto' || $estado === 'cerrado') {
                $stmtUp = $pdo->prepare("UPDATE tickets SET estado = :estado, fecha_resolucion = COALESCE(fecha_resolucion, NOW()) WHERE id = :tid AND area = :area");
            } else {
                $stmtUp = $pdo->prepare("UPDATE tickets SET estado = :estado WHERE id = :tid AND area = :area");
            }

            $stmtUp->execute([':estado' => $estado, ':tid' => $ticketId, ':area' => $areaAdmin]);

            $aid = (int)($ticketBase['asignado_a'] ?? 0);
            if ($aid > 0) {
                notify_user($pdo, $aid, 'ticket_status', 'Estado actualizado', "El ticket #{$ticketId} cambió a: " . estadoLabel($estado), "/HelpDesk_EQF/modules/dashboard/analyst/analyst.php?open_ticket={$ticketId}");
            }

            if (($estado === 'resuelto' || $estado === 'cerrado') && $ticketOwnerId > 0) {
                notify_user($pdo, $ticketOwnerId, 'ticket_status', 'Tu ticket fue actualizado', "Tu ticket #{$ticketId} cambió a: " . estadoLabel($estado), "/HelpDesk_EQF/modules/dashboard/user/user.php?open_ticket={$ticketId}");
            }

            $pdo->commit();
            $_SESSION['flash_ok'] = "Estado del ticket #{$ticketId} actualizado.";
            redirectSelf();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash_err'] = "Error al actualizar estado: " . $e->getMessage();
            redirectSelf();
        }
    }

    // Canalizar PRO
    if ($accion === 'canalizar') {
        $nuevaArea = trim($_POST['nueva_area'] ?? '');
        $motivo    = trim($_POST['motivo'] ?? '');
        $copiarAdj = isset($_POST['copiar_adjuntos']);

        $areasPermitidas = ['TI','SAP','MKT'];

        if (!in_array($nuevaArea, $areasPermitidas, true)) {
            $_SESSION['flash_err'] = "Área destino inválida.";
            redirectSelf();
        }
        if ($nuevaArea === $areaAdmin) {
            $_SESSION['flash_err'] = "El ticket ya pertenece a tu área.";
            redirectSelf();
        }

        try {
            $pdo->beginTransaction();

            $stmtIns = $pdo->prepare("INSERT INTO ticket_transfers (ticket_id, from_area, to_area, admin_id, motivo, created_at) VALUES (:ticket_id, :from_area, :to_area, :admin_id, :motivo, NOW())");
            $stmtIns->execute([
                ':ticket_id' => $ticketId,
                ':from_area' => $areaAdmin,
                ':to_area'   => $nuevaArea,
                ':admin_id'  => $userId,
                ':motivo'    => ($motivo !== '' ? mb_substr($motivo, 0, 255) : null),
            ]);
            $transferId = (int)$pdo->lastInsertId();

            $stmtMsg = $pdo->prepare("
                SELECT tm.sender_role, CONCAT(COALESCE(u.name,''),' ',COALESCE(u.last_name,'')) AS sender_name, tm.mensaje AS message, tm.created_at
                FROM ticket_messages tm
                LEFT JOIN users u ON u.id = tm.sender_id
                WHERE tm.ticket_id = :ticket_id
                ORDER BY tm.created_at ASC
            ");
            $stmtMsg->execute([':ticket_id' => $ticketId]);
            $msgs = $stmtMsg->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($msgs)) {
                $stmtMsgIns = $pdo->prepare("INSERT INTO ticket_transfer_messages (transfer_id, ticket_id, sender_role, sender_name, message, created_at) VALUES (:transfer_id, :ticket_id, :sender_role, :sender_name, :message, :created_at)");
                foreach ($msgs as $m) {
                    $stmtMsgIns->execute([
                        ':transfer_id' => $transferId,
                        ':ticket_id'   => $ticketId,
                        ':sender_role' => $m['sender_role'] ?? 'usuario',
                        ':sender_name' => (trim((string)($m['sender_name'] ?? '')) !== '' ? trim($m['sender_name']) : null),
                        ':message'     => $m['message'] ?? null,
                        ':created_at'  => $m['created_at'] ?? null,
                    ]);
                }
            }

            if ($copiarAdj) {
                $stmtFiles = $pdo->prepare("SELECT nombre_archivo, ruta_archivo, tipo, subido_en FROM ticket_attachments WHERE ticket_id = :ticket_id ORDER BY subido_en ASC");
                $stmtFiles->execute([':ticket_id' => $ticketId]);
                $files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($files)) {
                    $stmtFileIns = $pdo->prepare("INSERT INTO ticket_transfer_files (transfer_id, ticket_id, file_name, file_path, mime_type, created_at) VALUES (:transfer_id, :ticket_id, :file_name, :file_path, :mime_type, :created_at)");
                    foreach ($files as $f) {
                        $stmtFileIns->execute([
                            ':transfer_id' => $transferId,
                            ':ticket_id'   => $ticketId,
                            ':file_name'   => $f['nombre_archivo'] ?? 'archivo',
                            ':file_path'   => $f['ruta_archivo'] ?? '',
                            ':mime_type'   => $f['tipo'] ?? null,
                            ':created_at'  => $f['subido_en'] ?? date('Y-m-d H:i:s'),
                        ]);
                    }
                }
            }

            $stmtUp = $pdo->prepare("UPDATE tickets SET area = :nueva_area, asignado_a = NULL, estado = 'abierto', transferred_from_area = :from_area, transferred_by = :by_admin, transferred_at = NOW() WHERE id = :tid AND area = :area_actual");
            $stmtUp->execute([':nueva_area' => $nuevaArea, ':from_area' => $areaAdmin, ':by_admin' => $userId, ':tid' => $ticketId, ':area_actual' => $areaAdmin]);

            $stmtUsers = $pdo->prepare("SELECT id FROM users WHERE area = :area AND rol IN (2,3)");
            $stmtUsers->execute([':area' => $nuevaArea]);
            $destinatarios = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);

            $body = "Ticket #{$ticketId} canalizado a tu área ({$nuevaArea}).";
            if (!empty($motivo)) $body .= " Motivo: {$motivo}";

            notify_many($pdo, $destinatarios, 'ticket_transfer', 'Ticket canalizado', $body, "/HelpDesk_EQF/modules/dashboard/admin/tickets_area.php?estado=abierto");

            $pdo->commit();
            $_SESSION['flash_ok'] = "Ticket #{$ticketId} canalizado a {$nuevaArea}.";
            redirectSelf();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash_err'] = "Error al canalizar: " . $e->getMessage();
            redirectSelf();
        }
    }

    $_SESSION['flash_err'] = "Acción inválida.";
    redirectSelf();
}

// Analistas del área (para asignar)
$stmtAnalysts = $pdo->prepare("SELECT id, name, last_name FROM users WHERE rol = 3 AND area = :area ORDER BY last_name ASC, name ASC");
$stmtAnalysts->execute([':area' => $areaAdmin]);
$areaAnalysts = $stmtAnalysts->fetchAll(PDO::FETCH_ASSOC);

// Filtros
$estadoFiltro    = $_GET['estado']    ?? 'todos';
$prioridadFiltro = $_GET['prioridad'] ?? 'todas';
$desdeFiltro     = $_GET['desde']     ?? '';
$hastaFiltro     = $_GET['hasta']     ?? '';
$soloSinAnalista = isset($_GET['sin_asignar']);

$sql = "
    SELECT t.id, t.sap, t.nombre, t.email, t.equipo_area, t.problema, t.descripcion, t.fecha_envio, t.estado, t.prioridad, t.asignado_a,
           a.name AS analyst_name, a.last_name AS analyst_last
    FROM tickets t
    LEFT JOIN users a ON a.id = t.asignado_a AND a.rol = 3
    WHERE t.area = :areaX
";
$params = [':areaX' => $areaAdmin];

if ($estadoFiltro !== '' && $estadoFiltro !== 'todos') { $sql .= " AND t.estado = :estadoX"; $params[':estadoX'] = $estadoFiltro; }
if ($prioridadFiltro !== '' && $prioridadFiltro !== 'todas') { $sql .= " AND t.prioridad = :prioridadX"; $params[':prioridadX'] = $prioridadFiltro; }
if ($desdeFiltro !== '') { $sql .= " AND t.fecha_envio >= :desdeX"; $params[':desdeX'] = $desdeFiltro . ' 00:00:00'; }
if ($hastaFiltro !== '') { $sql .= " AND t.fecha_envio <= :hastaX"; $params[':hastaX'] = $hastaFiltro . ' 23:59:59'; }
if ($soloSinAnalista) { $sql .= " AND (t.asignado_a IS NULL OR t.asignado_a = 0)"; }

$sql .= " ORDER BY t.fecha_envio DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../../template/header.php';
include __DIR__ . '/../../../template/sidebar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Tickets | Mesa de Ayuda EQF</title>
  <link rel="stylesheet" href="/HelpDesk_EQF/assets/css/style.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
</head>
<body class="user-body">

<main class="user-main">
  <section class="user-main-inner">
    <header class="user-main-header">
      <div>
        <p class="login-brand">
          <span>HelpDesk </span><span class="eqf-e">E</span><span class="eqf-q">Q</span><span class="eqf-f">F</span>
        </p>
        <p class="user-main-subtitle">Tickets de mi área – <?php echo h($areaAdmin); ?></p>
      </div>
    </header>

    <section class="user-main-content">
      <?php if ($mensajeExito): ?> <div class="alert alert-success"><?php echo h($mensajeExito); ?></div> <?php endif; ?>
      <?php if ($mensajeError): ?> <div class="alert alert-danger"><?php echo h($mensajeError); ?></div> <?php endif; ?>

      <form method="get" class="user-filters-row" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
        <div class="form-group">
          <label for="desde">Desde</label>
          <input type="date" name="desde" id="desde" value="<?php echo h($desdeFiltro); ?>">
        </div>
        <div class="form-group">
          <label for="hasta">Hasta</label>
          <input type="date" name="hasta" id="hasta" value="<?php echo h($hastaFiltro); ?>">
        </div>
        <div class="form-group">
          <label for="estado">Estado</label>
          <select name="estado" id="estado">
            <?php $estados = ['todos' => 'Todos', 'abierto' => 'Abierto', 'en_proceso' => 'En proceso', 'resuelto' => 'Resuelto', 'cerrado' => 'Cerrado'];
            foreach ($estados as $value => $label): ?>
              <option value="<?php echo h($value); ?>" <?php if ($estadoFiltro === $value) echo 'selected'; ?>><?php echo h($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="prioridad">Prioridad</label>
          <select name="prioridad" id="prioridad">
            <?php $prioridades = ['todas' => 'Todas', 'baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta'];
            foreach ($prioridades as $value => $label): ?>
              <option value="<?php echo h($value); ?>" <?php if ($prioridadFiltro === $value) echo 'selected'; ?>><?php echo h($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group form-group-inline" style="margin-bottom: 8px;">
          <label><input type="checkbox" name="sin_asignar" value="1" <?php if ($soloSinAnalista) echo 'checked'; ?>> Sin analista</label>
        </div>
        <div style="display: flex; gap: 10px;">
          <button type="submit" class="btn-primary">Aplicar</button>
          <button type="button" class="btn-green" onclick="window.open('/HelpDesk_EQF/modules/reports/generate_viso_report.php?' + $('.user-filters-row').serialize(), '_blank')">Generar reporte</button>
        </div>
      </form>

      <div class="user-tickets-table-wrapper" style="margin-top: 20px;">
        <table id="adminTicketsAreaTable" class="data-table display">
          <thead>
            <tr>
              <th>Ticket #</th>
              <th>Sucursal</th>
              <th>Problema</th>
              <th>Prioridad</th>
              <th>Estatus</th>
              <th>Analista</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($tickets as $t): ?>
            <?php 
                $hasAnalyst = ((int)($t['asignado_a'] ?? 0) > 0); 
                $sucursal = '—';
                if (!empty($t['email'])) {
                    $partes = explode('@', $t['email']);
                    $sucursal = strtoupper($partes[0]);
                }
            ?>
            <tr>
              <td><strong>#<?php echo (int)$t['id']; ?></strong></td>
              <td><strong><?php echo h($sucursal); ?></strong></td>
              <td><?php echo h(problemaLabel((string)$t['problema'])); ?></td>
              <td><?php echo h(prioridadLabel($t['prioridad'] ?? null)); ?></td>
              <td>
                <span style="cursor:pointer; text-decoration:underline;" onclick="openStateModal(<?php echo (int)$t['id']; ?>, '<?php echo h($t['estado'] ?? 'abierto'); ?>')">
                    <?php echo h(estadoLabel($t['estado'] ?? null)); ?> ⚙️
                </span>
              </td>
              <td>
                <?php echo !empty($t['analyst_name']) ? h($t['analyst_name'] . ' ' . $t['analyst_last']) : '<em style="color:red;">Sin asignar</em>'; ?>
              </td>
              <td class="actions-inline">
                <!-- BOTÓN VER ADYACENTE -->
                <button type="button" class="task-link-blue" onclick="openViewTicketModal(<?php echo (int)$t['id']; ?>)">
                  Ver
                </button>
                <span class="actions-sep">|</span>
                <button type="button" class="task-link-combined" onclick="openAssignModal(<?php echo (int)$t['id']; ?>, <?php echo (int)($t['asignado_a'] ?? 0); ?>)">
                  <?php echo $hasAnalyst ? 'Reasignar' : 'Asignar'; ?>
                </button>
                <span class="actions-sep">|</span>
                <button type="button" class="task-cancel-link" onclick="openCanalizarModal(<?php echo (int)$t['id']; ?>)">
                  Canalizar
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </section>
</main>

<!-- MODAL: VER DETALLES (Muestra Sucursal, Descripcion, Fecha Envio, Analista + Controles de Cambio) -->
<div class="user-modal-backdrop" id="viewTicketModal" style="display:none;">
  <div class="user-modal" style="max-width: 550px;">
    <header class="user-modal-header">
      <h2 id="viewModalTitle">Detalles del Ticket</h2>
      <button type="button" class="user-modal-close" onclick="closeViewTicketModal()">✕</button>
    </header>

    <form method="POST" action="">
      <input type="hidden" name="accion" value="actualizar_desde_ver">
      <input type="hidden" name="ticket_id" id="view_ticket_id" value="">

      <!-- Bloque informativo filtrado -->
      <div id="view_ticket_info" style="margin-bottom: 15px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #ddd; padding-bottom: 12px;">
         <!-- Inyectado vía JS -->
      </div>

      <!-- Selección de Estado -->
      <div class="form-group">
        <label for="view_estado">Estatus del Ticket</label>
        <select name="estado" id="view_estado" required>
          <option value="abierto">Abierto</option>
          <option value="en_proceso">En proceso</option>
          <option value="resuelto">Resuelto</option>
          <option value="cerrado">Cerrado</option>
        </select>
      </div>

      <!-- Selección de Analista -->
      <div class="form-group">
        <label for="view_analyst_id">Analista Asignado</label>
        <select name="analyst_id" id="view_analyst_id">
          <option value="">Dejar sin asignar / Sin cambios</option>
          <?php foreach ($areaAnalysts as $a): ?>
            <option value="<?php echo (int)$a['id']; ?>">
              <?php echo h(($a['last_name'] ?? '') . ' ' . ($a['name'] ?? '')); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="view_motivo">Motivo del cambio (Opcional)</label>
        <input type="text" name="motivo" id="view_motivo" maxlength="255" placeholder="Ej: reasignación por guardia...">
      </div>

      <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:12px;">
        <button type="button" class="btn-secondary" onclick="closeViewTicketModal()">Cerrar</button>
        <button type="submit" class="btn-primary">Guardar Cambios</button>
      </div>
    </form>
  </div>
</div>

<!-- Modales Auxiliares -->
<div class="user-modal-backdrop" id="assignModal" style="display:none;">
  <div class="user-modal">
    <header class="user-modal-header">
      <h2 id="assignModalTitle">Asignar ticket</h2>
      <button type="button" class="user-modal-close" onclick="closeAssignModal()">✕</button>
    </header>
    <form method="POST" action="">
      <input type="hidden" name="accion" value="asignar">
      <input type="hidden" name="ticket_id" id="assign_ticket_id" value="">
      <div class="form-group">
        <label for="assign_analyst_id">Analista</label>
        <select name="analyst_id" id="assign_analyst_id" required>
          <option value="">Selecciona un analista</option>
          <?php foreach ($areaAnalysts as $a): ?>
            <option value="<?php echo (int)$a['id']; ?>"><?php echo h(($a['last_name'] ?? '') . ' ' . ($a['name'] ?? '')); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="assign_motivo">Motivo (opcional)</label>
        <input type="text" name="motivo" id="assign_motivo" maxlength="255" placeholder="Ej: carga alta...">
      </div>
      <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:12px;">
        <button type="button" class="btn-secondary" onclick="closeAssignModal()">Cancelar</button>
        <button type="submit" class="btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<div class="user-modal-backdrop" id="stateModal" style="display:none;">
  <div class="user-modal">
    <header class="user-modal-header">
      <h2>Cambiar estado</h2>
      <button type="button" class="user-modal-close" onclick="closeStateModal()">✕</button>
    </header>
    <form method="POST">
      <input type="hidden" name="accion" value="estado">
      <input type="hidden" name="ticket_id" id="state_ticket_id" value="">
      <div class="form-group">
        <label for="state_value">Estado</label>
        <select name="estado" id="state_value" required>
          <option value="abierto">Abierto</option>
          <option value="en_proceso">En proceso</option>
          <option value="resuelto">Resuelto</option>
          <option value="cerrado">Cerrado</option>
        </select>
      </div>
      <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:12px;">
        <button type="button" class="btn-secondary" onclick="closeStateModal()">Cancelar</button>
        <button type="submit" class="btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<div class="user-modal-backdrop" id="canalizarModal" style="display:none;">
  <div class="user-modal">
    <header class="user-modal-header">
      <h2>Canalizar ticket</h2>
      <button type="button" class="user-modal-close" onclick="closeCanalizarModal()">✕</button>
    </header>
    <form method="POST">
      <input type="hidden" name="accion" value="canalizar">
      <input type="hidden" name="ticket_id" id="canalizar_ticket_id" value="">
      <div class="form-group">
        <label for="nueva_area">Enviar a área</label>
        <select name="nueva_area" id="nueva_area" required>
          <option value="">Selecciona un área</option>
          <option value="TI">TI</option>
          <option value="SAP">SAP</option>
          <option value="MKT">MKT</option>
        </select>
      </div>
      <div class="form-group">
        <label for="motivo">Motivo (opcional)</label>
        <textarea name="motivo" id="motivo" rows="3" maxlength="255"></textarea>
      </div>
      <div class="form-group form-group-inline">
        <label><input type="checkbox" name="copiar_adjuntos" value="1" checked> Copiar adjuntos al traspaso</label>
      </div>
      <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:12px;">
        <button type="button" class="btn-secondary" onclick="closeCanalizarModal()">Cancelar</button>
        <button type="submit" class="btn-primary">Canalizar</button>
      </div>
    </form>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $('#adminTicketsAreaTable').DataTable({
    pageLength: 10,
    order: [[0, 'desc']]
  });
});

function openViewTicketModal(ticketId) {
  document.getElementById('view_ticket_id').value = ticketId;
  document.getElementById('viewModalTitle').textContent = 'Cargando Ticket #' + ticketId + '...';
  document.getElementById('view_ticket_info').innerHTML = 'Por favor, espere...';
  document.getElementById('viewTicketModal').style.display = 'flex';

  $.getJSON(window.location.pathname, { action: 'get_ticket_details', ticket_id: ticketId }, function(data) {
      if(data.error) {
          document.getElementById('view_ticket_info').innerHTML = '<span style="color:red;">Error al procesar los datos del ticket.</span>';
          return;
      }
      document.getElementById('viewModalTitle').textContent = 'Ticket #' + data.id;
      
      // Renderizado exclusivo de los 4 datos requeridos
      let infoHtml = `
        <p><strong>Sucursal:</strong> <span style="text-transform: uppercase; font-weight: bold;">${data.sucursal}</span></p>
        <p><strong>Descripción:</strong> ${data.descripcion || '<em>Sin descripción disponible</em>'}</p>
        <p><strong>Fecha de envío:</strong> ${data.fecha_envio}</p>
        <p><strong>Analista actual:</strong> ${data.analyst_name ? (data.analyst_name + ' ' + data.analyst_last) : '<em style="color:red;">Sin asignar</em>'}</p>
      `;
      document.getElementById('view_ticket_info').innerHTML = infoHtml;
      
      // Mapeo automático de valores actuales a los controles editables
      document.getElementById('view_estado').value = data.estado;
      document.getElementById('view_analyst_id').value = data.asignado_a || "";
      document.getElementById('view_motivo').value = "";
  });
}
function closeViewTicketModal() { document.getElementById('viewTicketModal').style.display = 'none'; }

function openAssignModal(ticketId, analystId){
  document.getElementById('assign_ticket_id').value = ticketId;
  document.getElementById('assign_analyst_id').value = (analystId && analystId > 0) ? String(analystId) : "";
  document.getElementById('assign_motivo').value = "";
  const title = document.getElementById('assignModalTitle');
  title.textContent = (analystId && analystId > 0) ? ('Reasignar ticket #' + ticketId) : ('Asignar ticket #' + ticketId);
  document.getElementById('assignModal').style.display = 'flex';
}
function closeAssignModal(){ document.getElementById('assignModal').style.display = 'none'; }

function openStateModal(ticketId, estado){
  document.getElementById('state_ticket_id').value = ticketId;
  document.getElementById('state_value').value = estado || 'abierto';
  document.getElementById('stateModal').style.display = 'flex';
}
function closeStateModal(){ document.getElementById('stateModal').style.display = 'none'; }

function openCanalizarModal(ticketId){
  document.getElementById('canalizar_ticket_id').value = ticketId;
  document.getElementById('nueva_area').value = "";
  document.getElementById('motivo').value = "";
  document.getElementById('canalizarModal').style.display = 'flex';
}
function closeCanalizarModal(){ document.getElementById('canalizarModal').style.display = 'none'; }

document.addEventListener('click', function(e){
  if (e.target === document.getElementById('assignModal')) closeAssignModal();
  if (e.target === document.getElementById('stateModal')) closeStateModal();
  if (e.target === document.getElementById('canalizarModal')) closeCanalizarModal();
  if (e.target === document.getElementById('viewTicketModal')) closeViewTicketModal();
});
</script>

<?php include __DIR__ . '/../../../template/footer.php'; ?>
</body>
</html>