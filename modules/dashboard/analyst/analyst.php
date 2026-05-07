<?php
session_start();

require_once __DIR__ . '/../../../config/connectionBD.php';
include __DIR__ . '/../../../template/header.php';
include __DIR__ . '/../../../template/sidebar.php';

/**
 * ============================
 *  AUTH / CONTEXTO
 * ============================
 */
if (!isset($_SESSION['user_id']) || (int)($_SESSION['user_rol'] ?? 0) !== 3) {
    header('Location: /HelpDesk_EQF/auth/login.php');
    exit;
}

$pdo      = Database::getConnection();
$userId   = (int)($_SESSION['user_id'] ?? 0);
$userName = trim(($_SESSION['user_name'] ?? '') . ' ' . ($_SESSION['user_last'] ?? ''));
$userArea = (string)($_SESSION['user_area'] ?? '');

/**
 * ============================
 *  HELPERS
 * ============================
 */
if (!function_exists('h')) {
    function h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('annClass')) {
    function annClass(string $level): string {
        $level = strtoupper(trim($level));
        return match ($level) {
            'CRITICAL' => 'announcement--critical',
            'WARN'     => 'announcement--warn',
            default    => 'announcement--info',
        };
    }
}

if (!function_exists('annLabel')) {
    function annLabel(string $level): string {
        $level = strtoupper(trim($level));
        return match ($level) {
            'CRITICAL' => 'Crítico',
            'WARN'     => 'Aviso',
            default    => 'Info',
        };
    }
}

function prioridadLabel(string $p): string {
    return match (strtolower($p)) {
        'alta'                => 'Alta',
        'media'               => 'Media',
        'baja'                => 'Baja',
        'critica', 'crítica'  => 'Crítica',
        default               => ucfirst($p),
    };
}

/**
 * ============================
 *  CATÁLOGO ESTATUS (SA)
 * ============================
 */
$statusCatalog = [];
try {
    $stmtStatus = $pdo->prepare("
    SELECT code, label
    FROM catalog_status
    WHERE active = 1
    ORDER BY sort_order ASC, id ASC
");
$stmtStatus->execute();
$statusCatalog = $stmtStatus->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (Throwable $e) {
    $statusCatalog = [];
}



/**
 * ============================
 *  ALERTAS UI
 * ============================
 */
$alerts = [];
if (isset($_GET['updated'])) {
    $alerts[] = [
        'type' => 'info',
        'icon' => 'capsulin_update.png',
        'text' => 'TICKET ACTUALIZADO EXITOSAMENTE',
    ];
}

/**
 * ============================
 *  ANUNCIOS (cards + lista)
 * ============================
 */
$annCards = [];
$annAdminList = [];

try {
    // Cards completas
    $stmt = $pdo->query("
        SELECT id, title, body, level, target_area, starts_at, ends_at, created_at, created_by_area
        FROM announcements
        WHERE is_active = 1
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $annCards = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Lista (por si luego la ocupas)
    $stmt2 = $pdo->query("
        SELECT id, title, level, target_area, created_at, created_by_area
        FROM announcements
        WHERE is_active = 1
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $annAdminList = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $annCards = [];
    $annAdminList = [];
}

/**
 * ============================
 *  KPIs del área
 * ============================
 */
$stmtKpi = $pdo->prepare("
    SELECT
  SUM(estado = 'abierto')    AS abiertos,
  SUM(estado = 'en_proceso') AS en_proceso,
  SUM(estado = 'soporte')    AS en_espera_externo,
  SUM(estado = 'cerrado')    AS cerrados,
  COUNT(*)                   AS total
FROM tickets
WHERE area = :area

");
$stmtKpi->execute([':area' => $userArea]);

$kpi = $stmtKpi->fetch() ?: [
    'abiertos'   => 0,
    'en_proceso' => 0,
    'cerrados'   => 0,
    'total'      => 0,
];


/**
 * ============================
 *  TICKETS ENTRANTES (abiertos, sin asignar)
 * ============================
 *  Nota: aquí tu tabla t.problema a veces es id, a veces code (por tus joins).
 *  Para NO romper, intentamos resolver label por ambos.
 */
$stmtIncoming = $pdo->prepare("
    SELECT
        t.id, t.sap, t.nombre, t.email,
        t.problema AS problema_raw,
        COALESCE(cp1.label, cp2.label, t.problema) AS problema_label,
        t.descripcion, t.fecha_envio, t.estado, t.prioridad,

        t.transferred_from_area AS from_area,
        t.transferred_by        AS from_by,
        CONCAT(COALESCE(u_from.name,''),' ',COALESCE(u_from.last_name,'')) AS from_name

    FROM tickets t
    LEFT JOIN catalog_problems cp1 ON cp1.id = t.problema
    LEFT JOIN catalog_problems cp2 ON cp2.code = t.problema
    LEFT JOIN users u_from ON u_from.id = t.transferred_by

    WHERE t.area = :area
      AND t.estado = 'abierto'
      AND (t.asignado_a IS NULL OR t.asignado_a = 0)
    ORDER BY t.fecha_envio ASC
");

$stmtIncoming->execute([':area' => $userArea]);
$incomingTickets = $stmtIncoming->fetchAll(PDO::FETCH_ASSOC) ?: [];

$maxIncomingId = 0;
foreach ($incomingTickets as $t) {
    $tid = (int)($t['id'] ?? 0);
    if ($tid > $maxIncomingId) $maxIncomingId = $tid;
}

/**
 * ============================
 *  MIS TICKETS (asignados a mí)
 * ============================
 */
$stmtMy = $pdo->prepare("
  SELECT
    t.id, t.sap, t.nombre, t.email,
    t.problema AS problema_raw,
    COALESCE(cp1.label, cp2.label, t.problema) AS problema_label,
    t.descripcion, t.fecha_envio, t.estado, t.prioridad,

    req.id  AS requester_id,
    req.rol AS requester_rol,
    CONCAT(COALESCE(req.name,''),' ',COALESCE(req.last_name,'')) AS requester_name,
t.transferred_from_area AS from_area,
t.transferred_by        AS from_by,
CONCAT(COALESCE(u_from.name,''),' ',COALESCE(u_from.last_name,'')) AS from_name

  FROM tickets t
  LEFT JOIN catalog_problems cp1 ON cp1.id = t.problema
  LEFT JOIN catalog_problems cp2 ON cp2.code = t.problema
  LEFT JOIN users req ON req.id = t.user_id
LEFT JOIN users u_from ON u_from.id = t.transferred_by


  WHERE t.area = :area
    AND t.asignado_a = :uid
    AND t.estado IN ('abierto','en_proceso','soporte')
  ORDER BY t.fecha_envio DESC
");
$stmtMy->execute([':area' => $userArea, ':uid' => $userId]);
$myTickets = $stmtMy->fetchAll(PDO::FETCH_ASSOC) ?: [];

/**
 * ============================
 *  MIS SOLICITUDES A TI 
 * ============================
 */
$myToTI = [];

if (strcasecmp(trim($userArea), 'TI') !== 0) {
  $stmtMyToTI = $pdo->prepare("
    SELECT
      t.id,
      t.fecha_envio,
      t.estado,
      t.asignado_a,
      t.email,
      t.nombre,
      t.prioridad,
      t.problema AS problema_raw,
      COALESCE(cp1.label, cp2.label, t.problema) AS problema_label,

      CONCAT(COALESCE(u.name,''),' ',COALESCE(u.last_name,'')) AS atendido_por
    FROM tickets t
    LEFT JOIN users u ON u.id = t.asignado_a
    LEFT JOIN catalog_problems cp1 ON cp1.id = t.problema
    LEFT JOIN catalog_problems cp2 ON cp2.code = t.problema
    WHERE t.user_id = :uid
      AND t.area = 'TI'
      AND t.estado IN ('abierto','en_proceso','soporte','cerrado')
    ORDER BY t.fecha_envio DESC
    LIMIT 10
  ");
  $stmtMyToTI->execute([':uid' => $userId]);
  $myToTI = $stmtMyToTI->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel de Analista | HELP DESK EQF</title>

    <link rel="stylesheet" href="/HelpDesk_EQF/assets/css/style.css?v=<?php echo time(); ?>">

    <style>
      .analyst-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;}
      .btn-mini{padding:6px 10px;border-radius:12px;font-weight:800;border:1px solid var(--eqf-border,#e5e7eb);background:#fff;cursor:pointer;}
      .btn-mini.primary{background: var(--eqf-combined,#6e1c5c);color:#fff;border-color: transparent;}
      .ticket-detail-grid{display:grid;grid-template-columns: 1fr;gap:10px;font-size:14px;}
      .ticket-detail-meta{display:flex;gap:10px;flex-wrap:wrap;opacity:.9;font-size:13px;}
      .ticket-attachments{display:flex;flex-direction:column;gap:8px;}
      .ticket-attachments a{display:inline-block;padding:8px 10px;border:1px solid var(--eqf-border,#e5e7eb);border-radius:12px;text-decoration:none;}
      .ticket-card--analyst{
  outline: 2px solid rgba(110,28,92,.25);
  box-shadow: 0 0 0 6px rgba(110,28,92,.06);
      }
      /*
========UN ESTILO DIFERENTE PARA SEPARAR DEL TICKET NORMAL AL DE LA AYUDA A TI=========
      .ticket-card--support{
  outline: 2px dashed rgba(110,28,92,.25);
  box-shadow: 0 0 0 6px rgba(110,28,92,.04);
}*/

    </style>
</head>

<body class="user-body">

<?php if (!empty($alerts)): ?>
    <?php $alert = $alerts[0]; ?>
    <div id="eqf-alert-container">
        <div class="eqf-alert eqf-alert-<?php echo h($alert['type']); ?>">
            <img class="eqf-alert-icon" src="/HelpDesk_EQF/assets/img/icons/<?php echo h($alert['icon']); ?>" alt="alert icon">
            <div class="eqf-alert-text"><?php echo h($alert['text']); ?></div>
        </div>
    </div>
<?php endif; ?>

<main class="user-main">
    <section class="user-main-inner">

<header class="analyst-topbar" id="analyst-dashboard">
  <div class="analyst-topbar-left">
    <p class="login-brand">
      <span>HelpDesk </span><span class="eqf-e">E</span><span class="eqf-q">Q</span><span class="eqf-f">F</span>
    </p>
    <p class="user-main-subtitle">
      Panel de Analista – <?php echo h($userArea); ?>
    </p>
  </div>
</header>

        <!-- ANUNCIOS -->
<div class="user-info-card" style="margin-top:18px;" id="annWrap">
          <h2>Anuncios</h2>

          <div class="user-announcements">
            <div class="user-announcements__head">
              <h3 class="user-announcements__title">
                Activos
                <span class="user-announcements__badge" id="annBadge"><?php echo (int)count($annCards); ?></span>
              </h3>
            </div>

            <div class="user-announcements__list" id="annList">
              <?php if (empty($annCards)): ?>
                <p style="margin:0; color:#6b7280;">No hay anuncios activos.</p>
              <?php else: ?>
<?php foreach ($annCards as $a): ?>
  <?php $lvl = strtoupper(trim((string)($a['level'] ?? 'INFO'))); ?>
  <div class="announcement <?php echo annClass($lvl); ?>">
    <div class="announcement__top">
      <div>
        <p class="announcement__h"><?php echo h($a['title'] ?? ''); ?></p>
        <p class="announcement__meta">
          <?php echo h('Dirigido a: ' . ($a['target_area'] ?? '')); ?>
          <?php if (!empty($a['starts_at'])): ?>
            <br><?php echo h('Hora de inicio: ' . $a['starts_at']); ?>
          <?php endif; ?>
          <?php if (!empty($a['ends_at'])): ?>
            <br><?php echo h('Hora estimada fin: ' . $a['ends_at']); ?>
          <?php endif; ?>
        </p>
      </div>

      <div style="display:flex; gap:10px; align-items:center;">
        <span class="announcement__pill"><?php echo annLabel($lvl); ?></span>

        <?php
          // Regla FINAL: Admin y Analista = MISMA regla → solo su misma área
          $createdArea = trim((string)($a['created_by_area'] ?? ''));
          $canDisable  = ($createdArea !== '' && strcasecmp($createdArea, trim((string)$userArea)) === 0);
        ?>

        <?php if ($canDisable): ?>
          <button type="button"
                  class="task-cancel-link"
                  data-ann-disable
                  data-id="<?php echo (int)($a['id'] ?? 0); ?>">
            Desactivar
          </button>
        <?php endif; ?>

      </div>
    </div>

    <div class="announcement__body">
      <?php echo nl2br(h($a['body'] ?? '')); ?>
    </div>
  </div>
<?php endforeach; ?>

              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- MODAL NUEVO AVISO -->
        <?php if (in_array((int)($_SESSION['user_rol'] ?? 0), [2,3], true)): ?>
        <div class="eqf-modal-backdrop" id="announceModal">
          <div class="eqf-modal eqf-announce-modal" data-ann-modal>
            <div class="eqf-modal-header">
              <div>
                <strong>Nuevo aviso</strong>
                <div class="panel-muted">Se mostrará en “Resumen” del usuario.</div>
              </div>
              <button class="eqf-modal-close" type="button" data-close-announcement>✕</button>
            </div>

            <div class="eqf-modal-body eqf-announce-body">
              <div class="eqf-field">
                <label>Título</label>
                <input type="text" id="ann_title" maxlength="120" placeholder="Ej. Mantenimiento programado">
              </div>

              <div class="eqf-field eqf-announce-mt">
                <label>Descripción</label>
                <textarea id="ann_body" rows="4" maxlength="600" placeholder="Escribe el mensaje..."></textarea>
              </div>

              <div class="eqf-grid-2 eqf-announce-mt">
                <div class="eqf-field">
                  <label>Categoría</label>
                  <select id="ann_level">
                    <option value="INFO">INFORMATIVO</option>
                    <option value="WARN">ADVERTENCIA</option>
                    <option value="CRITICAL">CRITICO</option>
                  </select>
                </div>

                <div class="eqf-field">
                  <label>Área</label>
                  <select id="ann_area">
                    <option value="ALL">Todos</option>
                    <option value="Sucursal">Sucursal</option>
                    <option value="Corporativo">Corporativo</option>
                  </select>
                </div>
              </div>

              <div class="eqf-grid-2 eqf-announce-mt">
                <div class="eqf-field">
                  <label>Inicio (opcional)</label>
                  <input type="datetime-local" id="ann_starts">
                </div>
                <div class="eqf-field">
                  <label>Fin (opcional)</label>
                  <input type="datetime-local" id="ann_ends">
                </div>
              </div>
            </div>

            <div class="eqf-modal-footer">
              <button class="eqf-btn eqf-btn-secondary" type="button" data-cancel-announcement>Cancelar</button>
              <button class="eqf-btn eqf-btn-primary" type="button" id="btnSendAnnouncement">Enviar</button>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <section class="user-main-content">

            <!-- MODAL ENCUESTA -->
            <div class="modal-backdrop" id="feedback-modal" style="display:none;">
              <div class="modal-card" style="max-width:900px; width:92vw; height:82vh;">
                <div class="modal-header">
                  <h3 id="feedbackTitle">Encuesta</h3>
                  <button type="button" class="modal-close" onclick="closeFeedbackModal()">✕</button>
                </div>
                <div class="modal-body" style="padding:0; height:calc(82vh - 56px);">
                  <iframe id="feedbackFrame"
                          src=""
                          style="width:100%; height:100%; border:0; border-bottom-left-radius:16px; border-bottom-right-radius:16px;"></iframe>
                </div>
              </div>
            </div>

            <!-- ENTRANTES (CARDS) -->
<div id="incoming-section" class="user-info-card">
  <div class="tickets-head">
    <h3>Tickets entrantes</h3>

    <div class="tickets-controls">
      <label class="tickets-search">
        Search:
        <input id="incomingSearch" type="search" placeholder="">
      </label>
    </div>
  </div>

  <div id="incomingGrid" class="tickets-grid" data-empty="No hay tickets entrantes.">
    <?php if (empty($incomingTickets)): ?>
      <div class="tickets-empty">No hay tickets entrantes.</div>
    <?php else: ?>
      <?php foreach ($incomingTickets as $t): ?>
        <?php
          $id    = (int)$t['id'];
          $fecha = (string)($t['fecha_envio'] ?? '');
          $user  = (string)($t['nombre'] ?? '');
          $prob  = (string)($t['problema_label'] ?? '');
          $desc  = (string)($t['descripcion'] ?? '');
          $prio  = strtolower((string)($t['prioridad'] ?? 'media'));
          $fromArea = trim((string)($t['from_area'] ?? ''));
          $fromName = trim((string)($t['from_name'] ?? ''));
        ?>
        <article class="ticket-card js-ticket"
                 data-ticket-id="<?php echo $id; ?>"
                 data-search="<?php echo h(strtolower($id.' '.$fecha.' '.$user.' '.$prob.' '.$desc.' '.$prio)); ?>">
          <header class="ticket-card__top">
            <div class="ticket-id">#<?php echo $id; ?></div>
            <div class="ticket-date"><?php echo h($fecha); ?></div>
          </header>
<?php if ($fromArea !== '' || $fromName !== ''): ?>
  <div style="margin:6px 0 2px; font-size:12px; font-weight:900; color:#6e1c5c;">
    Enviado por: <?php echo h($fromArea !== '' ? $fromArea : 'Área'); ?>
    <?php if ($fromName !== ''): ?> · <?php echo h($fromName); ?><?php endif; ?>
  </div>
<?php endif; ?>

          <div class="ticket-card__body">
            <div class="ticket-row">
              <span class="ticket-label">Usuario</span>
              <span class="ticket-value"><?php echo h($user); ?></span>
            </div>

            <div class="ticket-row">
              <span class="ticket-label">Problema</span>
              <span class="ticket-value"><?php echo h($prob); ?></span>
            </div>

            <div class="ticket-row">
              <span class="ticket-label">Prioridad</span>
              <span class="priority-pill priority-<?php echo h($prio); ?>">
                <?php echo h(prioridadLabel((string)($t['prioridad'] ?? 'media'))); ?>
              </span>
            </div>

            <div class="ticket-desc"><?php echo h($desc); ?></div>
          </div>

          <footer class="ticket-card__actions">
            <button type="button" class="btn-mini" onclick="openTicketDetail(<?php echo $id; ?>)">Previsualizar</button>
            <button type="button" class="btn-mini primary btn-assign-ticket" data-ticket-id="<?php echo $id; ?>">Asignar</button>
          </footer>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>


            <!-- MIS TICKETS (CARDS) -->
<div id="mytickets-section" class="user-info-card">
  <div class="tickets-head">
    <h3>Mis tickets</h3>

    <div class="tickets-controls">
      <label class="tickets-search">
        Search:
        <input id="mySearch" type="search" placeholder="">
      </label>
    </div>
  </div>

  <div id="myGrid" class="tickets-grid" data-empty="No tienes tickets asignados.">
    <?php if (empty($myTickets)): ?>
      <div class="tickets-empty">No tienes tickets asignados.</div>
    <?php else: ?>
      <?php foreach ($myTickets as $t): ?>
        <?php
          $id    = (int)$t['id'];
          $fecha = (string)($t['fecha_envio'] ?? '');
          $user  = (string)($t['nombre'] ?? '');
          $prob  = (string)($t['problema_label'] ?? '');
          $prio  = strtolower((string)($t['prioridad'] ?? 'media'));
          $estado = (string)($t['estado'] ?? 'abierto');
          $email = (string)($t['email'] ?? '');
          $requesterRol = (int)($t['requester_rol'] ?? 0);
  $requesterId  = (int)($t['requester_id'] ?? 0);
  $isFromAnalyst = ($requesterRol === 3 && $requesterId > 0 && $requesterId !== $userId);
  $requesterName = (string)($t['requester_name'] ?? 'Analista');
        ?>
<article
  class="ticket-card js-ticket <?php echo $isFromAnalyst ? 'ticket-card--analyst' : ''; ?>"
  data-ticket-id="<?php echo $id; ?>"
  data-search="<?php echo h(strtolower($id.' '.$fecha.' '.$user.' '.$prob.' '.$prio.' '.$estado)); ?>"
>


  <header class="ticket-card__top">
    <div class="ticket-id">#<?php echo $id; ?></div>
    <div class="ticket-date"><?php echo h($fecha); ?></div>
  </header>

  <?php if ($isFromAnalyst): ?>
    <div style="margin:6px 0 2px; font-size:12px; font-weight:900; color:#6e1c5c;">
      Ticket de analista: <?php echo h($requesterName); ?>
    </div>
  <?php endif; ?>

  <div class="ticket-card__body">
    <div class="ticket-row">
      <span class="ticket-label">Usuario</span>
      <span class="ticket-value"><?php echo h($user); ?></span>
    </div>

    <div class="ticket-row">
      <span class="ticket-label">Problema</span>
      <span class="ticket-value"><?php echo h($prob); ?></span>
    </div>

    <div class="ticket-row">
      <span class="ticket-label">Prioridad</span>
      <span class="priority-pill priority-<?php echo h($prio); ?>">
        <?php echo h(prioridadLabel((string)($t['prioridad'] ?? 'media'))); ?>
      </span>
    </div>

    <div class="ticket-row">
      <span class="ticket-label">Estatus</span>
      <select
        class="ticket-status-select status-<?php echo h($estado); ?>"
        data-ticket-id="<?php echo $id; ?>"
        data-prev="<?php echo h($estado); ?>"
      >
        <?php if (!empty($statusCatalog)): ?>
          <?php foreach ($statusCatalog as $s): ?>
            <?php
              $code = (string)($s['code'] ?? '');
              $name = (string)($s['label'] ?? $code);
              if ($code === '') continue;
            ?>
            <option value="<?php echo h($code); ?>" <?php echo ($code === $estado) ? 'selected' : ''; ?>>
              <?php echo h($name); ?>
            </option>
          <?php endforeach; ?>
        <?php else: ?>
          <option value="abierto" <?php echo ($estado==='abierto')?'selected':''; ?>>Abierto</option>
          <option value="en_proceso" <?php echo ($estado==='en_proceso')?'selected':''; ?>>En proceso</option>
          <option value="soporte" <?php echo ($estado==='soporte')?'selected':''; ?>>Soporte ViSo</option>
          <option value="cerrado" <?php echo ($estado==='cerrado')?'selected':''; ?>>Cerrado</option>
        <?php endif; ?>
      </select>
    </div>
  </div>
<?php if (!empty($myToTI)): ?>
  <?php foreach ($myToTI as $t): ?>
    <?php
      $id    = (int)$t['id'];
      $fecha = (string)($t['fecha_envio'] ?? '');
      $prob  = (string)($t['problema_label'] ?? '');
      $prio  = strtolower((string)($t['prioridad'] ?? 'media'));
      $estado = (string)($t['estado'] ?? 'abierto');
      $email = (string)($t['email'] ?? '');
      $atiende = trim((string)($t['atendido_por'] ?? ''));
    ?>
    <article
      class="ticket-card js-ticket ticket-card--support"
      data-ticket-id="<?php echo $id; ?>"
      data-support="1"
      data-search="<?php echo h(strtolower($id.' '.$fecha.' '.$prob.' '.$prio.' '.$estado.' '.$atiende)); ?>"
    >
      <header class="ticket-card__top">
        <div class="ticket-id">#<?php echo $id; ?></div>
        <div class="ticket-date"><?php echo h($fecha); ?></div>
      </header>

      <div style="margin:6px 0 2px; font-size:12px; font-weight:900; color:#6e1c5c;">
        Atiende: <?php echo h($atiende !== '' ? $atiende : 'Sin asignar'); ?>
      </div>

      <div class="ticket-card__body">
        <div class="ticket-row">
          <span class="ticket-label">Problema</span>
          <span class="ticket-value"><?php echo h($prob); ?></span>
        </div>

        <div class="ticket-row">
          <span class="ticket-label">Prioridad</span>
          <span class="priority-pill priority-<?php echo h($prio); ?>">
            <?php echo h(prioridadLabel((string)($t['prioridad'] ?? 'media'))); ?>
          </span>
        </div>

        <div class="ticket-row">
          <span class="ticket-label">Estatus</span>
          <span class="ticket-value"><?php echo h($estado); ?></span>
        </div>
      </div>

      <footer class="ticket-card__actions">
        <button type="button" class="btn-mini" onclick="openTicketDetail(<?php echo $id; ?>)">Ver</button>

        <button type="button"
          class="btn-mini primary"
          data-chat-btn
          data-ticket-id="<?php echo $id; ?>"
          onclick="openTicketChat(<?php echo $id; ?>,'<?php echo h($email); ?>')">
          Chat <span class="chat-badge" style="display:none;"></span>
        </button>
      </footer>
    </article>
  <?php endforeach; ?>
<?php endif; ?>

  <footer class="ticket-card__actions">
    <button type="button" class="btn-mini" onclick="openTicketDetail(<?php echo $id; ?>)">Ver</button>

    <button type="button"
            class="btn-mini primary"
            data-chat-btn
            data-ticket-id="<?php echo $id; ?>"
            onclick="openTicketChat(<?php echo $id; ?>,'<?php echo h($email); ?>')">
      Chat <span class="chat-badge" style="display:none;"></span>
    </button>
  </footer>
</article>

      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>


        </section>
    </section>
</main>

<!-- MODAL DETALLE TICKET -->
<div class="modal-backdrop" id="ticket-detail-modal">
  <div class="modal-card" style="max-width:760px;">
    <div class="modal-header">
      <h3 id="ticketDetailTitle">Detalle del ticket</h3>
      <button type="button" class="modal-close" onclick="closeTicketDetail()">✕</button>
    </div>
    <div class="modal-body" style="padding:14px 18px;">
      <div id="ticketDetailContent" class="ticket-detail-grid">
        <div style="opacity:.8;">Cargando...</div>
      </div>
      <div class="modal-actions" style="margin-top:14px; display:flex; gap:10px; justify-content:flex-end;">
        <button type="button" class="btn-primary" id="ticketDetailChatBtn" style="display:none;">Abrir chat</button>
        <button type="button" class="btn-secondary" onclick="closeTicketDetail()">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- MODAL CHAT -->
<div class="modal-backdrop" id="ticket-chat-modal">
    <div class="modal-card ticket-chat-modal-card">
        <div class="modal-header">
            <h3 id="ticketChatTitle">Chat del ticket</h3>
            <button type="button" class="modal-close" onclick="closeTicketChat()">✕</button>
        </div>

        <div class="ticket-chat-body" id="ticketChatBody">
          <div id="ticketTransferBox"></div>
  <div id="ticketChatMessages"></div>
</div>

        <form class="ticket-chat-form" onsubmit="sendTicketMessage(event)">
            <div class="ticket-chat-internal-row" style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
              <input type="checkbox" id="ticketChatInternal" value="1">
              <label for="ticketChatInternal" style="font-size:13px; opacity:.85;">
                Nota               </label>
            </div>

<textarea id="ticketChatInput" rows="2" placeholder="Escribe tu mensaje..." style="width:100%"
  onkeydown="ticketChatEnterSend(event)"></textarea>
            <div class="ticket-chat-input-row">
                <input type="file" id="ticketChatFile" name="adjunto" class="ticket-chat-file"
                       accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv" style="width:100%">
                <button type="submit" class="btn-primary" style="min-width: 60px;">Enviar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL CREAR TICKET (Analista) -->
<div class="eqf-modal-backdrop" id="createTicketBackdrop" aria-hidden="true">
  <div class="eqf-modal" role="dialog" aria-modal="true" aria-labelledby="createTicketTitle">

    <div class="eqf-modal-header">
      <h2 id="createTicketTitle">Crear ticket</h2>
      <button type="button" class="eqf-modal-close" id="btnCloseCreateTicket" aria-label="Cerrar">✕</button>
    </div>

    <form id="formCreateTicket">
      <div class="eqf-modal-body">

        <div class="eqf-grid-2" style="align-items:end;">
          <div class="eqf-field" style="position:relative;">
            <label for="ct_email">Correo del usuario</label>

            <div style="position:relative;">
              <input
                type="text"
                id="ct_email"
                name="email"
                list="usersEmailList"
                placeholder="usuario@eqf.com"
                autocomplete="off"
                required
                style="padding-right:34px;"
              >
              <span id="ct_email_ok" class="ct-ok" aria-hidden="true" title="Usuario encontrado"
                    style="display:none; position:absolute; right:12px; top:50%; transform:translateY(-50%);">
                ✓
              </span>
            </div>

            <datalist id="usersEmailList"></datalist>
          </div>

          <div class="eqf-field" style="display:flex; align-items:center; gap:10px; padding-top:22px;">
            <input type="checkbox" id="ct_ticket_mi">
            <label for="ct_ticket_mi" style="margin:0;">Ticket para mí</label>
          </div>
        </div>

        <div class="eqf-grid-2">
          <div class="eqf-field">
            <label>#SAP</label>
            <input type="text" id="ct_sap" disabled>
          </div>
          <div class="eqf-field">
            <label>Nombre</label>
            <input type="text" id="ct_nombre" disabled>
          </div>
        </div>

        <div class="eqf-grid-2">
          <div class="eqf-field">
            <label>Área</label>
            <input type="text" id="ct_area" disabled>
          </div>
          <div class="eqf-field">
            <label>Correo</label>
            <input type="text" id="ct_email_locked" disabled>
          </div>
        </div>

        <div class="eqf-field">
          <label>Área destino</label>
          <input type="text" id="ct_area_destino" disabled value="">
        </div>

        <hr class="eqf-hr">

        <div class="eqf-field">
          <label for="ct_descripcion">Descripción</label>
          <textarea
            id="ct_descripcion"
            name="descripcion"
            rows="4"
            required
            placeholder="Describe el problema, mensaje de error y qué intentaron."
          ></textarea>
        </div>

        <div class="eqf-alert" id="ct_msg" style="display:none;"></div>
      </div>

      <div class="eqf-modal-footer">
        <button type="button" class="btn-secondary" id="btnCancelCreateTicket">Cancelar</button>
        <button type="submit" class="btn-primary" id="btnSubmitCreateTicket" disabled>Guardar</button>
      </div>
    </form>

  </div>
</div>

<?php include __DIR__ . '/../../../template/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script>
/**
 * ===============================
 *  GLOBALS + UTILS
 * ===============================
 */
const CURRENT_USER_ID = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
const shownFeedbackTokens = new Set();

window.CURRENT_USER_ID = CURRENT_USER_ID;
window.CURRENT_USER_ROLE = <?php echo (int)($_SESSION['user_rol'] ?? 0); ?>;

const CURRENT_USER = <?php echo json_encode([
  'id'        => (int)($_SESSION['user_id'] ?? 0),
  'sap'       => (string)($_SESSION['number_sap'] ?? ''),
  'name'      => (string)($_SESSION['user_name'] ?? ''),
  'last_name' => (string)($_SESSION['user_last'] ?? ''),
  'email'     => (string)($_SESSION['user_email'] ?? ''),
  'area'      => (string)($_SESSION['user_area'] ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

window.CURRENT_USER = CURRENT_USER;

const STATUS_CATALOG = <?php echo json_encode($statusCatalog, JSON_UNESCAPED_UNICODE); ?>;

let currentTicketId = null;
let lastMessageId   = 0;
let chatPollTimer   = null;

function showTicketToast(text) {
  const toast = document.createElement('div');
  toast.className = 'eqf-toast-ticket';
  toast.textContent = text || '';
  document.body.appendChild(toast);
  setTimeout(() => {
    toast.classList.add('hide');
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}




function escapeHtml(str){
  return String(str ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}


function buildStatusSelect(ticketId, currentCode){
  const estado = String(currentCode || 'abierto');

  if (!Array.isArray(STATUS_CATALOG) || !STATUS_CATALOG.length){
    // fallback
    return `
      <select class="ticket-status-select status-${escapeHtml(estado)}"
              data-ticket-id="${escapeHtml(ticketId)}"
              data-prev="${escapeHtml(estado)}">
        <option value="abierto" ${estado==='abierto'?'selected':''}>Abierto</option>
        <option value="en_proceso" ${estado==='en_proceso'?'selected':''}>En proceso</option>
        <option value="cerrado" ${estado==='cerrado'?'selected':''}>Cerrado</option>
      </select>
    `;
  }

  const opts = STATUS_CATALOG.map(s => {
    const code = String(s.code || '');
    const name = String(s.label || code);
    if (!code) return '';
    const sel = (code === estado) ? 'selected' : '';
    return `<option value="${escapeHtml(code)}" ${sel}>${escapeHtml(name)}</option>`;
  }).join('');

  return `
    <select class="ticket-status-select status-${escapeHtml(estado)}"
            data-ticket-id="${escapeHtml(ticketId)}"
            data-prev="${escapeHtml(estado)}">
      ${opts}
    </select>
  `;
}

/**
 * every(): scheduler “inteligente” (pausa en pestaña oculta)
 */
function every(ms, fn){
  let t = null;

  const start = () => { if (!t) t = setInterval(fn, ms); };
  const stop  = () => { if (t) { clearInterval(t); t = null; } };

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stop();
    else { try { fn(); } catch(e){} start(); }
  });

  try { fn(); } catch(e){}
  start();
  return { start, stop };
}
</script>

<script>
/**
 * ===============================
 *  DETALLE TICKET
 * ===============================
 */
let detailTicketId = null;
let detailTicketUserName = '';

function openTicketDetail(ticketId){
  detailTicketId = ticketId;

  const modal   = document.getElementById('ticket-detail-modal');
  const content = document.getElementById('ticketDetailContent');
  const title   = document.getElementById('ticketDetailTitle');
  const chatBtn = document.getElementById('ticketDetailChatBtn');

  if (title)   title.textContent = 'Detalle del ticket #' + ticketId;
  if (content) content.innerHTML = '<div style="opacity:.8;">Cargando...</div>';
  if (chatBtn) chatBtn.style.display = 'none';

  if (typeof openModal === 'function') openModal('ticket-detail-modal');
  else modal.classList.add('show');

  fetch('/HelpDesk_EQF/modules/ticket/ticket_view.php?ticket_id=' + encodeURIComponent(ticketId), {cache:'no-store'})
    .then(r => r.json())
    .then(data => {
      if (!data.ok) {
        content.innerHTML = '<div style="color:#b91c1c; font-weight:800;">' + escapeHtml(data.msg || 'No se pudo cargar') + '</div>';
        return;
      }

      const t = data.ticket || {};
      const atts = Array.isArray(data.attachments) ? data.attachments : [];
      detailTicketUserName = t.nombre || '';

      const meta = `
        <div class="ticket-detail-meta">
          <div><strong>Usuario:</strong> ${escapeHtml(t.nombre || '')} ${t.sap ? '(SAP: '+escapeHtml(t.sap)+')' : ''}</div>
          <div><strong>Correo:</strong> ${escapeHtml(t.email || '')}</div>
          <div><strong>Área:</strong> ${escapeHtml(t.area || '')}</div>
          <div><strong>Prioridad:</strong> ${escapeHtml(t.prioridad || '')}</div>
          <div><strong>Estatus:</strong> ${escapeHtml(t.estado || '')}</div>
        </div>
      `;

      const prob = `<div><strong>Problema:</strong> ${escapeHtml(t.problema_label || t.problema_raw || '')}</div>`;
      const desc = `<div><strong>Descripción:</strong><div style="margin-top:6px; white-space:pre-wrap;">${escapeHtml(t.descripcion || '')}</div></div>`;

      let attHtml = '<div><strong>Adjuntos:</strong><div style="margin-top:6px; opacity:.75;">Sin adjuntos.</div></div>';
      if (atts.length){
        attHtml = `<div><strong>Adjuntos:</strong><div class="ticket-attachments" style="margin-top:6px;">${
          atts.map(a => {
            const name = a.file_name || 'archivo';
            const path = a.file_path || '#';
            return `<a href="${escapeHtml(path)}" target="_blank" rel="noopener">📎 ${escapeHtml(name)}</a>`;
          }).join('')
        }</div></div>`;
      }

      content.innerHTML = meta + prob + desc + attHtml;

      if (chatBtn){
        chatBtn.style.display = 'inline-block';
        chatBtn.onclick = () => {
          closeTicketDetail();
          openTicketChat(ticketId, detailTicketUserName || ('Ticket #' + ticketId));
        };
      }
    })
    .catch(err => {
      console.error(err);
      content.innerHTML = '<div style="color:#b91c1c; font-weight:800;">Error al cargar detalle.</div>';
    });
}

function closeTicketDetail(){
  const modal = document.getElementById('ticket-detail-modal');
  if (typeof closeModal === 'function') closeModal('ticket-detail-modal');
  else modal.classList.remove('show');

  detailTicketId = null;
  detailTicketUserName = '';
}
</script>
<script>
/**
 * ===============================
 *  CHAT
 * ===============================
 */
 let clipboardFiles = [];
 
function openTicketChat(ticketId, tituloExtra) {
  currentTicketId = ticketId;
  lastMessageId   = 0;
clipboardFiles = [];
renderClipboardPreview(); // limpia preview

  
  const titleEl = document.getElementById('ticketChatTitle');
  if (titleEl) titleEl.textContent = 'Chat del ticket #' + ticketId + (tituloExtra ? ' – ' + tituloExtra : '');

  const bodyEl = document.getElementById('ticketChatBody');
if (bodyEl) bodyEl.scrollTop = 0;

const trBox = document.getElementById('ticketTransferBox');
const msgBox = document.getElementById('ticketChatMessages');
if (trBox) trBox.innerHTML = '';
if (msgBox) msgBox.innerHTML = '';


  const modal = document.getElementById('ticket-chat-modal');
  if (typeof openModal === 'function') openModal('ticket-chat-modal');
  else modal.classList.add('show');

  fetch('/HelpDesk_EQF/modules/ticket/get_transfer_context.php?ticket_id=' + encodeURIComponent(ticketId), {cache:'no-store'})
    .then(r => r.json())
    .then(data => { if (data && data.ok) renderTransferBlock(data); })
    .catch(err => console.error('Error transfer context:', err));

  fetchMessages(true);

  if (chatPollTimer) clearInterval(chatPollTimer);
  chatPollTimer = setInterval(() => fetchMessages(false), 5000);

  // marcar leído y quitar badge local inmediato
  fetch('/HelpDesk_EQF/modules/ticket/mark_read.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'ticket_id=' + encodeURIComponent(ticketId)
  }).then(()=> {
    const badge = document.querySelector(`[data-chat-btn][data-ticket-id="${ticketId}"] .chat-badge`);
    if (badge){ badge.style.display='none'; badge.textContent=''; }
  }).catch(()=>{});
}

function closeTicketChat() {
  const modal = document.getElementById('ticket-chat-modal');
  if (typeof closeModal === 'function') closeModal('ticket-chat-modal');
  else modal.classList.remove('show');

  if (chatPollTimer) { clearInterval(chatPollTimer); chatPollTimer = null; }

  const old = document.getElementById('transfer-block');
  if (old) old.remove();
clipboardFiles = [];
renderClipboardPreview();

  currentTicketId = null;
}

function appendChatMessage(msg) {
const bodyEl = document.getElementById('ticketChatMessages');
  if (!bodyEl) return;

  const div = document.createElement('div');
  div.className = 'ticket-chat-message';

  if (String(msg.is_internal) === '1') {
    const badge = document.createElement('span');
    badge.textContent = 'NOTA ';
    badge.style.fontSize = '12px';
    badge.style.opacity = '.8';
    badge.style.display = 'block';
    badge.style.marginBottom = '4px';
    div.appendChild(badge);
  }

const senderId = parseInt(msg.sender_id ?? 0, 10);
const myId = parseInt(window.CURRENT_USER_ID ?? 0, 10);

let isMine = (senderId > 0 && myId > 0 && senderId === myId);

// fallback: si el backend no manda sender_id correctamente
if (!isMine && (!senderId || !myId)) {
  const senderRole = String(msg.sender_role ?? '').toLowerCase();
  const myRoleNum  = String(window.CURRENT_USER_ROLE ?? '').trim(); // ej: "3"
  // si eres analista (3) y el mensaje dice "analista", lo tomo como mío
  if (myRoleNum === '3' && senderRole.includes('analista')) isMine = true;
  if (myRoleNum === '4' && senderRole.includes('usuario'))  isMine = true;
}


  div.classList.add(isMine ? 'mine' : 'other');

  if (msg.mensaje) {
    const textSpan = document.createElement('span');
    textSpan.textContent = msg.mensaje;
    div.appendChild(textSpan);
  }

  if (msg.file_url) {
    const fileWrapper = document.createElement('div');
    fileWrapper.style.marginTop = '6px';

    const url  = msg.file_url;
    const name = msg.file_name || 'Archivo adjunto';
    const type = msg.file_type || '';

    if (type && type.startsWith('image/')) {
      const imgLink = document.createElement('a');
      imgLink.href   = url;
      imgLink.target = '_blank';
      imgLink.rel    = 'noopener';

      const img = document.createElement('img');
      img.src = url;
      img.alt = name;
      img.className = 'ticket-chat-image';

      imgLink.appendChild(img);
      fileWrapper.appendChild(imgLink);
    } else {
      const link = document.createElement('a');
      link.href   = url;
      link.target = '_blank';
      link.rel    = 'noopener';
      link.textContent = '📎 ' + name;
      fileWrapper.appendChild(link);
    }

    div.appendChild(fileWrapper);
  }

  const meta = document.createElement('span');
  meta.className = 'ticket-chat-meta';

const who = isMine
  ? 'Tú'
  : ((msg.sender_name && String(msg.sender_name).trim())
      ? String(msg.sender_name).trim()
      : (msg.sender_role || ''));

meta.textContent = who + ' · ' + (msg.created_at || '');

  div.appendChild(meta);

  bodyEl.appendChild(div);
  bodyEl.scrollTop = bodyEl.scrollHeight;
}

function renderTransferBlock(payload){
const bodyEl = document.getElementById('ticketTransferBox');
  if (!bodyEl) return;

  const old = document.getElementById('transfer-block');
  if (old) old.remove();

  if (!payload || !payload.has_transfer) return;

  const t = payload.transfer || {};
  const msgs = Array.isArray(payload.messages) ? payload.messages : [];
  const files = Array.isArray(payload.files) ? payload.files : [];

  const wrap = document.createElement('div');
  wrap.id = 'transfer-block';
  wrap.className = 'ticket-transfer-block';

  const header = document.createElement('div');
  header.className = 'ticket-transfer-header';
  header.innerHTML = `
    <strong>Historial transferido</strong>
    <div class="ticket-transfer-sub">
      ${escapeHtml(t.from_area)} → ${escapeHtml(t.to_area)}
      ${t.created_at ? ' · ' + escapeHtml(t.created_at) : ''}
    </div>
    ${t.motivo ? `<div class="ticket-transfer-motivo">Motivo: ${escapeHtml(t.motivo)}</div>` : ''}
    <div class="ticket-transfer-note">Este historial es informativo (bloqueado).</div>
  `;
  wrap.appendChild(header);

  const list = document.createElement('div');
  list.className = 'ticket-transfer-messages';

  msgs.forEach(m => {
    const item = document.createElement('div');
    item.className = 'ticket-transfer-msg';
    item.innerHTML = `
      <div class="ticket-transfer-msg-top">
        <span class="role">${escapeHtml(m.sender_role || '')}</span>
        <span class="name">${escapeHtml(m.sender_name || '')}</span>
        <span class="at">${escapeHtml(m.created_at || '')}</span>
      </div>
      <div class="ticket-transfer-msg-text">${escapeHtml(m.message || '')}</div>
    `;
    list.appendChild(item);
  });

  if (files.length) {
    const fwrap = document.createElement('div');
    fwrap.className = 'ticket-transfer-files';
    fwrap.innerHTML = `<div class="ticket-transfer-files-title">Adjuntos transferidos</div>`;
    files.forEach(f => {
      const a = document.createElement('a');
      a.className = 'ticket-transfer-file';
      a.href = f.file_path;
      a.target = '_blank';
      a.rel = 'noopener';
      a.textContent = '📎 ' + (f.file_name || 'archivo');
      fwrap.appendChild(a);
    });
    list.appendChild(fwrap);
  }

  wrap.appendChild(list);
bodyEl.innerHTML = '';
bodyEl.appendChild(wrap);
}

function fetchMessages(isInitial=false) {
  if (!currentTicketId) return;

  const url = '/HelpDesk_EQF/modules/ticket/get_messages.php'
    + '?ticket_id=' + encodeURIComponent(currentTicketId)
    + '&last_id=' + encodeURIComponent(lastMessageId);

  fetch(url, {cache:'no-store'})
    .then(r => r.json())
    .then(data => {
      if (!data.ok || !Array.isArray(data.messages)) return;
      data.messages.forEach(m => {
        appendChatMessage(m);
        if (m.id > lastMessageId) lastMessageId = m.id;
      });
    })
    .catch(err => console.error('Error obteniendo mensajes:', err));
}
function ensureClipboardPreviewBox() {
  let box = document.getElementById('ticketChatPastePreview');
  if (box) return box;

  const input = document.getElementById('ticketChatInput');
  if (!input) return null;

  box = document.createElement('div');
  box.id = 'ticketChatPastePreview';
  box.style.display = 'none';
  box.style.gap = '8px';
  box.style.marginTop = '8px';
  box.style.flexWrap = 'wrap';
  box.style.alignItems = 'center';

  input.insertAdjacentElement('afterend', box);
  return box;
}

function renderClipboardPreview() {
  const box = ensureClipboardPreviewBox();
  if (!box) return;

  if (!clipboardFiles.length) {
    box.style.display = 'none';
    box.innerHTML = '';
    return;
  }

  box.style.display = 'flex';
  box.innerHTML = '';

  clipboardFiles.forEach((file, idx) => {
    const url = URL.createObjectURL(file);

    const wrap = document.createElement('div');
    wrap.style.position = 'relative';
    wrap.style.width = '120px';

    const img = document.createElement('img');
    img.src = url;
    img.style.width = '120px';
    img.style.height = '80px';
    img.style.objectFit = 'cover';
    img.style.borderRadius = '10px';
    img.style.border = '1px solid #ddd';

    const del = document.createElement('button');
    del.type = 'button';
    del.textContent = '✕';
    del.style.position = 'absolute';
    del.style.top = '6px';
    del.style.right = '6px';
    del.style.border = '0';
    del.style.borderRadius = '999px';
    del.style.width = '26px';
    del.style.height = '26px';
    del.style.cursor = 'pointer';
    del.style.background = 'rgba(0,0,0,.6)';
    del.style.color = '#fff';

    del.onclick = () => {
      clipboardFiles.splice(idx, 1);
      renderClipboardPreview();
    };

    wrap.appendChild(img);
    wrap.appendChild(del);
    box.appendChild(wrap);
  });
}

function sendTicketMessage(ev) {
  ev.preventDefault();
  if (!currentTicketId) return;

  const input     = document.getElementById('ticketChatInput');
  const fileInput = document.getElementById('ticketChatFile');
  if (!input) return;

  const texto = input.value.trim();
  const file  = (fileInput && fileInput.files.length > 0) ? fileInput.files[0] : null;
if (!texto && !file && clipboardFiles.length === 0) return;

  input.disabled = true;
  if (fileInput) fileInput.disabled = true;

  const formData = new FormData();
  const internalCb = document.getElementById('ticketChatInternal');
  const isInternal = (internalCb && internalCb.checked) ? 1 : 0;

  formData.append('interno', isInternal);
  formData.append('ticket_id', currentTicketId);
  formData.append('mensaje', texto);
  if (file) formData.append('adjunto', file);
  clipboardFiles.forEach(f => formData.append('clipboard_files[]', f));


  fetch('/HelpDesk_EQF/modules/ticket/send_messages.php', { method: 'POST', body: formData })
    .then(response => {
      input.disabled = false;
      if (fileInput) {
        fileInput.disabled = false;
        fileInput.value = '';
        if (internalCb) internalCb.checked = false;
      }
      if (!response.ok) { alert('No se pudo enviar el mensaje'); return; }
      input.value = '';
      clipboardFiles = [];
renderClipboardPreview();

      input.focus();
      fetchMessages(false);
    })
    .catch(err => {
      console.error(err);
      input.disabled = false;
      if (fileInput) fileInput.disabled = false;
      alert('Error al enviar el mensaje');
    });
}

function ticketChatEnterSend(e){
  // Enter envía, Shift+Enter hace salto de línea
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendTicketMessage(e);
  }
}

document.addEventListener('paste', (e) => {
  if (!currentTicketId) return;

  const input = document.getElementById('ticketChatInput');
  if (!input) return;

  // Solo si el foco está en el input del chat
  if (document.activeElement !== input) return;

  const items = e.clipboardData?.items;
  if (!items) return;

  let added = false;

  for (const item of items) {
    if (item.type && item.type.startsWith('image/')) {
      const blob = item.getAsFile();
      if (!blob) continue;

      const ext = blob.type.split('/')[1] || 'png';
      const file = new File([blob], `screenshot_${Date.now()}.${ext}`, { type: blob.type });

      clipboardFiles.push(file);
      added = true;
    }
  }

  if (added) {
    e.preventDefault();
    renderClipboardPreview();
  }
});

</script>

<script>
/**
 * ===============================
 *  CARDS + ASIGNACIÓN + ESTADO + POLLING
 * ===============================
 */
const incomingGrid = document.getElementById('incomingGrid');
const myGrid = document.getElementById('myGrid');

function setEmptyIfNeeded(grid){
  if (!grid) return;
  const emptyText = grid.getAttribute('data-empty') || 'Sin elementos.';
  const hasCards = grid.querySelector('.js-ticket');
  const existing = grid.querySelector('.tickets-empty');

  if (!hasCards){
    if (!existing){
      const div = document.createElement('div');
      div.className = 'tickets-empty';
      div.textContent = emptyText;
      grid.appendChild(div);
    }
  } else {
    if (existing) existing.remove();
  }
}

function renderPriorityPill(priorityRaw) {
  const p = (priorityRaw || 'media').toLowerCase();
  let label = 'Media';
  if (p === 'alta') label = 'Alta';
  else if (p === 'baja') label = 'Baja';
  else if (p === 'critica' || p === 'crítica') label = 'Crítica';
  return `<span class="priority-pill priority-${escapeHtml(p)}">${escapeHtml(label)}</span>`;
}

function cardSearchText(obj){
  // arma string buscable (lower)
  const parts = [];
  for (const k in obj) parts.push(String(obj[k] ?? ''));
  return parts.join(' ').toLowerCase();
}

function buildIncomingCard(ticket){
  const id = parseInt(ticket.id, 10);
  const fecha = ticket.fecha || ticket.fecha_envio || '';
  const usuario = ticket.usuario || ticket.nombre || '';
  const problema = ticket.problema || ticket.problema_label || '';
  const descripcion = ticket.descripcion || '';
  const prio = (ticket.prioridad || 'media').toLowerCase();
  const fromArea = (ticket.from_area || '').trim();
const fromName = (ticket.from_name || '').trim();
const fromTag = (fromArea || fromName)
  ? `<div style="margin:6px 0 2px; font-size:12px; font-weight:900; color:#6e1c5c;">
       Enviado por: ${escapeHtml(fromArea || 'Área')} ${fromName ? '· ' + escapeHtml(fromName) : ''}
     </div>`
  : '';

  const article = document.createElement('article');
  article.className = 'ticket-card js-ticket'; // <- sin tag de analista aquí
  article.setAttribute('data-ticket-id', String(id));
  article.setAttribute('data-search', cardSearchText({id,fecha,usuario,problema,descripcion,prio}));

  article.innerHTML = `
    <header class="ticket-card__top">
    <div class="ticket-id">#${escapeHtml(id)}</div>
    <div class="ticket-date">${escapeHtml(fecha)}</div>
  </header>
  ${fromTag}

    <div class="ticket-card__body">
      <div class="ticket-row">
        <span class="ticket-label">Usuario</span>
        <span class="ticket-value">${escapeHtml(usuario)}</span>
      </div>

      <div class="ticket-row">
        <span class="ticket-label">Problema</span>
        <span class="ticket-value">${escapeHtml(problema)}</span>
      </div>

      <div class="ticket-row">
        <span class="ticket-label">Prioridad</span>
        ${renderPriorityPill(prio)}
      </div>

      <div class="ticket-desc">${escapeHtml(descripcion)}</div>
    </div>

    <footer class="ticket-card__actions">
      <button type="button" class="btn-mini" onclick="openTicketDetail(${escapeHtml(id)})">Previsualizar</button>
      <button type="button" class="btn-mini primary btn-assign-ticket" data-ticket-id="${escapeHtml(id)}">Asignar</button>
    </footer>
  `;

  return article;
}


function buildMyCard(ticket){
  const id = parseInt(ticket.id, 10);
  const fecha = ticket.fecha_envio || ticket.fecha || '';
  const usuario = ticket.usuario || ticket.nombre || '';
  const problema = ticket.problema_label || ticket.problema || ticket.problema_raw || '';
  const prio = (ticket.prioridad || 'media').toLowerCase();
  const estado = ticket.estado || 'en_proceso';
  const email = ticket.email || '';
  const requesterRol = parseInt(ticket.requester_rol ?? 0, 10);
const requesterId  = parseInt(ticket.requester_id ?? 0, 10);
const isFromAnalyst = (requesterRol === 3 && requesterId && requesterId !== parseInt(window.CURRENT_USER_ID ?? 0, 10));
const requesterName = ticket.requester_name || '';
const analystTag = isFromAnalyst
  ? `<div style="margin:6px 0 2px; font-size:12px; font-weight:900; color:#6e1c5c;">
       Ticket de analista: ${escapeHtml(requesterName || 'Analista')}
     </div>`
  : '';


  const statusSelect = buildStatusSelect(id, estado);

  const article = document.createElement('article');
article.className = 'ticket-card js-ticket' + (isFromAnalyst ? ' ticket-card--analyst' : '');
  article.setAttribute('data-ticket-id', String(id));
  article.setAttribute('data-search', cardSearchText({id,fecha,usuario,problema,prio,estado,email}));

  article.innerHTML = `
    <header class="ticket-card__top">
      <div class="ticket-id">#${escapeHtml(id)}</div>
      <div class="ticket-date">${escapeHtml(fecha)}</div>
    </header>
  ${analystTag}

    <div class="ticket-card__body">
      <div class="ticket-row">
        <span class="ticket-label">Usuario</span>
        <span class="ticket-value">${escapeHtml(usuario)}</span>
      </div>

      <div class="ticket-row">
        <span class="ticket-label">Problema</span>
        <span class="ticket-value">${escapeHtml(problema)}</span>
      </div>

      <div class="ticket-row">
        <span class="ticket-label">Prioridad</span>
        ${renderPriorityPill(prio)}
      </div>

      <div class="ticket-row">
        <span class="ticket-label">Estatus</span>
        ${statusSelect}
      </div>
    </div>

    <footer class="ticket-card__actions">
      <button type="button" class="btn-mini" onclick="openTicketDetail(${escapeHtml(id)})">Ver</button>

      <button type="button"
              class="btn-mini primary"
              data-chat-btn
              data-ticket-id="${escapeHtml(id)}"
              onclick="openTicketChat(${escapeHtml(id)}, '${escapeHtml(email)}')">
        Chat <span class="chat-badge" style="display:none;"></span>
      </button>
    </footer>
  `;

  return article;
}
function buildSupportCard(t){
  const id = parseInt(t.id,10);
  const fecha = t.fecha_envio || '';
  const problema = t.problema_label || t.problema_raw || '';
  const prio = (t.prioridad || 'media').toLowerCase();
  const estado = t.estado || 'abierto';
  const email = t.email || '';
  const atiende = (t.atendido_por || '').trim();

  const article = document.createElement('article');
  article.className = 'ticket-card js-ticket ticket-card--support';
  article.dataset.ticketId = String(id);
  article.dataset.support = '1';

  article.setAttribute('data-search', cardSearchText({
    id, fecha, problema, prio, estado, atiende
  }));

  article.innerHTML = `
    <header class="ticket-card__top">
      <div class="ticket-id">#${escapeHtml(id)}</div>
      <div class="ticket-date">${escapeHtml(fecha)}</div>
    </header>

    <div style="margin:6px 0 2px; font-size:12px; font-weight:900; color:#6e1c5c;">
      Atiende: ${escapeHtml(atiende || 'Sin asignar')}
    </div>

    <div class="ticket-card__body">
      <div class="ticket-row">
        <span class="ticket-label">Problema</span>
        <span class="ticket-value">${escapeHtml(problema)}</span>
      </div>

      <div class="ticket-row">
        <span class="ticket-label">Prioridad</span>
        ${renderPriorityPill(prio)}
      </div>

      <div class="ticket-row">
        <span class="ticket-label">Estatus</span>
        <span class="ticket-value">${escapeHtml(estado)}</span>
      </div>
    </div>

    <footer class="ticket-card__actions">
      <button type="button" class="btn-mini" onclick="openTicketDetail(${escapeHtml(id)})">Ver</button>
      <button type="button"
              class="btn-mini primary"
              data-chat-btn
              data-ticket-id="${escapeHtml(id)}"
              onclick="openTicketChat(${escapeHtml(id)}, '${escapeHtml(email)}')">
        Chat <span class="chat-badge" style="display:none;"></span>
      </button>
    </footer>
  `;

  return article;
}

function bumpKpi(deltaAbiertos, deltaEnProceso){
  const a = document.getElementById('kpiAbiertos');
  const p = document.getElementById('kpiEnProceso');
  if (a) a.textContent = Math.max(0, (parseInt(a.textContent || '0',10) + deltaAbiertos));
  if (p) p.textContent = Math.max(0, (parseInt(p.textContent || '0',10) + deltaEnProceso));
}

function addIncomingTicketCard(ticket) {
  if (!incomingGrid || !ticket || !ticket.id) return;

  const id = String(ticket.id);
  if (incomingGrid.querySelector(`.js-ticket[data-ticket-id="${CSS.escape(id)}"]`)) return;

  incomingGrid.prepend(buildIncomingCard(ticket));
  setEmptyIfNeeded(incomingGrid);
  bumpKpi(+1, 0);
}

function removeIncomingCard(ticketId){
  if (!incomingGrid) return;
  const el = incomingGrid.querySelector(`.js-ticket[data-ticket-id="${CSS.escape(String(ticketId))}"]`);
  if (el) el.remove();
  setEmptyIfNeeded(incomingGrid);
}

function addMyTicketCard(ticket){
  if (!myGrid || !ticket || !ticket.id) return;

  const id = String(ticket.id);
  if (myGrid.querySelector(`.js-ticket[data-ticket-id="${CSS.escape(id)}"]`)) return;

  myGrid.prepend(buildMyCard(ticket));
  setEmptyIfNeeded(myGrid);
}

function removeMyCard(ticketId){
  if (!myGrid) return;
  const el = myGrid.querySelector(`.js-ticket[data-ticket-id="${CSS.escape(String(ticketId))}"]`);
  if (el) el.remove();
  setEmptyIfNeeded(myGrid);
}

/* ===== POLLS ===== */
let lastTicketId = <?php echo (int)$maxIncomingId; ?>;
let myTiSig = '';

async function pollMyTiSnapshot(){
  try{
    const r = await fetch('/HelpDesk_EQF/modules/ticket/my_ti_snapshot.php', {cache:'no-store'});
    const j = await r.json();
    if (!r.ok || !j || !j.ok) return;

    if (j.signature && j.signature === myTiSig) return;
    myTiSig = j.signature || '';

    const items = j.items || [];
    const should = new Set(items.map(x => String(x.id)));

    // quitar cards support que ya no estén
    myGrid?.querySelectorAll('.js-ticket[data-support="1"]').forEach(card => {
      const id = card.getAttribute('data-ticket-id');
      if (id && !should.has(String(id))) card.remove();
    });

    // agregar/actualizar
    items.forEach(t => {
      const id = String(t.id);
      const existing = myGrid?.querySelector(`.js-ticket[data-support="1"][data-ticket-id="${CSS.escape(id)}"]`);
      const node = buildSupportCard(t);

      if (!existing) myGrid?.prepend(node);
      else existing.replaceWith(node);
    });
// Auto-abrir encuesta si hay ticket cerrado con token pendiente
for (const t of items) {
  const estado = String(t.estado || '');
  const tok = String(t.feedback_token || '').trim();
  const tid = parseInt(t.id || 0, 10);

  if (estado === 'cerrado' && tok && tid > 0 && !shownFeedbackTokens.has(tok)) {
    shownFeedbackTokens.add(tok);
    openFeedbackIframe(tok, tid, 'Encuesta de satisfacción – Ticket #' + tid);
    break; // abre una a la vez
  }
}

    setEmptyIfNeeded(myGrid);
  }catch(e){
    console.warn('pollMyTiSnapshot', e);
  }
}

function showDesktopNotification(ticket) {
  if (!('Notification' in window)) return;
  if (Notification.permission !== 'granted') return;

  new Notification('Nuevo ticket entrante (#' + ticket.id + ')', {
    body: ticket.problema || '',
    icon: '/HelpDesk_EQF/assets/img/icon_helpdesk.png'
  });
}

function pollNewTickets() {
  fetch('/HelpDesk_EQF/modules/ticket/check_new.php?last_id=' + lastTicketId, {cache:'no-store'})
    .then(r => r.json())
    .then(data => {
      if (!data || !data.new) return;

      lastTicketId = data.id;

      showTicketToast('Nuevo ticket #' + data.id + ' – ' + (data.problema || ''));
      showDesktopNotification(data);

      addIncomingTicketCard(data);
    })
    .catch(err => console.error('Error comprobando nuevos tickets:', err));
}

function cleanupIncomingTaken() {
  if (!incomingGrid) return;

  const cards = Array.from(incomingGrid.querySelectorAll('.js-ticket[data-ticket-id]'));
  if (!cards.length) return;

  const ids = cards.map(c => c.getAttribute('data-ticket-id')).filter(Boolean);

  fetch('/HelpDesk_EQF/modules/ticket/incoming_snapshot.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'ids=' + encodeURIComponent(ids.join(','))
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) return;
    const still = new Set((data.available_ids || []).map(x => String(x)));

    cards.forEach(c => {
      const id = c.getAttribute('data-ticket-id');
      if (id && !still.has(String(id))) c.remove();
    });

    setEmptyIfNeeded(incomingGrid);
  })
  .catch(()=>{});
}

function applyUnreadBadges(items){
  const map = new Map();
  (items || []).forEach(it => map.set(String(it.ticket_id), parseInt(it.unread_count||0,10)));

  document.querySelectorAll('[data-chat-btn][data-ticket-id]').forEach(btn => {
    const id = btn.getAttribute('data-ticket-id');
    const badge = btn.querySelector('.chat-badge');
    const count = map.get(String(id)) || 0;

    if (!badge) return;

    if (count > 0){
      badge.style.display = 'inline-flex';
      badge.textContent = (count > 9) ? '9+' : String(count);
    } else {
      badge.style.display = 'none';
      badge.textContent = '';
    }
  });
}

function pollStaffUnread(){
  fetch('/HelpDesk_EQF/modules/ticket/staff_unread.php', {cache:'no-store'})
    .then(r=>r.json())
    .then(data=>{
      if (!data.ok) return;
      applyUnreadBadges(data.items);
    })
    .catch(()=>{});
}

/* ===== SNAPSHOT: MIS TICKETS (asignados a mí) ===== */
let mySnapshotSig = '';

async function pollMyAssignedSnapshot(){
  try{
    const r = await fetch('/HelpDesk_EQF/modules/ticket/my_assigned_snapshot.php', {cache:'no-store'});
    const j = await r.json();
    if (!r.ok || !j || !j.ok) return;

    if (j.signature && j.signature === mySnapshotSig) return;
    mySnapshotSig = j.signature || '';

    const items = j.items || [];
    const should = new Set(items.map(x => String(x.id)));

    // elimina los que ya no están (cerrados o reasignados)
    myGrid?.querySelectorAll('.js-ticket[data-ticket-id]').forEach(card => {
      const id = card.getAttribute('data-ticket-id');
      if (id && !should.has(String(id))) card.remove();
    });

    // agrega/actualiza los que vienen
    items.forEach(t => {
      const id = String(t.id);
      const existing = myGrid?.querySelector(`.js-ticket[data-ticket-id="${CSS.escape(id)}"]`);
      if (!existing) {
        addMyTicketCard(t);
      } else {
        existing.replaceWith(buildMyCard(t));
      }
    });

    setEmptyIfNeeded(myGrid);
  } catch(e) {
    console.warn('pollMyAssignedSnapshot error', e);
  }
}
window.pollMyAssignedSnapshot = pollMyAssignedSnapshot;


/* ===== EVENTOS (delegados) ===== */
document.addEventListener('change', function (e) {
  const select = e.target.closest('.ticket-status-select');
  if (!select) return;

  const ticketId    = select.dataset.ticketId;
  const nuevoEstado = select.value;
  const prevEstado  = select.dataset.prev || 'abierto';

  fetch('/HelpDesk_EQF/modules/ticket/update_status.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'ticket_id=' + encodeURIComponent(ticketId) + '&estado=' + encodeURIComponent(nuevoEstado)
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) {
      alert(data.msg || 'Error al actualizar el estatus.');
      select.value = prevEstado;
      return;
    }

    select.dataset.prev = nuevoEstado;
    select.className = 'ticket-status-select status-' + nuevoEstado;

    // si se cierra, quita de MIS
    if (nuevoEstado === 'cerrado') {
      removeMyCard(ticketId);
      return;
    }

    // liberado -> se va a entrantes
    if (data.released === 1){
      removeMyCard(ticketId);

      if (data.incoming_ticket) addIncomingTicketCard(data.incoming_ticket);

      bumpKpi(+1, -1);
      showTicketToast('Ticket #' + ticketId + ' liberado y enviado a entrantes.');
      return;
    }
  })
  .catch(err => {
    console.error(err);
    alert('Error interno al actualizar el estatus.');
    select.value = prevEstado;
  });
});

document.addEventListener('click', function (e) {
  const btn = e.target.closest('.btn-assign-ticket');
  if (!btn) return;

  const ticketId = btn.dataset.ticketId;
  if (!ticketId) return;

  btn.disabled = true;
  const original = btn.textContent;
  btn.textContent = 'Asignando...';

  fetch('/HelpDesk_EQF/modules/ticket/assign.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'ticket_id=' + encodeURIComponent(ticketId)
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) {
      alert(data.msg || 'No se pudo asignar.');
      btn.disabled = false;
      btn.textContent = original;
      return;
    }

    // quita de incoming
    removeIncomingCard(ticketId);

    const t = data.ticket || {};
    const id = t.id || ticketId;

    // crea en MIS
    addMyTicketCard({
      id,
      fecha_envio: t.fecha_envio || '',
      usuario: t.usuario || '',
      nombre: t.usuario || '',
      email: t.email || '',
      problema_label: (t.problema_label || t.problema_raw || ''),
      prioridad: (t.prioridad || 'media'),
      estado: 'en_proceso'
    });

    bumpKpi(-1, +1);
    showTicketToast('Ticket #' + id + ' asignado a ti.');
  })
  .catch(err => {
    console.error(err);
    alert('Error al asignar el ticket.');
  })
  .finally(() => {
    btn.disabled = false;
    btn.textContent = original;
  });
});

/* ===== SEARCH local (cards) ===== */
function wireSearch(inputId, gridId){
  const input = document.getElementById(inputId);
  const grid = document.getElementById(gridId);
  if (!input || !grid) return;

  input.addEventListener('input', () => {
    const q = (input.value || '').trim().toLowerCase();
    grid.querySelectorAll('.js-ticket').forEach(card => {
      const hay = (card.getAttribute('data-search') || '');
      card.style.display = (!q || hay.includes(q)) ? '' : 'none';
    });
  });
}

/* ===== init ===== */
document.addEventListener('DOMContentLoaded', function () {
  setEmptyIfNeeded(incomingGrid);
  setEmptyIfNeeded(myGrid);

  wireSearch('incomingSearch', 'incomingGrid');
  wireSearch('mySearch', 'myGrid');

  if ('Notification' in window && Notification.permission === 'default') {
    Notification.requestPermission();
  }

  every(7000, pollStaffUnread);
every(10000, pollNewTickets);
every(10000, cleanupIncomingTaken);
every(5000, pollMyAssignedSnapshot);
if ((window.CURRENT_USER?.area || '') !== 'TI') {
  every(5000, pollMyTiSnapshot);
}


});
</script>


<script>
/**
 * ===============================
 *  MODAL CREAR TICKET (tu lógica)
 * ===============================
 */
(() => {
  'use strict';

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('#btnOpenCreateTicket');
    if (!btn) return;
    e.preventDefault();

    const backdrop = document.getElementById('createTicketBackdrop');
    if (!backdrop) return;

    backdrop.classList.add('show');
    backdrop.setAttribute('aria-hidden', 'false');

    const emailInput = document.getElementById('ct_email');
    if (emailInput) {
      emailInput.disabled = false;
      setTimeout(() => emailInput.focus(), 30);
    }

    const ok = document.getElementById('ct_email_ok');
    if (ok) ok.classList.remove('show');

    const msg = document.getElementById('ct_msg');
    if (msg) { msg.style.display = 'none'; msg.textContent = ''; }

    const areaDestino = document.getElementById('ct_area_destino');
    const me = (window.CURRENT_USER || {});
    if (areaDestino) areaDestino.value = me.area || '';
  });

  document.addEventListener('DOMContentLoaded', () => {
    const backdrop  = document.getElementById('createTicketBackdrop');
    const btnClose  = document.getElementById('btnCloseCreateTicket');
    const btnCancel = document.getElementById('btnCancelCreateTicket');

    const form      = document.getElementById('formCreateTicket');
    const msg       = document.getElementById('ct_msg');
    const btnSubmit = document.getElementById('btnSubmitCreateTicket');

    const emailInput  = document.getElementById('ct_email');
    const datalist    = document.getElementById('usersEmailList');
    const emailOk     = document.getElementById('ct_email_ok');

    const sap         = document.getElementById('ct_sap');
    const nombre      = document.getElementById('ct_nombre');
    const area        = document.getElementById('ct_area');
    const emailLocked = document.getElementById('ct_email_locked');

    const areaDestino = document.getElementById('ct_area_destino');
    const ticketMi    = document.getElementById('ct_ticket_mi');

    const txtDesc     = document.getElementById('ct_descripcion');

    if (!backdrop || !form || !msg || !btnSubmit || !emailInput || !datalist || !areaDestino || !txtDesc) {
      console.error('Modal crear ticket: faltan elementos del DOM (IDs).');
      return;
    }

    form.setAttribute('novalidate','novalidate');

    let foundUserId = null;
    let timer = null;
    let isAutofilling = false;
    let topSuggestion = '';

    function isValidEmail(v){
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v||'').trim());
    }

    function showMsg(text){
      msg.style.display = 'block';
      msg.style.color = '#9a3412';
      msg.textContent = text || '';
    }

    function hideMsg(){
      msg.style.display = 'none';
      msg.textContent = '';
    }

    function showOk(on){
      if (!emailOk) return;
      emailOk.classList.toggle('show', !!on);
      emailOk.style.display = on ? 'inline' : 'none';
    }

    function closeModal(){
      backdrop.classList.remove('show');
      backdrop.setAttribute('aria-hidden','true');
      hideMsg();
      showOk(false);
    }

    function clearUser(){
      foundUserId = null;
      if (sap) sap.value = '';
      if (nombre) nombre.value = '';
      if (emailLocked) emailLocked.value = '';
      if (area) area.value = '';
      btnSubmit.disabled = true;
      showOk(false);
    }

    function setUser(u){
      foundUserId = u.id;
      if (sap) sap.value = u.number_sap ?? '';
      if (nombre) nombre.value = `${u.name ?? ''} ${u.last_name ?? ''}`.trim();
      if (area) area.value = u.area ?? '';
      if (emailLocked) emailLocked.value = u.email ?? '';
      btnSubmit.disabled = false;
      showOk(true);
    }

    function toDateTimeLocal(d){
      const pad = (n)=> String(n).padStart(2,'0');
      return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }

    const wrapOf = (el) => el ? (el.closest('.eqf-field') || el.closest('.eqf-grid-2') || el.parentElement) : null;
    const showEl = (el, show) => {
      const w = wrapOf(el);
      if (w) w.style.display = show ? '' : 'none';
    };

    btnClose?.addEventListener('click', closeModal);
    btnCancel?.addEventListener('click', closeModal);

    backdrop.addEventListener('click', (e) => {
      if (e.target === backdrop) closeModal();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && backdrop.classList.contains('show')) closeModal();
    });

  function applyTicketMiMode(on){
  hideMsg();
  datalist.innerHTML = '';
  topSuggestion = '';
  clearUser();
  showOk(false);

  if (on) {
    // Ticket para mí -> va a TI como ticket normal
    emailInput.value = '';
    emailInput.disabled = true;

    areaDestino.value = 'TI';

    const me = (window.CURRENT_USER || {});
    if (me.id && me.email) {
      setUser({
        id: me.id,
        email: me.email,
        number_sap: me.sap,
        name: me.name,
        last_name: me.last_name || '',
        area: me.area
      });
      hideMsg();
    } else {
      showMsg('Faltan datos del analista (CURRENT_USER) para autollenado.');
    }

    txtDesc.focus();
  } else {
    // Registro de asistencia a sucursal -> cerrado automático + encuesta
    emailInput.disabled = false;

    const me = (window.CURRENT_USER || {});
    areaDestino.value = me.area || '';

    clearUser();
    emailInput.focus();
  }
}


    if (ticketMi) {
      ticketMi.addEventListener('change', () => applyTicketMiMode(ticketMi.checked));
      ticketMi.checked = false;
      applyTicketMiMode(false);
    }

    function applyInlineSuggestion(typed, suggestion){
      if (!typed || !suggestion) return;

      const t = typed.toLowerCase();
      const s = suggestion.toLowerCase();

      if (!s.startsWith(t) || suggestion.length <= typed.length) return;
      if (document.activeElement !== emailInput) return;

      isAutofilling = true;
      emailInput.value = suggestion;
      try { emailInput.setSelectionRange(typed.length, suggestion.length); } catch(e) {}
      isAutofilling = false;
    }

    emailInput.addEventListener('input', () => {
      if (ticketMi && ticketMi.checked) return;
      if (isAutofilling) return;

      const typed = emailInput.value.trim();

      hideMsg();
      clearUser();
      showOk(false);

      clearTimeout(timer);
      timer = setTimeout(async () => {
        if (typed.length < 1) { datalist.innerHTML=''; topSuggestion=''; return; }

        try {
          const res = await fetch(`/HelpDesk_EQF/modules/dashboard/analyst/ajax/search_users.php?q=${encodeURIComponent(typed)}`, {cache:'no-store'});
          const raw = await res.text();
          let j = null; try { j = JSON.parse(raw); } catch(e) {}

          if (!res.ok || !j || !j.ok) {
            console.error('search_users error', res.status, raw);
            datalist.innerHTML = '';
            topSuggestion = '';
            return;
          }

          const items = j.items || [];
          topSuggestion = items[0]?.email || '';

          datalist.innerHTML = items.map(u =>
            `<option value="${u.email}">${u.email} — ${u.number_sap} — ${u.name} ${u.last_name}</option>`
          ).join('');

          applyInlineSuggestion(typed, topSuggestion);

        } catch(err) {
          console.error('autocomplete error', err);
        }
      }, 180);
    });

    emailInput.addEventListener('keydown', (e) => {
      if (ticketMi && ticketMi.checked) return;

      if (e.key === 'Tab' || e.key === 'ArrowRight') {
        const start = emailInput.selectionStart;
        const end   = emailInput.selectionEnd;

        if (typeof start === 'number' && typeof end === 'number' && end > start) {
          e.preventDefault();
          emailInput.setSelectionRange(end, end);
          emailInput.dispatchEvent(new Event('change'));
        }
      }
    });

    emailInput.addEventListener('change', async () => {
      if (ticketMi && ticketMi.checked) return;

      const email = emailInput.value.trim();
      if (!isValidEmail(email)) { showOk(false); return; }

      try {
        const res = await fetch(`/HelpDesk_EQF/modules/dashboard/analyst/ajax/get_user_by_email.php?email=${encodeURIComponent(email)}`, {cache:'no-store'});
        const raw = await res.text();
        let j = null; try { j = JSON.parse(raw); } catch(e) {}

        if (!res.ok || !j) {
          console.error('get_user_by_email error', res.status, raw);
          clearUser();
          showMsg('Error consultando usuario (revisa consola).');
          return;
        }

        if (!j.ok) {
          clearUser();
          showMsg(j.msg || 'Usuario no encontrado');
          return;
        }

        setUser(j.user);
        hideMsg();

      } catch(err) {
        console.error(err);
        clearUser();
        showMsg('Error al buscar usuario.');
      }
    });

    form.addEventListener('submit', async (e) => {
  e.preventDefault();
  hideMsg();

  // Validaciones primero (ANTES de poner submitting=1)
  const isMine = (ticketMi && ticketMi.checked);

  if (!foundUserId) {
    showMsg('Selecciona un usuario válido.');
    form.dataset.submitting = '0';
    return;
  }
  if (!txtDesc.value.trim()) {
    showMsg('La descripción es obligatoria.');
    txtDesc.focus();
    form.dataset.submitting = '0';
    return;
  }

  // lock anti-doble-submit
  if (form.dataset.submitting === '1') return;
  form.dataset.submitting = '1';

  btnSubmit.disabled = true;
  const prevTxt = btnSubmit.textContent;
  btnSubmit.textContent = 'Guardando...';

  const payload = new URLSearchParams();
  payload.append('user_id', String(foundUserId));
  payload.append('ticket_para_mi', isMine ? '1' : '0');
  payload.append('descripcion', txtDesc.value || '');
  payload.append('inicio', '');
  payload.append('fin', '');


  try {
    const res = await fetch('/HelpDesk_EQF/modules/ticket/create_ticket_by_analyst.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: payload.toString()
    });

    const raw = await res.text();
    let j = null; try { j = JSON.parse(raw); } catch(e) {}

    if (!res.ok || !j) {
      console.error('create_ticket_by_analyst error', res.status, raw);
      showMsg('No se pudo guardar (revisa consola).');
      return;
    }
    if (!j.ok) {
      showMsg(j.msg || 'No se pudo guardar.');
      return;
    }

    showOk(true);
    hideMsg();

    if (window.pollMyAssignedSnapshot) window.pollMyAssignedSnapshot();
    setTimeout(() => closeModal(), 350);

  } catch(err) {
    console.error(err);
    showMsg('Error al guardar ticket (revisa consola).');
  } finally {
    btnSubmit.textContent = prevTxt || 'Guardar';
    btnSubmit.disabled = false;
    form.dataset.submitting = '0';
  }
}); 

}); 

})(); 


</script>



<script>
/**
 * ===============================
 *  FEEDBACK MODAL
 * ===============================
 */
function openFeedbackIframe(token, ticketId, title){
  const modal = document.getElementById('feedback-modal');
  const frame = document.getElementById('feedbackFrame');
  const t = document.getElementById('feedbackTitle');

  if (!modal || !frame) return;

  if (t) t.textContent = title || ('Encuesta ticket #' + ticketId);

  frame.src = '/HelpDesk_EQF/modules/feedback/feedback.php?token=' + encodeURIComponent(token);

  modal.style.display = 'flex';
  modal.classList.add('show');
}

function closeFeedbackModal(){
  const modal = document.getElementById('feedback-modal');
  const frame = document.getElementById('feedbackFrame');

  if (frame) frame.src = '';
  if (modal) {
    modal.classList.remove('show');
    modal.style.display = 'none';
  }
if (window.pollMyAssignedSnapshot) window.pollMyAssignedSnapshot();
if (typeof pollNewTickets === 'function') pollNewTickets();
if (typeof pollMyTiSnapshot === 'function') pollMyTiSnapshot();


}

document.addEventListener('click', (e) => {
  const modal = document.getElementById('feedback-modal');
  if (!modal || modal.style.display === 'none') return;
  if (e.target === modal) closeFeedbackModal();
});

document.addEventListener('keydown', (e) => {
  const modal = document.getElementById('feedback-modal');
  if (!modal || modal.style.display === 'none') return;
  if (e.key === 'Escape') closeFeedbackModal();
});
</script>

<script src="/HelpDesk_EQF/assets/js/script.js?v=20251208a"></script>
<script src="/HelpDesk_EQF/assets/js/sidebar.js?v=20251208a"></script>

<script>

(() => {
  const modal = document.getElementById('announceModal');
  if (!modal) return;

  function open() { modal.classList.add('show'); }
  function close(){ modal.classList.remove('show'); }

  window.openAnnounceModal = open;
  window.closeAnnounceModal = close;

  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-open-announcement]') || e.target.closest('#btnOpenAnnouncement')) {
      e.preventDefault();
      open();
      return;
    }

    if (
      e.target.closest('[data-close-announcement]') ||
      e.target.closest('[data-cancel-announcement]') ||
      e.target.closest('#btnCloseAnnouncement') ||
      e.target.closest('#btnCancelAnnouncement')
    ) {
      e.preventDefault();
      close();
      return;
    }

    if (modal.classList.contains('show')) {
      const inner = modal.querySelector('[data-ann-modal]');
      if (inner && !inner.contains(e.target) && e.target.closest('#announceModal')) {
        close();
      }
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('show')) close();
  });
})();

</script>


<script>
document.getElementById('btnSendAnnouncement')?.addEventListener('click', async () => {

  // Agarra valores (si tus IDs cambian entre admin/analyst, esto te salva)
  const getVal = (id) => (document.getElementById(id)?.value ?? '').trim();

  const title = getVal('ann_title');
  const body  = getVal('ann_body');

  if (!title || !body) {
    alert('Faltan campos obligatorios');
    return;
  }

  const fd = new FormData();
  fd.append('title', title);
  fd.append('body', body);

  // level/area tal cual, el backend ya los normaliza si son ENUM
  fd.append('level', (document.getElementById('ann_level')?.value ?? '').trim());
  fd.append('target_area', (document.getElementById('ann_area')?.value ?? '').trim());

  fd.append('starts_at', (document.getElementById('ann_starts')?.value ?? '').trim());
  fd.append('ends_at', (document.getElementById('ann_ends')?.value ?? '').trim());

  try {
    const res = await fetch('/HelpDesk_EQF/modules/dashboard/admin/ajax/create_announcement.php', {
      method: 'POST',
      body: fd
    });

    const data = await res.json().catch(() => null);
    if (!data || !data.ok) {
      alert((data && data.msg) ? data.msg : 'Error al guardar aviso');
      return;
    }

    alert('Aviso creado correctamente ✅');

    // cerrar modal
    document.getElementById('announceModal')?.classList.remove('show');

    // limpiar campos
    ['ann_title','ann_body','ann_starts','ann_ends'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });

  } catch (e) {
    console.error(e);
    alert('Error de red al crear aviso');
  }
});
</script>

<script>
  window.EQF_CAN_DEACTIVATE_ANN = true;
</script>
<script src="/HelpDesk_EQF/assets/js/announcements_live.js?v=1"></script>
<script>
  window.HELPDESK_USER_ID = <?= (int)($_SESSION['user_id'] ?? 0) ?>;
</script>

<script src="/HelpDesk_EQF/assets/noti_push.js?v=1"></script>

</body>
</html>
