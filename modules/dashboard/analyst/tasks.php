<?php
session_start();
require_once __DIR__ . '/../../../config/connectionBD.php';
include __DIR__ . '/../../../template/header.php';
include __DIR__ . '/../../../template/sidebar.php';

// Solo Analistas (rol = 3)
if (!isset($_SESSION['user_id']) || (int)($_SESSION['user_rol'] ?? 0) !== 3) {
    header('Location: /HelpDesk_EQF/auth/login.php');
    exit;
}

$pdo    = Database::getConnection();
$area   = $_SESSION['user_area'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mis tareas | HELP DESK EQF</title>
  <link rel="stylesheet" href="/HelpDesk_EQF/assets/css/style.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

  <style>
    .task-badge{
      display:inline-flex; align-items:center; gap:6px;
      padding:4px 10px; border-radius:999px;
      font-size:12px; font-weight:900;
      border:1px solid var(--eqf-border,#e5e7eb);
      background:#fff;
      text-transform: uppercase;
      letter-spacing: .5px;
    }
    .task-badge.pendiente{ background: rgba(200,0,45,.08); }
    .task-badge.en_proceso{ background: rgba(20,55,138,.10); }
    .task-badge.cerrada{ background: rgba(30,138,79,.10); }
    .task-badge.cancelada{ background: rgba(170,170,170,.18); }

    .task-actions{ display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap; }
    .btn-mini{
      padding:6px 10px; border-radius:12px; font-weight:900;
      border:1px solid var(--eqf-border,#e5e7eb); background:#fff; cursor:pointer;
      white-space:nowrap;
    }
    .btn-mini.primary{ background: var(--eqf-combined,#6e1c5c); color:#fff; border-color:transparent; }
    .btn-mini.success{ background: var(--eqf-green,#1E8A4F); color:#fff; border-color:transparent; }
    .btn-mini[disabled]{ opacity:.6; cursor:not-allowed; }

    .task-attachments{ display:flex; flex-direction:column; gap:8px; margin-top:6px; }
    .task-attachments a{
      display:inline-block; padding:8px 10px;
      border:1px solid var(--eqf-border,#e5e7eb);
      border-radius:12px; text-decoration:none;
    }
    .task-detail-meta{ display:flex; gap:10px; flex-wrap:wrap; font-size:13px; opacity:.9; margin-bottom:10px; }
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
        <p class="user-main-subtitle">Mis tareas – <?php echo htmlspecialchars($area, ENT_QUOTES, 'UTF-8'); ?></p>
      </div>
    </header>

    <section class="user-main-content">
      <div class="user-info-card">
        <h2>Mis tareas</h2>
        <p>Aquí aparecen las tareas asignadas por tu Admin. Puedes marcar “Enterado” y después “Finalizada”.</p>

        <table id="tasksTable" class="data-table display" style="width:100%;">
          <thead>
            <tr>
              <th>ID</th>
              <th>Creada</th>
              <th>Título</th>
              <th>Estado</th>
              <th>Fecha límite</th>
              <th>Adjunto</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody><!-- AJAX --></tbody>
        </table>
      </div>
    </section>

  </section>
</main>

<!-- MODAL DETALLE TAREA -->
<div class="modal-backdrop" id="task-detail-modal">
  <div class="modal-card" style="max-width:760px;">
    <div class="modal-header">
      <h3 id="taskDetailTitle">Detalle de tarea</h3>
      <button type="button" class="modal-close" onclick="closeTaskDetail()">✕</button>
    </div>

    <div class="modal-body" style="padding:14px 18px;">
      <div id="taskDetailContent">
        <div style="opacity:.8;">Cargando...</div>
      </div>

      <div class="modal-actions" style="margin-top:14px; display:flex; gap:10px; justify-content:flex-end;">
        <button type="button" class="btn-secondary" onclick="closeTaskDetail()">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../../template/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="/HelpDesk_EQF/assets/js/script.js?v=20251208a"></script>

<script>
const TASKS_LIST = '/HelpDesk_EQF/modules/tasks/get_my_tasks.php';
const TASK_ACK  = '/HelpDesk_EQF/modules/tasks/ack_task.php';
const TASK_DONE = '/HelpDesk_EQF/modules/tasks/finish_task.php';

function escapeHtml(str){
  return String(str ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}

function showToast(msg){
  const toast = document.createElement('div');
  toast.className = 'eqf-toast-ticket';
  toast.textContent = msg || '';
  document.body.appendChild(toast);
  setTimeout(() => {
    toast.classList.add('hide');
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

function badgeEstado(estado){
  const e = (estado || '').toLowerCase();
  return `<span class="task-badge ${escapeHtml(e)}">${escapeHtml(e)}</span>`;
}

let dt = null;
let cacheTasks = [];

function openTaskDetail(taskId){
  const t = cacheTasks.find(x => String(x.id) === String(taskId));
  const title = document.getElementById('taskDetailTitle');
  const content = document.getElementById('taskDetailContent');

  if (title) title.textContent = 'Detalle de tarea #' + taskId;

  if (!t){
    content.innerHTML = `<div style="color:#b91c1c; font-weight:900;">No se encontró la tarea.</div>`;
  } else {
    const meta = `
      <div class="task-detail-meta">
        <div><strong>Estado:</strong> ${escapeHtml(t.estado || '')}</div>
        <div><strong>Creada:</strong> ${escapeHtml(t.created_at || '')}</div>
        <div><strong>Fecha límite:</strong> ${escapeHtml(t.fecha_limite || '—')}</div>
      </div>
    `;

    const desc = `
      <div><strong>Descripción:</strong>
        <div style="margin-top:6px; white-space:pre-wrap;">${escapeHtml(t.descripcion || '—')}</div>
      </div>
    `;

    let attHtml = `<div style="margin-top:12px;"><strong>Adjuntos:</strong><div style="margin-top:6px; opacity:.75;">Sin adjuntos.</div></div>`;
    if (t.archivo_url){
      attHtml = `
        <div style="margin-top:12px;">
          <strong>Adjuntos:</strong>
          <div class="task-attachments">
            <a href="${escapeHtml(t.archivo_url)}" target="_blank" rel="noopener">📎 ${escapeHtml(t.archivo_nombre || 'Descargar')}</a>
          </div>
        </div>
      `;
    }

    content.innerHTML = `<div><strong>Título:</strong> ${escapeHtml(t.titulo || '')}</div>` + meta + desc + attHtml;
  }

  const modal = document.getElementById('task-detail-modal');
  if (typeof openModal === 'function') openModal('task-detail-modal');
  else if (modal) modal.classList.add('show');
}

function closeTaskDetail(){
  const modal = document.getElementById('task-detail-modal');
  if (typeof closeModal === 'function') closeModal('task-detail-modal');
  else if (modal) modal.classList.remove('show');
}

function renderRowActions(t){
  const id = t.id;
  const estado = (t.estado || '').toLowerCase();

  const btnVer = `<button class="btn-mini" type="button" onclick="openTaskDetail(${id})">Ver</button>`;

  // Pendiente -> Enterado
  const btnAck = `<button class="btn-mini primary" type="button" onclick="ackTask(${id})">Enterado</button>`;

  // En proceso -> Finalizada
  const btnDone = `<button class="btn-mini success" type="button" onclick="finishTask(${id})">Finalizada</button>`;

  let right = '';
  if (estado === 'pendiente') right = btnAck;
  else if (estado === 'en_proceso') right = btnDone;
  else right = `<button class="btn-mini" type="button" disabled>Sin acciones</button>`;

  return `<div class="task-actions">${btnVer}${right}</div>`;
}

function renderTable(tasks){
  const rows = tasks.map(t => {
    const adj = t.archivo_url
      ? `<a class="btn-mini" href="${escapeHtml(t.archivo_url)}" target="_blank" rel="noopener">📎 Descargar</a>`
      : `<span style="opacity:.65;">—</span>`;

    return [
      `#${t.id}`,
      escapeHtml(t.created_at || ''),
      escapeHtml(t.titulo || ''),
      badgeEstado(t.estado || ''),
      escapeHtml(t.fecha_limite || ''),
      adj,
      renderRowActions(t),
    ];
  });

  if (!dt){
    dt = $('#tasksTable').DataTable({ pageLength: 8, order: [[1,'desc']] });
  }
  dt.clear();
  dt.rows.add(rows);
  dt.draw(false);
}

function refresh(){
  return fetch(TASKS_LIST)
    .then(r => r.json())
    .then(data => {
      if (!data.ok) return [];
      cacheTasks = Array.isArray(data.tasks) ? data.tasks : [];
      renderTable(cacheTasks);
      return cacheTasks;
    })
    .catch(() => []);
}

function postForm(url, obj){
  const body = new URLSearchParams();
  Object.keys(obj).forEach(k => body.set(k, obj[k]));
  return fetch(url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body })
    .then(r => r.json());
}

function ackTask(taskId){
  postForm(TASK_ACK, { task_id: taskId })
    .then(data => {
      if (!data.ok) return alert(data.msg || 'No se pudo marcar enterado');
      showToast('Tarea #' + taskId + ' marcada como ENTERADO.');
      refresh();
    })
    .catch(()=>alert('Error interno'));
}

function finishTask(taskId){
  postForm(TASK_DONE, { task_id: taskId })
    .then(data => {
      if (!data.ok) return alert(data.msg || 'No se pudo finalizar');
      showToast('Tarea #' + taskId + ' marcada como FINALIZADA.');
      refresh();
    })
    .catch(()=>alert('Error interno'));
}

document.addEventListener('DOMContentLoaded', () => {
  refresh();
  // Si quieres “casi en vivo”
  setInterval(refresh, 10000);
});
</script>
<script src="/HelpDesk_EQF/assets/js/script.js?v=20251208a"></script>
<script src="/HelpDesk_EQF/assets/js/sidebar.js?v=20251208a"></script>
</body>
</html>
