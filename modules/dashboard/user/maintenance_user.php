<?php
// /HelpDesk_EQF/modules/dashboard/user/equipment.php
session_start();
require_once __DIR__ . '/../../../config/connectionBD.php';

$pdo = Database::getConnection();

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// =====================================================
// AJAX: cambiar estatus desde ESTE MISMO archivo
// (solo roles 2 y 3)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
  header('Content-Type: application/json; charset=utf-8');

  if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'msg'=>'No autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $rol = (int)($_SESSION['user_rol'] ?? 0);
  if (!in_array($rol, [2,3], true)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'msg'=>'Sin permisos'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $adminId   = (int)$_SESSION['user_id'];
  $requestId = (int)($_POST['request_id'] ?? 0);
  $status    = strtoupper(trim((string)($_POST['status'] ?? '')));

  if ($requestId <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'msg'=>'ID inválido'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $allowed = ['ENVIADO_DHL','ENVIADO_LEVIC','ENTREGADO_CORPO'];
  if (!in_array($status, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'msg'=>'Estatus inválido'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  try {
    $st = $pdo->prepare("SELECT status FROM equipment_requests WHERE id = ? LIMIT 1");
    $st->execute([$requestId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      http_response_code(404);
      echo json_encode(['ok'=>false,'msg'=>'No encontrada'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $cur = strtoupper((string)($row['status'] ?? ''));

    // Si ya está cerrada, no permitir cambios
    if (in_array($cur, ['ENTREGADO','ENTREGADO_CORPO'], true)) {
      http_response_code(409);
      echo json_encode(['ok'=>false,'msg'=>'La requisición ya está cerrada'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if ($status === 'ENTREGADO_CORPO') {
      // Cierra directo (sin evidencia)
      $up = $pdo->prepare("
        UPDATE equipment_requests
        SET status = 'ENTREGADO_CORPO',
            delivered_at = NOW(),
            delivered_by_user_id = NULL
        WHERE id = ?
        LIMIT 1
      ");
      $up->execute([$requestId]);

      echo json_encode(['ok'=>true,'msg'=>'Cerrada como Entregado en corpo'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    // ENVIADO DHL / LEVIC
    $up = $pdo->prepare("
      UPDATE equipment_requests
      SET status = ?,
          shipped_at = NOW(),
          shipped_by_admin_id = ?
      WHERE id = ?
      LIMIT 1
    ");
    $up->execute([$status, $adminId, $requestId]);

    echo json_encode(['ok'=>true,'msg'=>'Estatus actualizado'], JSON_UNESCAPED_UNICODE);
    exit;

  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'msg'=>'Error al actualizar estatus'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

// =====================================================
// VISTA: Solicitar equipo (roles 2,3,4)
// =====================================================
if (
  !isset($_SESSION['user_id']) ||
  !in_array((int)($_SESSION['user_rol'] ?? 0), [2,3,4], true)
) {
  header('Location: /HelpDesk_EQF/auth/login.php');
  exit;
}

include __DIR__ . '/../../../template/header.php';
include __DIR__ . '/../../../template/sidebar.php';

$userId = (int)$_SESSION['user_id'];

// email desde BD
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$me = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$email = trim((string)($me['email'] ?? ''));


?>
<link rel="stylesheet" href="/HelpDesk_EQF/assets/css/style.css?v=<?php echo time(); ?>">

<style>
  /* Scroll dentro del modal */
  #modal-maintence-create .eqf-modal-body,
  #modal-equipment-view .eqf-modal-body{
    max-height: calc(100vh - 220px);
    overflow: auto;
  }

  /* Input readonly gris */
  .eqf-input-readonly{
    background-color: #f3f4f6 !important;
    color: #6b7280 !important;
    border: 1px solid #e5e7eb !important;
    cursor: not-allowed;
  }
  .eqf-input-readonly:focus{ outline:none !important; box-shadow:none !important; }

  

  .eqf-eq-item{
    border:1px solid rgba(0,0,0,.08);
    border-radius:14px;
    padding:12px;
    background:#fff;
  }
  .eqf-eq-item__top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:10px;
  }
  .eqf-eq-item__name{ font-weight:700; font-size:14px; }

  .eqf-eq-item__controls{
    display:grid;
    grid-template-columns:120px 1fr;
    gap:10px;
    align-items:end;
  }
  .eqf-eq-mini label{
    font-size:12px;
    opacity:.8;
    display:block;
    margin-bottom:6px;
  }
  .eqf-eq-mini input, .eqf-eq-mini select{ width:100%; }
  .eqf-eq-mini select{
    padding:10px 12px;
    border-radius:10px;
    border:1px solid rgba(0,0,0,.12);
    background:#fff;
  }
  .eqf-eq-mini select:disabled{
    background:#f3f4f6;
    color:#6b7280;
  }
  .eqf-block{ margin-top:14px; }
  .eqf-upload-wrap{
  margin-top:15px;
}

.eqf-upload-box{
  border:2px dashed #d0d7de;
  border-radius:14px;
  padding:18px;
  text-align:center;
  cursor:pointer;
  display:block;
  transition:.2s ease;
  background:#fafafa;
}

.eqf-upload-box:hover{
  background:#f3f4f7;
}

.eqf-file-preview-list{
  margin-top:15px;
  display:flex;
  flex-direction:column;
  gap:10px;
}

.eqf-file-item{
  display:flex;
  align-items:center;
  justify-content:space-between;
  border:1px solid #e5e7eb;
  border-radius:12px;
  padding:10px;
  background:#fff;
}

.eqf-file-info{
  display:flex;
  align-items:center;
  gap:12px;
}

.eqf-file-thumb{
  width:55px;
  height:55px;
  object-fit:cover;
  border-radius:10px;
  border:1px solid #ddd;
}

.eqf-file-icon{
  width:55px;
  height:55px;
  display:flex;
  align-items:center;
  justify-content:center;
  border-radius:10px;
  background:#f3f4f6;
  font-size:24px;
}

.eqf-file-name{
  font-size:14px;
  font-weight:600;
}

.eqf-file-size{
  font-size:12px;
  color:#6b7280;
}

.eqf-remove-file{
  border:none;
  background:#ef4444;
  color:#fff;
  width:32px;
  height:32px;
  border-radius:8px;
  cursor:pointer;
}
</style>

<div class="user-main">
  <div class="user-main-inner">

    <div class="user-main-header">
      <div>
        <h1 class="login-brand">
          <span class="eqf-e">E</span><span class="eqf-q">Q</span><span class="eqf-f">F</span>
          · Solicitud de mantenimiento
        </h1>
        <p class="user-main-subtitle">Levanta una soliciutd de mantenimiento y consulta su estatus</p>
      </div>

      <button class="eqf-btn eqf-btn-primary" type="button" onclick="openModal('modal-maintenance-create')">
        Solicitar
      </button>
    </div>

    <div class="cards-grid" id="reqList"></div>

    <div class="empty-state" id="reqEmpty" style="display:none;">
      No tienes solicitudes registradas.
    </div>

  </div>
</div>

<!-- MODAL: CREAR -->
<!-- MODAL: CREAR -->
<div class="eqf-modal-backdrop" id="modal-maintenance-create">

  <div class="eqf-modal">

    <div class="eqf-modal-header">
      <h3>Solicitar Mantenimiento</h3>

      <button class="eqf-modal-close"
              type="button"
              onclick="closeModal('modal-maintenance-create')">
        ✕
      </button>
    </div>

    <div class="eqf-modal-body">

      <form id="reqForm">

        <!-- DATOS -->
        <div class="eqf-block">

          <div class="eqf-grid-2" style="margin-top:10px;">

            <div class="eqf-field">

              <label>Solicitante</label>

              <input type="email"
                     name="requester_email"
                     value="<?php echo h($email); ?>"
                     readonly
                     class="eqf-input-readonly">

            </div>

            <div class="eqf-field">

              <label>Título del mantenimiento</label>

              <input type="text"
                     name="title"
                     maxlength="150"
                     placeholder="Ej. Reparación de luminarias">

            </div>

          </div>

        </div>

        <hr class="eqf-hr">

        <!-- DESCRIPCIÓN -->
        <div class="eqf-block">

          <div class="panel-mini-title">
            Mantenimiento solicitado
          </div>

          <div class="panel-muted">
            Describe detalladamente lo que se necesita realizar.
          </div>

          <div class="eqf-field" style="margin-top:15px;">

            <label>Descripción</label>

            <textarea name="description"
                      rows="5"
                      maxlength="1000"
                      placeholder="Ej. Se requiere mantenimiento al aire acondicionado del área administrativa..."></textarea>

          </div>

        </div>

        <!-- ARCHIVOS -->
        <div class="eqf-block">

          <div class="panel-mini-title">
            Evidencias / Archivos
          </div>

          <div class="panel-muted">
            Puedes adjuntar fotografías, videos o documentos.
          </div>

          <div class="eqf-upload-wrap">

            <label class="eqf-upload-box">

              <input type="file"
                     id="maintenanceFiles"
                     name="files[]"
                     multiple
                     accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx"
                     capture="environment"
                     hidden>

              <span>
                📎 Adjuntar archivos o tomar foto
              </span>

            </label>

            <div id="filePreviewList"
                 class="eqf-file-preview-list"></div>

          </div>

        </div>

        <!-- FOOTER -->
        <div class="eqf-modal-footer">

          <button class="eqf-btn eqf-btn-secondary"
                  type="button"
                  onclick="closeModal('modal-maintenance-create')">

            Cancelar

          </button>

          <button class="eqf-btn eqf-btn-primary"
                  type="submit">

            Enviar solicitud

          </button>

        </div>

      </form>

    </div>

  </div>

</div>

<!-- MODAL: VER -->
<div class="eqf-modal-backdrop" id="modal-equipment-view">
  <div class="eqf-modal">
    <div class="eqf-modal-header">
      <h3>Detalle requisición</h3>
      <button class="eqf-modal-close" type="button" onclick="closeModal('modal-equipment-view')">✕</button>
    </div>
    <div class="eqf-modal-body" id="viewBody">Cargando...</div>
  </div>
</div>

<script>
(function(){
  const reqList  = document.getElementById('reqList');
  const reqEmpty = document.getElementById('reqEmpty');
  const reqForm  = document.getElementById('reqForm');


  const inputFiles = document.getElementById('maintenanceFiles');
const previewList = document.getElementById('filePreviewList');

let selectedFiles = [];

inputFiles.addEventListener('change', (e) => {

  const newFiles = Array.from(e.target.files);

  newFiles.forEach(file => {
    selectedFiles.push(file);
  });

  renderFiles();

  inputFiles.value = '';
});

function renderFiles(){

  previewList.innerHTML = '';

  selectedFiles.forEach((file, index) => {

    const item = document.createElement('div');

    item.classList.add('eqf-file-item');

    const isImage = file.type.startsWith('image/');

    item.innerHTML = `

      <div class="eqf-file-info">

        ${
          isImage
          ? `<img src="${URL.createObjectURL(file)}" class="eqf-file-thumb">`
          : `<div class="eqf-file-icon">📄</div>`
        }

        <div>

          <div class="eqf-file-name">
            ${esc(file.name)}
          </div>

          <div class="eqf-file-size">
            ${(file.size / 1024 / 1024).toFixed(2)} MB
          </div>

        </div>

      </div>

      <button type="button"
              class="eqf-remove-file"
              onclick="removeFile(${index})">

        ✕

      </button>
    `;

    previewList.appendChild(item);
  });
}

window.removeFile = function(index){

  selectedFiles.splice(index, 1);

  renderFiles();
}


  function esc(s){
    return String(s ?? '').replace(/[&<>"']/g, m => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[m]));
  }

  function statusLabel(st){
    const s = String(st || '').toUpperCase();
    if (s === 'VERIFICANDO') return 'Verificando';
    if (s === 'ENVIADO_DHL') return 'Enviado por DHL';
    if (s === 'ENVIADO_LEVIC') return 'Enviado por Levic';
    if (s === 'ENTREGADO_CORPO') return 'Entregado en corpo';
    if (s === 'ENTREGADO') return 'Entregado';
    return s || '—';
  }


  async function apiJson(url, opts={}){
    const r = await fetch(url, opts);
    const j = await r.json().catch(()=>({ok:false,msg:'Respuesta inválida'}));
    if (!r.ok) throw new Error(j.msg || 'Error de servidor');
    return j;
  }

  async function uploadEvidenceFromCard(reqId, fileInput){
    if (!fileInput.files || !fileInput.files[0]) return alert('Selecciona un archivo');

    const fd = new FormData();
    fd.append('request_id', String(reqId));
    fd.append('file', fileInput.files[0]);

    const r = await fetch('/HelpDesk_EQF/modules/dashboard/user/ajax/equipment_upload.php', { method:'POST', body: fd });
    const j = await r.json().catch(()=>({ok:false,msg:'Respuesta inválida'}));
    if (!r.ok) return alert(j.msg || 'No se pudo subir');

    alert(j.msg || 'Evidencia subida');
    fileInput.value = '';
    loadList();
  }

  async function markDeliveredFromCard(reqId){
    const r = await fetch('/HelpDesk_EQF/modules/dashboard/user/ajax/equipment_deliver.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ request_id: String(reqId) })
    });
    const j = await r.json().catch(()=>({ok:false,msg:'Respuesta inválida'}));
    if (!r.ok) return alert(j.msg || 'No se pudo marcar');

    alert(j.msg || 'Entregado');
    loadList();
  }

  function render(rows){
    reqList.innerHTML = '';

    if (!rows || !rows.length){
      reqEmpty.style.display = 'block';
      return;
    }
    reqEmpty.style.display = 'none';

    rows.forEach(rq=>{
      const items = Array.isArray(rq.items) ? rq.items : [];
      const created = esc(rq.created_at || '');
      const st = String(rq.status || '').toUpperCase();

      const card = document.createElement('div');
      card.className = 'support-card';

      let extraActions = '';
      if (st === 'ENVIADO_DHL' || st === 'ENVIADO_LEVIC') {
        extraActions = `
          <div class="task-actions" style="justify-content:flex-end;">
            <input type="file" class="ticket-chat-file" data-eqf-file="${Number(rq.id)}" accept=".jpg,.jpeg,.png,.pdf">
            <button class="task-link-blue" type="button" data-upload="${Number(rq.id)}">Adjuntar archivos</button>
            <button class="task-link-combined" type="button" data-deliver="${Number(rq.id)}">Entregado</button>
          </div>
        `;
      }

      card.innerHTML = `
        <div class="support-card__top">
          <div class="support-card__person">
            <div class="support-card__avatar">EQ</div>
            <div class="support-card__info">
              <div class="support-card__name">Requisición #${Number(rq.id)}</div>
              <div class="support-card__meta">${created}</div>
            </div>
          </div>
          <div class="support-card__status">
            <span class="status-pill">${esc(statusLabel(rq.status))}</span>
          </div>
        </div>

        <div class="support-card__body">
          ${items.map(it => `
            <div class="support-row">
              <span class="support-row__label">${Number(it.quantity||1)}×</span>
              <span class="support-row__value">${esc(it.item_type)}${it.description ? ' · ' + esc(it.description) : ''}</span>
            </div>
          `).join('')}
        </div>

        <div class="task-actions" style="justify-content:flex-end;">
          <button class="task-link-blue" type="button" data-view="${Number(rq.id)}">Ver</button>
        </div>

        ${extraActions}
      `;

      reqList.appendChild(card);
    });

    reqList.querySelectorAll('button[data-view]').forEach(btn=>{
      btn.addEventListener('click', ()=> openDetail(btn.getAttribute('data-view')));
    });

    reqList.querySelectorAll('button[data-upload]').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const id = btn.getAttribute('data-upload');
        const input = reqList.querySelector(`input[data-eqf-file="${id}"]`);
        if (!input) return;
        input.click();
        input.onchange = ()=> uploadEvidenceFromCard(id, input);
      });
    });

    reqList.querySelectorAll('button[data-deliver]').forEach(btn=>{
      btn.addEventListener('click', ()=> markDeliveredFromCard(btn.getAttribute('data-deliver')));
    });
  }

  async function loadList(){
    const out = await apiJson('/HelpDesk_EQF/modules/dashboard/user/ajax/equipment_list.php');
    render(out.rows || []);
  }

  async function openDetail(id){
    openModal('modal-equipment-view');
    const viewBody = document.getElementById('viewBody');
    viewBody.textContent = 'Cargando...';

    try{
      const out = await apiJson('/HelpDesk_EQF/modules/dashboard/user/ajax/equipment_detail.php?id=' + encodeURIComponent(id));
      viewBody.innerHTML = out.html || 'Sin datos';
    }catch(e){
      viewBody.textContent = e.message || 'No se pudo cargar';
    }
  }

reqForm.addEventListener('submit', async (ev)=>{

  ev.preventDefault();

  const fd = new FormData(reqForm);

  selectedFiles.forEach(file => {
    fd.append('files[]', file);
  });

  try{

    const out = await apiJson(
      '/HelpDesk_EQF/modules/dashboard/user/ajax/equipment_create.php',
      {
        method:'POST',
        body: fd
      }
    );

    alert(out.msg || 'Solicitud creada');

    closeModal('modal-maintenance-create');

    reqForm.reset();

    selectedFiles = [];

    renderFiles();

    loadList();

  }catch(e){

    alert(e.message || 'No se pudo crear');

  }

});
</script>

<script src="/HelpDesk_EQF/assets/js/script.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/../../../template/footer.php'; ?>
