<?php
session_start();
require_once __DIR__ . '/../../../config/connectionBD.php';

$rol = (int)($_SESSION['user_rol'] ?? ($_SESSION['rol'] ?? 0));
if (!isset($_SESSION['user_id']) || $rol !== 3) {
  header('Location: /HelpDesk_EQF/auth/login.php');
  exit;
}

$pdo = Database::getConnection();
$analystId = (int)($_SESSION['user_id'] ?? 0);

function prioClass(string $label): string {
  $x = mb_strtolower(trim($label));
  if (in_array($x, ['baja','low'], true)) return 'task-priority-low';
  if (in_array($x, ['media','medium'], true)) return 'task-priority-med';
  if (in_array($x, ['alta','high'], true)) return 'task-priority-high';
  return 'task-priority-med';
}
function statusPillClass(string $s): string {
  $s = strtoupper(trim($s));
  return match($s){
    'ASIGNADA'   => 'task-status-pill task-status-assigned',
    'EN_PROCESO' => 'task-status-pill task-status-progress',
    'FINALIZADA' => 'task-status-pill task-status-done',
    default      => 'task-status-pill task-status-assigned',
  };
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function statusLabel(string $s): string {
  $s = strtoupper(trim($s));
  return match($s){
    'ASIGNADA'   => 'Asignada',
    'EN_PROCESO' => 'En proceso',
    'FINALIZADA' => 'Finalizada',
    default      => $s ?: '—',
  };
}
function fmtSeconds(?int $sec): string {
  if (!$sec || $sec < 0) return '—';
  $h = intdiv($sec, 3600);
  $m = intdiv($sec % 3600, 60);
  $s = $sec % 60;
  return sprintf('%02d:%02d:%02d', $h, $m, $s);
}


// PENDIENTES (cards): ASIGNADA y EN_PROCESO
$stmtT = $pdo->prepare("
  SELECT t.*,
         cp.label AS priority_name,
         CONCAT(ad.name,' ',ad.last_name) AS admin_name
  FROM tasks t
  JOIN catalog_priorities cp ON cp.id = t.priority_id
  JOIN users ad ON ad.id = t.created_by_admin_id
  WHERE t.assigned_to_user_id = ?
    AND t.status IN ('ASIGNADA','EN_PROCESO')
  ORDER BY t.created_at DESC
");
$stmtT->execute([$analystId]);
$tasks = $stmtT->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stmtH = $pdo->prepare("
  SELECT
    t.id AS task_id,
    t.title,
    t.due_at,
    t.finished_at,
    TIMESTAMPDIFF(SECOND, t.acknowledged_at, t.finished_at) AS elapsed_sec
  FROM tasks t
  WHERE t.assigned_to_user_id = ?
    AND t.status = 'FINALIZADA'
  ORDER BY t.finished_at DESC
");
$stmtH->execute([$analystId]);
$history = $stmtH->fetchAll(PDO::FETCH_ASSOC) ?: [];


include __DIR__ . '/../../../template/header.php';
include __DIR__ . '/../../../template/sidebar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mis tareas| HelpDesk EQF</title>
  <link rel="stylesheet" href="/HelpDesk_EQF/assets/css/style.css?v=<?php echo time(); ?>">
</head>

<body class="user-body">
<main class="user-main">
  <section class="user-main-inner">

    <header class="user-main-header">
      <div>
        <p class="login-brand">
          <span>HelpDesk </span><span class="eqf-e">E</span><span class="eqf-q">Q</span><span class="eqf-f">F</span>
        </p>
        <p class="user-main-subtitle">Mis tareas</p>
      </div>
    </header>

    <section class="user-main-content">

      <?php if (empty($tasks)): ?>
        <div class="user-info-card">
          <h2>Mis tareas</h2>
          <p style="margin:0; opacity:.85;">No tienes tareas asignadas.</p>
        </div>
      <?php else: ?>
        <div class="tickets-grid">
          <?php foreach ($tasks as $t): ?>
            <article class="ticket-card">
              <div class="ticket-card__top">
                <div class="ticket-id"><?php echo h($t['title']); ?></div>
                <div class="ticket-date">Entrega: <?php echo h($t['due_at']); ?></div>
              </div>

              <div class="ticket-card__body">
                <div class="ticket-row">
                  <div class="ticket-label">Creada por</div>
                  <div class="ticket-value"><?php echo h($t['admin_name']); ?></div>
                </div>

                  <div class="ticket-row">
  <div class="ticket-label">Prioridad</div>
  <div class="ticket-value" style="text-align:left;">
   <span class="task-priority-pill <?php echo prioClass($t['priority_name'] ?? ''); ?>">
  <?php echo h($t['priority_name'] ?? '—'); ?>
</span>


  </div>
</div>



                <div class="ticket-row">
                  <div class="ticket-label">Estado</div>
<?php $st = $t['status'] ?? ''; ?>

<span class="<?php echo statusPillClass($st); ?>">
  <?php echo h(statusLabel($st)); ?>
</span>

                </div>

                <div class="ticket-desc"><?php echo h($t['description']); ?></div>
              </div>
        <?php $st = $t['status'] ?? ''; ?>

<div class="ticket-card__actions task-actions-analyst">
  <div class="task-actions-analyst__row">

    <!-- IZQUIERDA: evidencias SOLO si EN_PROCESO -->
    <?php if ($st === 'EN_PROCESO'): ?>
      <form method="POST"
            action="/HelpDesk_EQF/modules/dashboard/tasks/upload_evidence.php"
            enctype="multipart/form-data"
            class="task-actions-analyst__left"
            style="margin:0;">
        <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">

        <input id="ev_<?php echo (int)$t['id']; ?>"
               type="file"
               name="evidence_files[]"
               multiple
               required
               style="display:none"
               onchange="this.form.submit()">

        <button type="button"
                class="task-link-combined"
                onclick="document.getElementById('ev_<?php echo (int)$t['id']; ?>').click();">
          Adjuntar Evidencia
        </button>
      </form>
    <?php else: ?>
      <div class="task-actions-analyst__left"></div>
    <?php endif; ?>

    <button type="button"
        class="panel-link task-actions-analyst__mid"
        data-open-task-detail
        data-task-id="<?php echo (int)$t['id']; ?>">
  Ver
</button>


    <!-- DERECHA: Enterado / Finalizar -->
    <div class="task-actions-analyst__right">
      <?php if ($st === 'ASIGNADA'): ?>
        <form method="POST" action="/HelpDesk_EQF/modules/dashboard/tasks/ack.php" style="margin:0;">
          <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">
          <button class="chip-btn" type="submit">Enterado</button>
        </form>

      <?php elseif ($st === 'EN_PROCESO'): ?>
        <form method="POST" action="/HelpDesk_EQF/modules/dashboard/tasks/finish.php" style="margin:0;">
          <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">
          <button class="chip-btn-finish" type="submit">Finalizar</button>
        </form>
      <?php endif; ?>
    </div>

  </div>
</div>



            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </section>
  </section>
<?php if (!empty($history)): ?>
  <div class="user-info-card" style="margin-top:16px;">
    <h2 style="margin:0 0 12px 0;">Historial de tareas finalizadas</h2>

    <div style="overflow:auto;">
      <table id="tasksHistoryTable" class="display" style="width:100%;">
        <thead>
          <tr>
            <th>Tarea</th>
            <th>Fecha de entrega</th>
            <?php if ($rol === 3): ?><th>Analista</th><?php endif; ?>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $t): ?>
            <tr>
              <td><?php echo h($t['title']); ?></td>
              <td><?php echo h($t['due_at']); ?></td>
              <?php if ($rol === 3): ?><td><?php echo h($t['analyst_name'] ?? '—'); ?></td><?php endif; ?>
              <td>
                <a class="panel-link" href="/HelpDesk_EQF/modules/dashboard/tasks/view.php?id=<?php echo (int)$t['task_id']; ?>">Ver</a>
&nbsp;|&nbsp;
<a class="panel-link" href="/HelpDesk_EQF/modules/dashboard/tasks/report_pdf.php?id=<?php echo (int)$t['task_id']; ?>" target="_blank" rel="noopener">PDF</a>
</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  

  <!-- DataTables (CDN rápido) -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
  <script>
    $(function(){
      $('#tasksHistoryTable').DataTable({
        pageLength: 10,
        order: [[1,'desc']],
        language: {
          search: "Buscar:",
          lengthMenu: "Mostrar _MENU_",
          info: "Mostrando _START_ a _END_ de _TOTAL_",
          paginate: { previous: "Anterior", next: "Siguiente" },
          zeroRecords: "Sin registros"
        }
      });
    });
  </script>
<?php endif; ?>

<div class="task-modal-backdrop" id="taskDetailModal">
<div class="task-modal">
<header style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
<h2 style="margin:0;">Detalle de tarea</h2>
<button type="button" onclick="closeTaskDetailModal()">×</button>
</header>
 
    <div style="margin-top:12px;">
<h3 style="margin:0 0 6px 0;" data-title>Cargando...</h3>
 
      <div style="display:flex; gap:18px; flex-wrap:wrap; font-size:14px; opacity:.9;">
<div>Entrega: <b data-due>—</b></div>
<div>Prioridad: <b data-priority>—</b></div>
<div>Estatus: <b data-status>—</b></div>
</div>
 
      <p style="margin:12px 0 0 0; white-space:pre-wrap;" data-desc>—</p>
 
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:14px;">
<div class="user-info-card" style="margin:0;">
<h3 style="margin:0 0 8px 0;">Adjuntos admin</h3>
<ul style="margin:0; padding-left:18px;" data-admin-files></ul>
</div>
 
        <div class="user-info-card" style="margin:0;">
<h3 style="margin:0 0 8px 0;">Evidencias</h3>
<ul style="margin:0; padding-left:18px;" data-evidence-files></ul>
</div>
</div>
</div>
</div>
</div>

</main>

<?php include __DIR__ . '/../../../template/footer.php'; ?>

<script>
(function(){
  const UID = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;

  // claves por usuario (para que NO se repitan al abrir/cerrar)
  const KEY_SINCE = `tasks_since_event_id_u${UID}`;
  const KEY_SEEN  = `tasks_seen_event_ids_u${UID}`;

  let lastSig = '';
  let sinceEventId = parseInt(localStorage.getItem(KEY_SINCE) || '0', 10);

async function ensureNotifyPermission(){
  if (!("Notification" in window)) return false;

  if (Notification.permission === "granted") return true;

  // Importante: esto debe correr por una acción del usuario la primera vez.
  // Pero en localhost normalmente ya lo tienes concedido por tickets.
  if (Notification.permission === "default") {
    const p = await Notification.requestPermission();
    return p === "granted";
  }

  return false; // denied
}

async function pushNotify(title, body, tag){
  const ok = await ensureNotifyPermission();
  if (!ok) return;

  // 1) Preferir Service Worker (notificación "real" como la tuya)
  if ("serviceWorker" in navigator) {
    const reg = await navigator.serviceWorker.getRegistration();
    if (reg && reg.showNotification) {
      reg.showNotification(title, {
        body,
        tag,              // evita duplicadas si llega varias veces
        renotify: false,
        silent: false
        // icon: "/HelpDesk_EQF/assets/img/logo_helpdesk.png" // si quieres
      });
      return;
    }
  }

  // 2) Fallback
  new Notification(title, { body, tag });
}


  function getSeen(){
    try { return new Set(JSON.parse(localStorage.getItem(KEY_SEEN) || '[]')); }
    catch(e){ return new Set(); }
  }
  function saveSeen(set){
    // guarda solo últimos 200 para que no crezca infinito
    const arr = Array.from(set).slice(-200);
    localStorage.setItem(KEY_SEEN, JSON.stringify(arr));
  }

  function showToast(msg, tag){
    pushNotify("Tareas · HelpDesk EQF", msg, tag || "task-event");
  }


  async function poll(){
    try{
      const url = `/HelpDesk_EQF/modules/dashboard/tasks/ajax/tasks_signature.php?since_event_id=${sinceEventId}`;
      const r = await fetch(url, { cache:'no-store' });
      const j = await r.json();
      if(!j.ok) return;

      const seen = getSeen();

      // 1) notificaciones (sin repetir)
      if (Array.isArray(j.events) && j.events.length){
        for (const ev of j.events){
          const id = String(ev.id || '');
          if (!id || seen.has(id)) continue;

         if (ev.event_type === 'REASSIGNED') {
  showToast(
    `Te reasignaron/retiraron una tarea: ${ev.note || ''}`.trim(),
    `task-${ev.task_id}-reassigned`
  );
}
if (ev.event_type === 'CANCELED') {
  showToast(
    `Cancelaron una tarea: ${ev.note || ''}`.trim(),
    `task-${ev.task_id}-canceled`
  );
}


          seen.add(id);
        }
        saveSeen(seen);
      }

      // 2) avanzar marcador SIEMPRE (aunque venga string)
      const maxId = parseInt(j.max_event_id ?? sinceEventId, 10) || sinceEventId;

      let maxFromEvents = sinceEventId;
      if (Array.isArray(j.events)) {
        for (const ev of j.events) {
          const eid = parseInt(ev.id || 0, 10) || 0;
          if (eid > maxFromEvents) maxFromEvents = eid;
        }
      }

      const nextSince = Math.max(maxId, maxFromEvents);

      if (nextSince > sinceEventId) {
        sinceEventId = nextSince;
        localStorage.setItem(KEY_SINCE, String(sinceEventId));
      }


      // 3) refresh si cambió firma
      if(!lastSig){ lastSig = j.signature || ''; return; }
      if((j.signature || '') !== lastSig) location.reload();

    }catch(e){}
  }

  poll();
  setInterval(poll, 4000);
  document.addEventListener('visibilitychange', () => { if(!document.hidden) poll(); });
})();
</script>
<script>
  // Delegación de evento: funciona siempre aunque cambie el DOM
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-open-task-detail]');
    if (!btn) return;

    const taskId = parseInt(btn.dataset.taskId || '0', 10);
    if (!taskId) return;

    openTaskDetailModal(taskId);
  });

  function closeTaskDetailModal(){
    document.getElementById('taskDetailModal')?.classList.remove('is-visible');
  }

  document.getElementById('taskDetailModal')?.addEventListener('click', (e)=>{
    if(e.target.id === 'taskDetailModal') closeTaskDetailModal();
  });

  async function openTaskDetailModal(taskId){
    const modal = document.getElementById('taskDetailModal');
    if (!modal) { alert('No existe el modal en el DOM'); return; }

    modal.classList.add('is-visible');

    // placeholders
    modal.querySelector('[data-title]').textContent = 'Cargando...';
    modal.querySelector('[data-admin-files]').innerHTML = '<li style="opacity:.7;">Cargando…</li>';
    modal.querySelector('[data-evidence-files]').innerHTML = '<li style="opacity:.7;">Cargando…</li>';

    let r, j;
    try{
      r = await fetch(`/HelpDesk_EQF/modules/dashboard/tasks/ajax/task_detail.php?id=${taskId}`, {cache:'no-store'});
      j = await r.json();
    } catch (err){
      alert('No se pudo leer la respuesta del servidor (JSON). Revisa si te está redirigiendo a login o tirando HTML.');
      return;
    }

    if(!j.ok){ alert(j.msg || 'No se pudo cargar'); return; }

    const task = j.task || {};
    modal.querySelector('[data-title]').textContent = task.title || '—';
    modal.querySelector('[data-desc]').textContent = task.description || '';
    modal.querySelector('[data-due]').textContent = task.due_at || '—';
    modal.querySelector('[data-priority]').textContent = task.priority_name || '—';
    modal.querySelector('[data-status]').textContent = task.status || '—';

    const adminUL = modal.querySelector('[data-admin-files]');
    const evUL = modal.querySelector('[data-evidence-files]');
    adminUL.innerHTML = '';
    evUL.innerHTML = '';

    const baseAdmin = '/HelpDesk_EQF/uploads/tasks/admin/';
    const baseEv = '/HelpDesk_EQF/uploads/tasks/evidence/';

    const files = Array.isArray(j.files) ? j.files : [];
    const adminFiles = files.filter(f => f.file_type === 'ADMIN_ATTACHMENT');
    const evFiles    = files.filter(f => f.file_type === 'EVIDENCE');

    adminUL.innerHTML = adminFiles.length ? '' : '<li style="opacity:.7;">Sin adjuntos.</li>';
    evUL.innerHTML    = evFiles.length ? '' : '<li style="opacity:.7;">Sin evidencias.</li>';

    adminFiles.forEach(f=>{
      const li=document.createElement('li');
      li.innerHTML = `<a target="_blank" rel="noopener" href="${baseAdmin}${encodeURIComponent(f.stored_name)}">${f.original_name}</a>`;
      adminUL.appendChild(li);
    });

    evFiles.forEach(f=>{
      const li=document.createElement('li');
      li.innerHTML = `<a target="_blank" rel="noopener" href="${baseEv}${encodeURIComponent(f.stored_name)}">${f.original_name}</a>`;
      evUL.appendChild(li);
    });
  }
</script>



</body>
</html>
<script src="/HelpDesk_EQF/assets/js/script.js?v=<?php echo time(); ?>"></script>
