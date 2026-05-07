<?php
session_start();
require_once __DIR__ . '/../../config/connectionBD.php';

include __DIR__ . '/../../template/header.php';
include __DIR__ . '/../../template/sidebar.php';

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
$rol       = (int)($_SESSION['user_rol'] ?? 0);

/* ============================================
   HELPERS
============================================ */

// Si problema viene como string (códigos)
function problemaLabel(string $p): string {
    return match ($p) {
        'cierre_dia'   => 'Cierre del día',
        'no_legado'    => 'Sin acceso a legado/legacy',
        'no_internet'  => 'Sin internet',
        'no_checador'  => 'No funciona checador',
        'rastreo'      => 'Rastreo de checada',
        'otro'         => 'Otro',
        default        => $p,
    };
}

// Si problema viene como número (catálogo por id)
function problemaLabelId($p): string {
    $id = (int)$p;
    return match ($id) {
        1 => 'Cierre del día',
        2 => 'Sin acceso a legado/legacy',
        3 => 'Sin internet',
        4 => 'No funciona checador',
        5 => 'Rastreo de checada',
        default => (string)$p,
    };
}

function problemaHuman($p): string {
    // si es numérico => mapeo por id
    if (is_numeric($p)) return problemaLabelId($p);
    // si es string => mapeo por código
    return problemaLabel((string)$p);
}

/* ============================================
   CONSULTA SEGÚN ROL
============================================ */

$showEmailColumn = false;
$title          = 'Historial de tickets';
$subtitle       = '';

if ($rol === 4) {
    // USUARIO FINAL: todos sus tickets
    $sql = "
        SELECT
          t.id,
          t.problema,
          t.descripcion,
          t.fecha_envio,
          t.estado,
          t.fecha_resolucion
        FROM tickets t
        WHERE t.user_id = :uid
        ORDER BY t.fecha_envio DESC
    ";
    $params   = [':uid' => $userId];
    $title    = 'Historial de tus tickets';
    $subtitle = 'Aquí puedes consultar todos los tickets que has creado y su estatus actual.';
    $showEmailColumn = false;

} elseif ($rol === 3) {
    // ANALISTA: historial de tickets atendidos
    $sql = "
        SELECT
          t.id,
          u.email AS email,
          t.problema,
          t.descripcion,
          t.fecha_envio,
          t.estado,
          t.fecha_resolucion
        FROM tickets t
        LEFT JOIN users u ON u.id = t.user_id
        WHERE t.asignado_a = :uid
          AND t.estado IN ('resuelto','cerrado')
        ORDER BY t.fecha_resolucion DESC, t.fecha_envio DESC
    ";
    $params   = [':uid' => $userId];
    $title    = 'Historial de tickets atendidos';
    $subtitle = 'Tickets que han sido resueltos o cerrados y fueron asignados a ti.';
    $showEmailColumn = true;

} else {
    // SA / ADMIN: todos los tickets
    $sql = "
        SELECT
          t.id,
          u.email AS email,
          t.problema,
          t.descripcion,
          t.fecha_envio,
          t.estado,
          t.fecha_resolucion
        FROM tickets t
        LEFT JOIN users u ON u.id = t.user_id
        ORDER BY t.fecha_envio DESC
    ";
    $params   = [];
    $title    = 'Historial de todos los tickets';
    $subtitle = 'Listado general de tickets en el sistema.';
    $showEmailColumn = true;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial | HELP DESK EQF</title>

    <!-- CSS PROYECTO -->
    <link rel="stylesheet" href="/HelpDesk_EQF/assets/css/style.css?v=<?php echo time(); ?>">

    <!-- DATATABLE -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

    <!-- FORZAR MODAL REAL (overlay) -->
    <style>
      .modal{
        display:none;
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.45);
        z-index:9999;
        align-items:center;
        justify-content:center;
        padding:20px;
      }
      .modal.show{ display:flex; }

      .modal-content{
        width:100%;
        max-width:900px;
        background:#fff;
        border-radius:14px;
        box-shadow:0 20px 60px rgba(0,0,0,.25);
        overflow:hidden;
      }
      .modal-header{
        display:flex;
        align-items:center;
        justify-content:space-between;
        padding:14px 16px;
        border-bottom:1px solid #eee;
      }
      .modal-body{ padding:16px; }
      .modal-footer{
        padding:12px 16px;
        border-top:1px solid #eee;
        display:flex;
        justify-content:flex-end;
        gap:10px;
      }
      .modal-close{
        background:transparent;
        border:0;
        font-size:20px;
        cursor:pointer;
        line-height:1;
      }
    </style>
</head>

<body class="user-body">

<main class="user-main">
    <section class="user-main-inner">

        <header class="user-main-header">
            <div>
                <p class="login-brand">
                    <span>HelpDesk </span><span class="eqf-e">E</span><span class="eqf-q">Q</span><span class="eqf-f">F</span>
                </p>
                <p class="user-main-subtitle">
                    <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>
        </header>

        <section class="user-main-content">
            <div class="user-info-card">
                <h2><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h2>
                <p><?php echo htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="user-tickets-table-wrapper">
                <table id="historyTicketsTable" class="data-table display">
                    <thead>
                        <tr>
                            <th>ID</th>

                            <?php if ($showEmailColumn): ?>
                                <th>Usuario</th>
                            <?php endif; ?>

                            <th>Problema</th>
                            <th>Descripción</th>
                            <th>Fecha envío</th>
                            <th>Fecha resolución</th>
                            <th>Estatus</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tickets as $t): ?>
                        <tr>
                            <td><?php echo (int)$t['id']; ?></td>

                            <?php if ($showEmailColumn): ?>
                                <td><?php echo htmlspecialchars($t['email'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <?php endif; ?>

                            <td><?php echo htmlspecialchars(problemaHuman($t['problema'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($t['descripcion'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($t['fecha_envio'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($t['fecha_resolucion'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($t['estado'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>

                           <td class="actions-cell">

    <?php
      $emailForModal = $showEmailColumn ? ($t['email'] ?? '') : $userEmail;
    ?>

    <a href="javascript:void(0);"
       class="action-link"
       onclick="openChatViewer(<?php echo (int)$t['id']; ?>, '<?php echo htmlspecialchars($emailForModal, ENT_QUOTES, 'UTF-8'); ?>')">
       💬
    </a>

    <span class="action-sep">|</span>

    <a href="/HelpDesk_EQF/modules/ticket/report_pdf.php?id=<?= (int)$t['id'] ?>"
       target="_blank"
       rel="noopener"
       class="action-link">
       PDF
    </a>

</td>

                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </section>
    </section>
</main>

<!-- MODAL: CONSULTAR CHAT -->
<div class="modal" id="chatViewerModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3 id="chatViewerTitle">Historial de chat</h3>
      <button class="modal-close" type="button" onclick="closeModal('chatViewerModal')">×</button>
    </div>

    <div class="modal-body">
      <div id="chatViewerLocked" class="alert alert-warning" style="display:none; margin-bottom:12px;">
        Este ticket está cerrado. El chat se muestra en modo <b>solo lectura</b>.
      </div>

      <div id="chatViewerError" class="alert alert-danger" style="display:none; margin-bottom:12px;"></div>

      <div id="chatViewerBody"
           style="height: 420px; overflow:auto; background:#f7f7f7; border:1px solid #ddd; border-radius:10px; padding:12px;">
        Cargando chat...
      </div>
    </div>

    <div class="modal-footer">
     
    </div>
  </div>
</div>

<!-- LIBS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

<!-- JS PROYECTO -->
<script src="/HelpDesk_EQF/assets/js/script.js?v=<?php echo time(); ?>"></script>
<script src="/HelpDesk_EQF/assets/js/sidebar.js?v=<?php echo time(); ?>"></script>

<script>
  $(document).ready(function () {
      $('#historyTicketsTable').DataTable({
          pageLength: 10,
          order: [[4, 'desc']]
      });
  });

  // ===========================
  // CONSULTAR CHAT (SOLO LECTURA)
  // ===========================
  let chatViewerTicketId = null;

  function openChatViewer(ticketId, email){
      chatViewerTicketId = Number(ticketId) || 0;

      const titleEl = document.getElementById('chatViewerTitle');
      if (titleEl) {
        const em = (email || '').trim();
        titleEl.textContent = `Historial de chat - #${chatViewerTicketId}` + (em ? ` - ${em}` : '');
      }

      const bodyEl = document.getElementById('chatViewerBody');
      const errEl  = document.getElementById('chatViewerError');
      const lockEl = document.getElementById('chatViewerLocked');

      if (bodyEl) bodyEl.innerHTML = 'Cargando chat...';
      if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }
      if (lockEl) lockEl.style.display = 'none';

      openModal('chatViewerModal');
      fetchChatViewer(chatViewerTicketId);
  }

  async function fetchChatViewer(ticketId){
      const bodyEl = document.getElementById('chatViewerBody');
      const errEl  = document.getElementById('chatViewerError');
      const lockEl = document.getElementById('chatViewerLocked');

      try {
          const res = await fetch(`/HelpDesk_EQF/modules/ticket/get_chat_viewer.php?ticket_id=${encodeURIComponent(ticketId)}`, {
              cache: 'no-store'
          });

          const ct = (res.headers.get('content-type') || '').toLowerCase();
          const data = ct.includes('application/json') ? await res.json() : null;

          if (!data || !data.ok) {
              throw new Error((data && data.message) ? data.message : 'No se pudo cargar el chat.');
          }

          if (lockEl) lockEl.style.display = data.locked ? 'block' : 'none';
          renderChatViewerMessages(data.messages || []);

      } catch (e) {
          if (bodyEl) bodyEl.innerHTML = '';
          if (errEl) {
              errEl.textContent = e.message || 'Error al cargar el chat.';
              errEl.style.display = 'block';
          }
      }
  }

 function renderChatViewerMessages(messages){
    const bodyEl = document.getElementById('chatViewerBody');
    if (!bodyEl) return;

    if (!messages.length) {
        bodyEl.innerHTML = '<div style="opacity:.7;">No hay mensajes en este ticket.</div>';
        return;
    }

    bodyEl.innerHTML = '';

    for (const m of messages) {

        const wrap = document.createElement('div');
        wrap.style.margin = '6px 0';
        wrap.style.padding = '8px 10px';
        wrap.style.borderRadius = '8px';
        wrap.style.background = '#f9f9f9';
        wrap.style.border = '1px solid #eee';
        wrap.style.fontSize = '14px';

        const head = document.createElement('div');
        head.style.marginBottom = '4px';
        head.style.fontSize = '13px';
        head.style.color = '#555';

        // SOLO el nombre en negrita
        head.innerHTML = `<strong>${m.sender_name || 'Usuario'}</strong> • ${m.created_at || ''}`;

        const msg = document.createElement('div');
        msg.style.whiteSpace = 'pre-wrap';
        msg.style.color = '#333';
        msg.textContent = m.message || '';

        wrap.appendChild(head);
        wrap.appendChild(msg);

        // ====== ADJUNTOS ======
        if (Array.isArray(m.attachments) && m.attachments.length) {
            const att = document.createElement('div');
            att.style.marginTop = '6px';

            for (const a of m.attachments) {

                const link = document.createElement('a');
                link.href = a.url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = '📎 ' + (a.name || 'Adjunto');
                link.style.display = 'inline-block';
                link.style.marginRight = '10px';
                link.style.fontSize = '13px';
                link.style.textDecoration = 'none';
                link.style.color = '#0066cc';

                att.appendChild(link);
            }

            wrap.appendChild(att);
        }

        bodyEl.appendChild(wrap);
    }

    bodyEl.scrollTop = bodyEl.scrollHeight;
}

</script>

<?php include __DIR__ . '/../../template/footer.php'; ?>
</body>
</html>
