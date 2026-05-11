<?php

session_start();
require_once __DIR__ . '/../../../config/connectionBD.php';
include __DIR__ . '/../../../template/header.php';
include __DIR__ . '/../../../template/sidebar.php';

// helper de escape HTML
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ===========================
   ALERTAS
=========================== */
$alerts = [];
if (isset($_GET['created'])) {
    $alerts[] = [
        'type' => 'success',
        'icon' => 'capsulin_add.png',
        'text' => 'TICKET REGISTRADO EXITOSAMENTE'
    ];
}
if (isset($_GET['deleted'])) {
    $alerts[] = [
        'type' => 'danger',
        'icon' => 'capsulin_delete.png',
        'text' => 'OCURRIÓ UN ERROR AL REGISTRAR EL TICKET'
    ];
}

/* ===========================
   AUTH
=========================== */
if (!isset($_SESSION['user_id'])) {
    header('Location: /HelpDesk_EQF/auth/login.php');
    exit;
}

$pdo = Database::getConnection();

$userId    = (int)($_SESSION['user_id'] ?? 0);
$userName  = trim(($_SESSION['user_name'] ?? '') . ' ' . ($_SESSION['user_last'] ?? ''));
$userEmail = $_SESSION['user_email'] ?? '';
$userArea  = $_SESSION['user_area'] ?? '';
$userSap   = $_SESSION['number_sap'] ?? '';

$profileImg = ($userArea === 'Sucursal')
    ? '/HelpDesk_EQF/assets/img/pp/pp_sucursal.jpg'
    : '/HelpDesk_EQF/assets/img/pp/pp_corporativo.jpg';

// ============================
// AVISOS para el usuario (Sucursal / Corporativo)
// ============================
$rawArea = trim((string)($_SESSION['user_area'] ?? ''));
$audience = (stripos($rawArea, 'sucursal') !== false)
    ? 'Sucursal'
    : 'Corporativo';

$announcements = [];

try {
    $sqlAnn = "
  SELECT id, title, body, level, target_area, starts_at, ends_at, created_at
  FROM announcements
  WHERE is_active = 1
    AND (target_area = 'ALL' OR target_area = :aud)
  ORDER BY created_at DESC
  LIMIT 10
";
$stmtAnn = $pdo->prepare($sqlAnn);
$stmtAnn->execute([':aud' => $audience]);

$announcements = $stmtAnn->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (Throwable $e) {
    error_log('Announcements error: ' . $e->getMessage());
    $announcements = [];
}

function annClass(string $level): string {
    return match ($level) {
        'CRITICAL' => 'announcement--critical',
        'WARN'     => 'announcement--warn',
        default    => 'announcement--info',
    };
}

function annLabel(string $level): string {
    return match ($level) {
        'CRITICAL' => 'Crítico',
        'WARN'     => 'Aviso',
        default    => 'Info',
    };
}




/* ===========================
   CATÁLOGO DE PROBLEMAS (SA)
   AJUSTA ESTA TABLA si es necesario
=========================== */
$CATALOG_TABLE = 'catalog_problems'; // <-- cámbiala si tu tabla se llama diferente
$CATALOG_CODE_COL  = 'code';
$CATALOG_LABEL_COL = 'label';


/* ===========================
   TICKETS A MOSTRAR:
   - abiertos/en_proceso
   - cerrado + encuesta pendiente
=========================== */
$stmtOpen = $pdo->prepare("
    SELECT 
        t.id,
        t.problema,
        t.fecha_envio,
        t.estado,
        f.token AS feedback_token,
        u.name AS analyst_name,
        u.last_name AS analyst_last

    FROM tickets t
    LEFT JOIN ticket_feedback f
        ON f.ticket_id = t.id
       AND f.answered_at IS NULL

    LEFT JOIN users u
        ON u.id = t.asignado_a
        
    WHERE t.user_id = :uid
      AND (
            t.estado IN ('abierto','en_proceso', 'soporte')
         OR (t.estado = 'cerrado' AND f.id IS NOT NULL)
      )
    ORDER BY t.fecha_envio DESC
    LIMIT 10
");
$stmtOpen->execute([':uid' => $userId]);
$openTickets = $stmtOpen->fetchAll(PDO::FETCH_ASSOC);


/* ===========================
   ENCUESTAS PENDIENTES (BLOQUEO)
=========================== */
$stmtPending = $pdo->prepare("
  SELECT COUNT(*)
  FROM ticket_feedback
  WHERE user_id = ? AND answered_at IS NULL
");
$stmtPending->execute([$userId]);
$pendingCount = (int)$stmtPending->fetchColumn();


/* ===========================
   MAPA code => label DESDE BD (SA)
=========================== */
$problemMap = [];

try {
    // juntar todos los codes que aparezcan en esta lista
    $problemCodes = array_values(array_unique(array_filter(array_map(
        fn($t) => $t['problema'] ?? null,
        $openTickets
    ))));

    if (!empty($problemCodes)) {
        $in = implode(',', array_fill(0, count($problemCodes), '?'));

        $sql = "
            SELECT {$CATALOG_CODE_COL} AS code, {$CATALOG_LABEL_COL} AS label
            FROM {$CATALOG_TABLE}
            WHERE {$CATALOG_CODE_COL} IN ($in)
        ";
        $stmtProb = $pdo->prepare($sql);
        $stmtProb->execute($problemCodes);

        foreach ($stmtProb->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $problemMap[$row['code']] = $row['label'];
        }
    }
} catch (Throwable $e) {
    // Si el catálogo no existe o falla, NO rompemos user.php; solo mostraremos el code
    error_log('Catalog problems error: ' . $e->getMessage());
}

function problemLabelFromDb(string $code, array $map): string {
    return $map[$code] ?? $code;
}


/* ===========================
   AUTO-ABRIR ENCUESTA (si hay token)
=========================== */
$autoFeedbackToken = null;
$autoFeedbackTicketId = 0;
$autoFeedbackTitle = '';

foreach ($openTickets as $t) {
    if (!empty($t['feedback_token'])) {
        $autoFeedbackToken    = $t['feedback_token'];
        $autoFeedbackTicketId = (int)$t['id'];
        $autoFeedbackTitle    = problemLabelFromDb((string)$t['problema'], $problemMap);
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>User | HELP DESK EQF</title>
    <link rel="stylesheet" href="/HelpDesk_EQF/assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .user-body #eqf-alert-container {
            position: fixed;
            top: 24px;
            left: 50%;
            transform: translateX(-50%);
            width: 360px;
            max-width: calc(100% - 40px);
            padding: 16px 24px;
            border-radius: 18px;
            display: flex;
            flex-direction: row;
            align-items: center;
            background: none;
            justify-content: center;
            gap: 12px;
            height: auto;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
            z-index: 9999;
        }
        .user-body #eqf-alert-container .eqf-alert-icon img {
            width: 40px;
            height: 40px;
            object-fit: contain;
            display: block;
            margin: 0;
        }
        .user-body #eqf-alert-container .eqf-alert-text {
            font-size: 14px;
            font-weight: 700;
            text-align: center;
        }

        /* Feedback wizard */
        .feedback-options{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }
        .feedback-option-btn{
            border: 1px solid var(--eqf-border, #e5e7eb);
            background:#fff;
            padding:10px 12px;
            border-radius:14px;
            cursor:pointer;
            font-weight:700;
        }
        .feedback-option-btn.is-active{
            outline: 2px solid var(--eqf-combined, #6e1c5c);
        }
        .feedback-badge{
            display:inline-block;
            margin-left:8px;
            padding:2px 8px;
            border-radius:999px;
            font-size:11px;
            font-weight:800;
            background: rgba(110, 28, 92, 0.12);
        }
    </style>
</head>

<body class="user-body">

<?php if (!empty($alerts)): ?>
    <?php $alert = $alerts[0]; ?>
    <div id="eqf-alert-container">
        <div class="eqf-alert eqf-alert-<?php echo htmlspecialchars($alert['type']); ?>">
            <img class="eqf-alert-icon"
                 src="/HelpDesk_EQF/assets/img/icons/<?php echo htmlspecialchars($alert['icon']); ?>"
                 alt="alert icon">
            <div class="eqf-alert-text"><?php echo htmlspecialchars($alert['text']); ?></div>
        </div>
    </div>
<?php endif; ?>

<main class="user-main">
    <section class="user-main-inner">

<header class="user-topbar" id="user-dashboard-topbar">
  <div class="user-topbar-left">
    <p class="login-brand">
      <span>HelpDesk </span><span class="eqf-e">E</span><span class="eqf-q">Q</span><span class="eqf-f">F</span>
    </p>
    <p class="user-main-subtitle">
      Bienvenid@, <?php echo h($userName); ?>.
    </p>
  </div>

  <div class="user-topbar-right">
    <button id="btnCreateTicket" type="button"
      class="btn-primary"
      onclick="<?php echo ($pendingCount > 0) ? 'return false;' : 'openTicketModal()'; ?>"
      <?php echo ($pendingCount > 0) ? 'disabled style="opacity:.6; cursor:not-allowed;"' : ''; ?>>
      Crear ticket
    </button>
  </div>
</header>

        <section class="user-main-content">
  <div class="user-info-card">
    <h2>Resumen</h2>
    <p>
      Desde aquí puedes crear tickets, consultar el historial de los que has levantado y acceder a documentos importantes para la operación de tu sucursal o área.
    </p>

    <div class="user-announcements" id="annWrap">
  <div class="user-announcements__head">
    <h3 class="user-announcements__title">
      Avisos
      <span class="user-announcements__badge" id="annBadge">
        <?php echo count($announcements ?? []); ?>
      </span>
    </h3>

       <?php if (isset($audience)): ?>
      <p class="user-announcements__hint">
        Mostrando: <?php echo h($audience); ?>
      </p>
    <?php endif; ?>
  </div>
<div class="user-announcements__list" id="annList">
    <?php if (!empty($announcements)): ?>
      <?php foreach ($announcements as $a): ?>
        <?php $lvl = strtoupper((string)($a['level'] ?? 'INFO')); ?>
        <div class="announcement <?php echo annClass($lvl); ?>">
          <div class="announcement__top">
            <div>
              <p class="announcement__h"><?php echo h($a['title'] ?? ''); ?></p>
              <p class="announcement__meta">
                <?php echo h('Dirigido a: ' . ($a['target_area'] ?? '')); ?>
                
                <?php if (!empty($a['starts_at'])): ?>
                  <br><?php echo h('Hora de inicio: ' . date('d/m/Y H:i', strtotime($a['starts_at']))); ?>
                <?php endif; ?>
                <?php if (!empty($a['ends_at'])): ?>
                  <br><?php echo h('Hora estimada fin: ' . date('d/m/Y H:i', strtotime($a['ends_at']))); ?>
                <?php endif; ?>
              </p>
            </div>
      
                <span class="announcement__pill">
              <?php echo annLabel($lvl); ?>
            </span>
          </div>

              <div class="announcement__body">
            <?php echo nl2br(h($a['body'] ?? '')); ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p style="margin:0; color:#6b7280;">No hay avisos por el momento.</p>
    <?php endif; ?>
  </div>
</div>
</section>




            <?php if ($pendingCount > 0): ?>
    <div class="user-info-card" id="pendingFeedbackCard">

                    <h2>Encuestas pendientes</h2>
                    <p>
Tienes <strong id="pendingFeedbackCount"><?php echo $pendingCount; ?></strong> encuesta(s) pendiente(s).
Debes responderlas antes de crear un nuevo ticket.

                    </p>
                </div>
            <?php endif; ?>



            <div id="tickets-section" class="user-tickets-placeholder">
                <h3>Mis tickets</h3>

                <?php if (empty($openTickets)): ?>
                    <p>No tienes tickets activos ni encuestas pendientes por el momento.</p>
                <?php else: ?>
                    <ul class="user-tickets-list" id="userTicketsList">
                        <?php foreach ($openTickets as $t): ?>
                            <?php
                                $ticketId = (int)$t['id'];
                                $problemCode = (string)($t['problema'] ?? '');
                                $problemLabel = problemLabelFromDb($problemCode, $problemMap);
                                $analystFull = trim(($t['analyst_name'] ?? '') . ' ' . ($t['analyst_last'] ?? ''));
                                $analystText = $analystFull !== '' ? $analystFull : 'Sin asignar';
                            ?>
                            <li class="user-ticket-item">
                                <div class="user-ticket-info">
                                    <div>
                                        <strong>#<?php echo $ticketId; ?></strong>
                                        — <?php echo htmlspecialchars($problemLabel, ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if (!empty($t['feedback_token'])): ?>
                                            <span class="feedback-badge">encuesta pendiente</span>
                                        <?php endif; ?>
                                    </div>

                                    <small>
                                        <?php echo htmlspecialchars((string)$t['fecha_envio'], ENT_QUOTES, 'UTF-8'); ?>
                                        · <?php echo htmlspecialchars((string)$t['estado'], ENT_QUOTES, 'UTF-8'); ?>
                                        · <strong>Atiende:</strong> <?php echo htmlspecialchars($analystText, ENT_QUOTES, 'UTF-8'); ?>
                                      </small>
                                </div>

                                <div class="user-ticket-actions">
                                    <?php if (!empty($t['feedback_token'])): ?>
                                        <button type="button"
                                                class="btn-main-combined"
                                                style="padding:6px 14px; font-size:0.75rem;"
                                                onclick="openFeedbackIframe(
                                                    '<?php echo htmlspecialchars((string)$t['feedback_token'], ENT_QUOTES, 'UTF-8'); ?>',
                                                    <?php echo $ticketId; ?>,
                                                    '<?php echo htmlspecialchars($problemLabel, ENT_QUOTES, 'UTF-8'); ?>'
                                                )">
                                            Encuesta pendiente
                                        </button>
                                    <?php else: ?>
                                        <button type="button"
  class="btn-main-combined"
  style="padding:6px 14px; font-size:0.75rem;"
  onclick="openTicketChat(<?php echo $ticketId; ?>,'<?php echo htmlspecialchars($problemLabel,ENT_QUOTES,'UTF-8'); ?>')"
  data-chat-btn
  data-ticket-id="<?php echo $ticketId; ?>"
>
  Ver chat <span class="chat-badge" style="display:none;"></span>
</button>

                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    </section>
</main>

<!-- MODAL CREAR TICKET -->
<div class="user-modal-backdrop" id="ticketModal">
    <div class="user-modal">
        <header class="user-modal-header">
            <h2>Crear ticket</h2>
            <button type="button" class="user-modal-close" onclick="closeTicketModal()">×</button>
        </header>

        <p class="user-modal-description">
            Completa la información para registrar tu incidencia en el HelpDesk EQF.
        </p>

        <form method="POST"
              action="/HelpDesk_EQF/modules/ticket/create.php"
              enctype="multipart/form-data"
              class="user-modal-form"
              id="ticketForm">

            <input type="hidden" name="user_id" value="<?php echo htmlspecialchars((string)$userId, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="area" value="<?php echo htmlspecialchars($userArea, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-group form-row">
                <div class="field">
                    <label># SAP</label>
                    <input type="text" id="sapDisplay" value="<?php echo htmlspecialchars($userSap, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
                <div class="field">
                    <label>Nombre</label>
                    <input type="text" id="nombreDisplay" value="<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
                <div class="form-group">
                    <label>Correo</label>
                    <input type="text" id="emailDisplay" value="<?php echo htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
            </div>

            <input type="hidden" name="sap" id="sapValue" value="<?php echo htmlspecialchars($userSap, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="nombre" id="nombreValue" value="<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-group checkbox-container">
  <input type="checkbox" id="noJefe" name="no_jefe" value="1">
  <label for="noJefe">No soy jefe de sucursal</label>
</div>

<div class="form-group" id="nombreJefeWrap" style="display:none;">
  <label>Nombre</label>
  <input
    type="text"
    id="nombreJefeInput"
    name="nombre_jefe"
    placeholder="Escribe el nombre completo"
    maxlength="120"
  >
  <small style="opacity:.75; display:block; margin-top:6px;">
    Este ticket se registra con tu nombre, con remitente de tu sucursal.
  </small>
</div>


            <div class="form-group">
                <label>Área de soporte</label>
                <select id="areaSoporte" name="area_soporte" required>
                    <option value="">Selecciona un área</option>
                    <option value="TI">TI</option>
                    <option value="SAP">SAP</option>
                    <!-- <option value="MKT">MKT</option> -->
                </select>
            </div>

            <div class="form-group">
                <label>Problema</label>
                <select name="problema" id="problemaSelect" required>
                    <option value="">Selecciona primero un área</option>
                </select>
            </div>

            <input type="hidden" name="prioridad" id="prioridadValue" value="media">

            <div class="form-group form-group-full">
                <label>Descripción</label>
<textarea id="ticketDesc" name="descripcion" rows="3" placeholder="Describe el problema" required
          onkeydown="ticketFormEnterSubmit(event)"></textarea>
            </div>

            <div class="form-group form-group-full" id="adjuntoContainer">
                <label>Adjuntar archivos</label>
                <input type="file"
                       name="adjuntos[]"
                       multiple
                       accept=".pdf,.jpg,.jpeg,.webp,.docx,.png,.xls,.xlsx,.csv">
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeTicketModal()">Cancelar</button>
                <button type="submit"
        class="btn-primary"
        id="submitTicketBtn">
    Enviar ticket
</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL CHAT DE TICKET -->
<div class="modal-backdrop" id="ticket-chat-modal">
    <div class="modal-card ticket-chat-modal-card">
        <div class="modal-header">
            <h3 id="ticketChatTitle">Chat del ticket</h3>
            <button type="button" class="modal-close" onclick="closeTicketChat()">✕</button>
        </div>

        <div class="ticket-chat-body" id="ticketChatBody"></div>

        <form class="ticket-chat-form" onsubmit="sendTicketMessage(event)">
<textarea id="ticketChatInput" rows="2" placeholder="Escribe tu mensaje..." style="width:100%"
  onkeydown="ticketChatEnterSend(event)"></textarea>

            <div class="ticket-chat-input-row">
                <input type="file"
                       id="ticketChatFile"
                       name="adjunto"
                       class="ticket-chat-file"
                       accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv"
                       style="width:100%">

                <button type="submit" class="btn-primary" style="min-width: 60px;">Enviar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL FEEDBACK (usa feedback.php) -->
<div class="modal-backdrop" id="feedback-modal">
  <div class="modal-card" style="max-width:780px; width:min(92vw,780px);">
    <div class="modal-header">
      <h3 id="feedbackTitle">Encuesta de satisfacción</h3>
      <button type="button" class="modal-close" onclick="closeFeedbackModal()">✕</button>
    </div>

    <div class="modal-body" style="padding:0; height:520px;">
      <iframe
        id="feedbackFrame"
        src="about:blank"
        style="width:100%; height:520px; border:0; border-radius:0 0 18px 18px; background:#fff;"
        loading="lazy"
      ></iframe>
    </div>
  </div>
</div>


<script>
function openTicketModal() {
    document.getElementById('ticketModal').classList.add('is-visible');
}
function closeTicketModal() {
    document.getElementById('ticketModal').classList.remove('is-visible');
}

function ticketFormEnterSubmit(e){
  // Enter envía, Shift+Enter hace salto de línea
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();

    const form = document.getElementById('ticketForm');
    if (!form) return;

    // valida requireds del form (selects/textarea/etc)
    if (!form.checkValidity()) {
      // muestra los mensajes nativos del navegador
      form.reportValidity();
      return;
    }

    // dispara submit como si presionaras el botón
    form.requestSubmit(); // respeta validaciones y handler submit si tienes
  }
}

</script>

<script>
const CURRENT_USER_ID = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
</script>

<script>
/* ===========================
   CHAT
=========================== */
let currentTicketId = null;
let lastMessageId   = 0;
let chatPollTimer   = null;
let clipboardFiles  = [];

function openTicketChat(ticketId, tituloExtra) {
    currentTicketId = ticketId;
    lastMessageId = 0;
clipboardFiles = [];
renderClipboardPreview();

    const titleEl = document.getElementById('ticketChatTitle');
    if (titleEl) {
        titleEl.textContent = 'Chat del ticket #' + ticketId + (tituloExtra ? ' – ' + tituloExtra : '');
    }

    const bodyEl = document.getElementById('ticketChatBody');
    if (bodyEl) bodyEl.innerHTML = '';

    const modal = document.getElementById('ticket-chat-modal');
    if (typeof openModal === 'function') openModal('ticket-chat-modal');
    else if (modal) modal.classList.add('show');

    fetchMessages();

    if (chatPollTimer) clearInterval(chatPollTimer);
    chatPollTimer = setInterval(() => fetchMessages(), 5000);
fetch('/HelpDesk_EQF/modules/ticket/mark_read.php', {
  method:'POST',
  headers:{'Content-Type':'application/x-www-form-urlencoded'},
  body:'ticket_id=' + encodeURIComponent(ticketId)
}).then(()=> {
  // quita badge local inmediato (sin esperar al polling)
  const btn = document.querySelector(`[data-chat-btn][data-ticket-id="${ticketId}"] .chat-badge`);
  if (btn){ btn.style.display='none'; btn.textContent=''; }
}).catch(()=>{});

}

function closeTicketChat() {
    const modal = document.getElementById('ticket-chat-modal');
    if (typeof closeModal === 'function') closeModal('ticket-chat-modal');
    else if (modal) modal.classList.remove('show');

    if (chatPollTimer) { clearInterval(chatPollTimer); chatPollTimer = null; }
    currentTicketId = null;
    clipboardFiles = [];
renderClipboardPreview();

    if (typeof pollUserSnapshot === 'function') pollUserSnapshot();

}

function appendChatMessage(msg) {
    const bodyEl = document.getElementById('ticketChatBody');
    if (!bodyEl) return;

    const div = document.createElement('div');
    div.className = 'ticket-chat-message';

    const senderId = parseInt(msg.sender_id, 10);
    const isMine = (senderId === CURRENT_USER_ID);
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
            imgLink.href = url;
            imgLink.target = '_blank';
            imgLink.rel = 'noopener';

            const img = document.createElement('img');
            img.src = url;
            img.alt = name;
            img.className = 'ticket-chat-image';

            imgLink.appendChild(img);
            fileWrapper.appendChild(imgLink);
        } else {
            const link = document.createElement('a');
            link.href = url;
            link.target = '_blank';
            link.rel = 'noopener';
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

function fetchMessages() {
    if (!currentTicketId) return;

    const url = '/HelpDesk_EQF/modules/ticket/get_messages.php'
        + '?ticket_id=' + encodeURIComponent(currentTicketId)
        + '&last_id=' + encodeURIComponent(lastMessageId);

    fetch(url)
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

    const input = document.getElementById('ticketChatInput');
    const fileInput = document.getElementById('ticketChatFile');
    if (!input) return;

    const texto = input.value.trim();
    const file = fileInput && fileInput.files.length > 0 ? fileInput.files[0] : null;
if (!texto && !file && clipboardFiles.length === 0) return;

    input.disabled = true;
    if (fileInput) fileInput.disabled = true;

    const formData = new FormData();
    formData.append('ticket_id', currentTicketId);
    formData.append('mensaje', texto);
    if (file) formData.append('adjunto', file);
    clipboardFiles.forEach(f => formData.append('clipboard_files[]', f));


    fetch('/HelpDesk_EQF/modules/ticket/send_messages.php', { method: 'POST', body: formData })
        .then(resp => {
            input.disabled = false;
            if (fileInput) { fileInput.disabled = false; fileInput.value = ''; }

            if (!resp.ok) { alert('No se pudo enviar el mensaje'); return; }

            input.value = '';
            clipboardFiles = [];
renderClipboardPreview();

            input.focus();
            fetchMessages();
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
function openFeedbackIframe(token, ticketId, title){
  const frame = document.getElementById('feedbackFrame');
  const t = document.getElementById('feedbackTitle');

  if (t){
    t.textContent = 'Encuesta – Ticket #' + ticketId + (title ? ' · ' + title : '');
  }

  // carga el feedback.php real
  if (frame){
    frame.src = '/HelpDesk_EQF/modules/feedback/feedback.php?token=' + encodeURIComponent(token);
  }

  const modal = document.getElementById('feedback-modal');
  if (typeof openModal === 'function') openModal('feedback-modal');
  else if (modal) modal.classList.add('show');
}

function closeFeedbackModal(){
  const modal = document.getElementById('feedback-modal');
  if (typeof closeModal === 'function') closeModal('feedback-modal');
  else if (modal) modal.classList.remove('show');

  const frame = document.getElementById('feedbackFrame');
  if (frame) frame.src = 'about:blank';

  //  refresca inmediatamente la UI (quita encuesta pendiente)
  if (typeof pollUserSnapshot === 'function') pollUserSnapshot();
}


</script>

<?php include __DIR__ . '/../../../template/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    function showUserToast(msg) {
        const toast = document.createElement('div');
        toast.className = 'eqf-toast-ticket';
        toast.textContent = msg;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('hide');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }
});
</script>

<?php if (!empty($autoFeedbackToken)): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  openFeedbackIframe(
    "<?php echo htmlspecialchars((string)$autoFeedbackToken, ENT_QUOTES, 'UTF-8'); ?>",
    <?php echo (int)$autoFeedbackTicketId; ?>,
    "<?php echo htmlspecialchars((string)$autoFeedbackTitle, ENT_QUOTES, 'UTF-8'); ?>"
  );
});
</script>
<?php endif; ?>



<script>
/* ===========================
   LIVE REFRESH - USER
=========================== */

function escapeHtml(str){
  return String(str ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}

function escapeAttr(str){
  return String(str ?? '')
    .replaceAll("\\", "\\\\")
    .replaceAll("'", "\\'");
}



function buildTicketLi(t){
  const id = t.id;
  const problema = t.problema_label || t.problema_raw || '';
  const estado = t.estado || '';
  const fecha = t.fecha_envio || '';
  const token = t.feedback_token;
const attends = (t.analyst_full || '').trim() || 'Sin asignar';

  const badge = token ? `<span class="feedback-badge">encuesta pendiente</span>` : '';

  const actionBtn = token
  ? `<button type="button"
            class="btn-main-combined"
            style="padding:6px 14px; font-size:0.75rem;"
            onclick="openFeedbackIframe('${escapeAttr(token)}', ${id}, '${escapeAttr(problema)}')">
        Encuesta pendiente
     </button>`
  : `<button type="button"
            class="btn-main-combined"
            style="padding:6px 14px; font-size:0.75rem;"
            onclick="openTicketChat(${id}, '${escapeAttr(problema)}')">
        Ver chat
     </button>`;


  return `
    <li class="user-ticket-item" data-ticket-id="${id}">
      <div class="user-ticket-info">
        <div>
          <strong>#${id}</strong> — ${escapeHtml(problema)} ${badge}
        </div>
        <small>
          ${escapeHtml(fecha)} · <span class="ticket-estado">${escapeHtml(estado)}</span>
          · <strong>Atiende:</strong> ${escapeHtml(attends)}

          </small>
      </div>
      <div class="user-ticket-actions">
        ${actionBtn}
      </div>
    </li>
  `;
}

//seras atendido por:
//  memoria: para no repetir toast en cada polling
const assignToastSeen = new Map(JSON.parse(sessionStorage.getItem('assignToastSeen') || '[]'));
 // ticket_id => analyst_full ya mostrado


 function persistAssignSeen(){
  sessionStorage.setItem('assignToastSeen', JSON.stringify([...assignToastSeen.entries()]));
}

function showAssignToast(text){
  const toast = document.createElement('div');
  toast.textContent = text;

  // estilo inline (no dependemos de CSS)
  Object.assign(toast.style, {
    position: 'fixed',
    top: '50%',
    left: '50%',
    transform: 'translate(-50%, -50%)',
    padding: '14px 18px',
    borderRadius: '16px',
    background: 'var(--eqf-green, #6e1c5c)',
    color: '#fff',
    fontWeight: '900',
    boxShadow: '0 10px 25px rgba(0,0,0,.25)',
    zIndex: 99999,
    opacity: '0',
    transition: 'opacity .25s ease',
    maxWidth: 'min(92vw, 520px)',
    textAlign: 'center'
  });

  document.body.appendChild(toast);
  requestAnimationFrame(() => toast.style.opacity = '1');

  setTimeout(() => {
    toast.style.opacity = '0';
    setTimeout(() => toast.remove(), 250);
  }, 5000);
}



function applyUserSnapshot(payload){
  if (!payload || !payload.ok) return;

  // 0) tickets (DEBE IR ARRIBA)
  const tickets = Array.isArray(payload.tickets) ? payload.tickets : [];

  // 1) Bloqueo "Crear ticket"
  const pending = parseInt(payload.pending_feedback_count || 0, 10);
  const btn = document.getElementById('btnCreateTicket');
  const card = document.getElementById('pendingFeedbackCard');
  const countEl = document.getElementById('pendingFeedbackCount');

  if (countEl) countEl.textContent = String(pending);

  if (pending > 0){
    if (btn){
      btn.disabled = true;
      btn.style.opacity = '.6';
      btn.style.cursor = 'not-allowed';
      btn.onclick = () => false;
    }
    if (!card){
      const btnWrap = document.querySelector('.button');
      if (btnWrap){
        const div = document.createElement('div');
        div.className = 'user-info-card';
        div.id = 'pendingFeedbackCard';
        div.innerHTML = `
          <h2>Encuestas pendientes</h2>
          <p>
            Tienes <strong id="pendingFeedbackCount">${pending}</strong> encuesta(s) pendiente(s).
            Debes responderlas antes de crear un nuevo ticket.
          </p>
        `;
        btnWrap.parentNode.insertBefore(div, btnWrap);
      }
    }
  } else {
    if (btn){
      btn.disabled = false;
      btn.style.opacity = '';
      btn.style.cursor = '';
      btn.onclick = () => openTicketModal();
    }
    if (card) card.remove();
  }

  let changed = false;

  // Toast cuando se asigna analista (ya con tickets definido)
  tickets.forEach(t => {
    const id = String(t.id);
    const analyst = String(t.analyst_full || '').trim();
    const prev = assignToastSeen.get(id) || '';

    if (!prev && analyst) {
      showAssignToast(`Te atenderá: ${analyst}`);
      assignToastSeen.set(id, analyst);
      changed = true;
    } else if (analyst && prev !== analyst){
      assignToastSeen.set(id, analyst);
      changed = true;
    } else if (!analyst && prev) {
      assignToastSeen.delete(id);
      changed = true;
    }
  });

  if (changed) persistAssignSeen();

  // 2) Refrescar lista "Tus tickets"
  const list = document.querySelector('.user-tickets-list');
  const placeholder = document.getElementById('tickets-section');

  let ul = list;
  if (!ul && tickets.length){
    const p = placeholder ? placeholder.querySelector('p') : null;
    if (p) p.remove();

    ul = document.createElement('ul');
    ul.className = 'user-tickets-list';
    if (placeholder) placeholder.appendChild(ul);
  }

  if (!tickets.length){
    if (ul) ul.remove();
    if (placeholder && !placeholder.querySelector('p')){
      const p = document.createElement('p');
      p.textContent = 'No tienes tickets activos ni encuestas pendientes por el momento.';
      placeholder.appendChild(p);
    }
    return;
  }

  if (!ul) return;

  ul.innerHTML = tickets.map(buildTicketLi).join('');
}


function pollUserSnapshot(){
  fetch('/HelpDesk_EQF/modules/ticket/user_snapshot.php?_=' + Date.now(), {
    cache: 'no-store',
    headers: { 'Accept': 'application/json' }
  })
    .then(r => r.json())
    .then(applyUserSnapshot)
    .catch(()=>{});
}


document.addEventListener('DOMContentLoaded', () => {
  pollUserSnapshot();
  setInterval(pollUserSnapshot, 5000);
});


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
      badge.textContent = count > 9 ? '9+' : String(count);
    } else {
      badge.style.display = 'none';
      badge.textContent = '';
    }
  });
}

function pollUserUnread(){
  fetch('/HelpDesk_EQF/modules/ticket/user_unread.php', {cache:'no-store'})
    .then(r=>r.json())
    .then(data=>{
      if (!data.ok) return;
      applyUnreadBadges(data.items);
    })
    .catch(()=>{});
}
setInterval(pollUserUnread, 7000);
pollUserUnread();


</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const problema = document.getElementById('problemaSelect');
  const prio = document.getElementById('prioridadValue');

  function syncPriority(){
    const val = (problema?.value || '');
    prio.value = (val === 'otro') ? 'media' : (val ? 'alta' : 'media');
  }

  if (problema) {
    problema.addEventListener('change', syncPriority);
    syncPriority();
  }
});
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const url = new URL(window.location.href);
  if (url.searchParams.has('created') || url.searchParams.has('deleted')) {
    url.searchParams.delete('created');
    url.searchParams.delete('deleted');
    window.history.replaceState({}, document.title, url.pathname + url.search);
  }
});
</script>


<script src="/HelpDesk_EQF/assets/js/noti_push.js?v=1"></script>


<script>
document.addEventListener('DOMContentLoaded', () => {
  const cb   = document.getElementById('noJefe');
  const wrap = document.getElementById('nombreJefeWrap');
  const inp  = document.getElementById('nombreJefeInput');

  function toggleNombreJefe(){
    const on = !!cb?.checked;

    if (wrap) wrap.style.display = on ? '' : 'none';
    if (inp) {
      inp.required = on;        
      if (!on) inp.value = '';  
    }
  }

  if (cb) cb.addEventListener('change', toggleNombreJefe);
  toggleNombreJefe();
});
</script>
<script src="/HelpDesk_EQF/assets/js/announcements_live.js?v=1"></script>
<script>
  window.HELPDESK_USER_ID = <?= (int)($_SESSION['user_id'] ?? 0) ?>;
</script>

<script src="/HelpDesk_EQF/assets/noti_push.js?v=1"></script>
</body>
</html>
