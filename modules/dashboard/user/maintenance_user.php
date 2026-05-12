<?php
session_start();
require_once __DIR__ . '/../../../config/connectionBD.php';

$pdo = Database::getConnection();

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
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
  #modal-maintenance-create .eqf-modal-body,
  #modal-maintenance-view .eqf-modal-body{
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
        <p class="user-main-subtitle">Levanta una solicitud de mantenimiento y consulta su estatus</p>
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
         placeholder="Ej. Reparación de luminarias"
         required>
                     

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
            placeholder="Ej. Se requiere mantenimiento al aire acondicionado del área administrativa..."
            required></textarea>

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
                     multiple
                     accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx"
                     capture="environment"
                     hidden>

              <span>
                Adjuntar archivos o tomar foto
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
<div class="eqf-modal-backdrop" id="modal-maintenance-view">
  <div class="eqf-modal">
    <div class="eqf-modal-header">
      <h3>Detalle Mantenimiento</h3>
      <button class="eqf-modal-close" type="button" onclick="closeModal('modal-maintenance-view')">✕</button>
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
    if (s === 'ABIERTO') return 'Abierto';
    if (s === 'PENDIENTE') return 'Pendiente';
    if (s === 'EN_PROCESO') return 'En proceso';
    if (s === 'FINALIZADO') return 'Finalizado';
    if (s === 'CANCELADO') return 'Cancelado';
    return s || '—';
  }


  async function apiJson(url, opts={}){
    const r = await fetch(url, opts);
    const j = await r.json().catch(()=>({ok:false,msg:'Respuesta inválida'}));
    if (!r.ok) throw new Error(j.msg || 'Error de servidor');
    return j;
  }



  function render(rows){
    reqList.innerHTML = '';

    if (!rows || !rows.length){
      reqEmpty.style.display = 'block';
      return;
    }
    reqEmpty.style.display = 'none';

    rows.forEach(rq=>{
      const created = esc(rq.created_at || '');

      const card = document.createElement('div');
      card.className = 'support-card';


      card.innerHTML = `
        <div class="support-card__top">
          <div class="support-card__person">
            <div class="support-card__avatar">EQ</div>
            <div class="support-card__info">
              <div class="support-card__name">Mantenimiento #${Number(rq.id)}</div>
              <div class="support-card__meta">${created}</div>
            </div>
          </div>
          <div class="support-card__status">
            <span class="status-pill">${esc(statusLabel(rq.status))}</span>
          </div>
        </div>

<div class="support-card__body">

  <div class="support-row">
    <span class="support-row__label">Título:</span>
    <span class="support-row__value">${esc(rq.title || '')}</span>
  </div>

  <div class="support-row">
    <span class="support-row__label">Descripción:</span>
    <span class="support-row__value">${esc(rq.description || '')}</span>
  </div>

</div>

        <div class="task-actions" style="justify-content:flex-end;">
          <button class="task-link-blue" type="button" data-view="${Number(rq.id)}">Ver</button>
        </div>
      `;

      reqList.appendChild(card);
    });

    reqList.querySelectorAll('button[data-view]').forEach(btn=>{
      btn.addEventListener('click', ()=> openDetail(btn.getAttribute('data-view')));
    });

  }

  async function loadList(){
    const out = await apiJson('/HelpDesk_EQF/modules/dashboard/user/ajax/maintenance_list.php');
    render(out.rows || []);
  }

  async function openDetail(id){
    openModal('modal-maintenance-view');
    const viewBody = document.getElementById('viewBody');
    viewBody.textContent = 'Cargando...';

    try{
      const out = await apiJson('/HelpDesk_EQF/modules/dashboard/user/ajax/maintenance_detail.php?id=' + encodeURIComponent(id));
      viewBody.innerHTML = out.html || 'Sin datos';
    }catch(e){
      viewBody.textContent = e.message || 'No se pudo cargar';
    }
  }

reqForm.addEventListener('submit', async (ev)=>{

  ev.preventDefault();

  const submitBtn = reqForm.querySelector('button[type="submit"]');

  if (submitBtn.disabled) {
    return;
  }

  submitBtn.disabled = true;

  const originalText = submitBtn.innerHTML;

  submitBtn.innerHTML = 'Enviando...';

  const fd = new FormData(reqForm);

  selectedFiles.forEach(file => {
    fd.append('files[]', file);
  });

  try{

    const out = await apiJson(
      '/HelpDesk_EQF/modules/dashboard/user/ajax/maintenance_create.php',
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

  } finally {

    submitBtn.disabled = false;

    submitBtn.innerHTML = originalText;

  }

});

loadList();

})();
</script>

<script src="/HelpDesk_EQF/assets/js/script.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/../../../template/footer.php'; ?>
