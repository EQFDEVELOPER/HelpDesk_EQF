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

// Catálogo
$catalog = [
  ['Computadora', 'Ej. Caja / Consulta / Almacén...'],
  ['Monitor', 'Opcional'],
  ['No break', 'Ubicación'],
  ['Mouse', 'Opcional'],
  ['Teclado', 'Opcional'],
  ['Cámara', 'Ej. interior/exterior/ubicación...'],
  ['Impresora', 'Ej. tickets / gerencia...'],
  ['Escáner', 'Ej. pistola / cubo...'],
  ['Otro', '¿Qué equipo?'],
];
?>
<link rel="stylesheet" href="/HelpDesk_EQF/assets/css/style.css?v=<?php echo time(); ?>">

<style>
  /* Scroll dentro del modal */
  #modal-equipment-create .eqf-modal-body,
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

  /* Catalog cards */
  .eqf-eq-catalog{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
    margin-top:10px;
  }
  @media (max-width: 900px){ .eqf-eq-catalog{ grid-template-columns:1fr; } }

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
</style>

<div class="user-main">
  <div class="user-main-inner">

    <div class="user-main-header">
      <div>
        <h1 class="login-brand">
          <span class="eqf-e">E</span><span class="eqf-q">Q</span><span class="eqf-f">F</span>
          · Solicitud de equipo
        </h1>
        <p class="user-main-subtitle">Levanta una solicitud de equipo y consulta su estatus.</p>
      </div>

      <button class="eqf-btn eqf-btn-primary" type="button" onclick="openModal('modal-equipment-create')">
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
<div class="eqf-modal-backdrop" id="modal-equipment-create">
  <div class="eqf-modal">
    <div class="eqf-modal-header">
      <h3>Solicitar Equipo</h3>
      <button class="eqf-modal-close" type="button" onclick="closeModal('modal-equipment-create')">✕</button>
    </div>

    <div class="eqf-modal-body">
      <form id="reqForm">

        <div class="eqf-block">
          <div class="eqf-grid-2" style="margin-top:10px;">
            <div class="eqf-field">
              <label>Solicitante</label>
              <input type="email" name="requester_email"
                     value="<?php echo h($email); ?>" readonly class="eqf-input-readonly">
            </div>

            <div class="eqf-field">
              <label>Comentarios (opcional)</label>
              <input type="text" name="comments" placeholder="Ej. Sucursal, área, urgencia..." maxlength="500">
            </div>
          </div>
        </div>

        <hr class="eqf-hr">

        <div class="eqf-block">
          <div class="panel-mini-title">Equipo solicitado</div>
          <div class="panel-muted">Marca el equipo, define cantidad (máx 3) y agrega detalles si aplica.</div>

          <div class="eqf-eq-catalog" id="equipCatalog">
            <?php foreach ($catalog as $c): $type=$c[0]; $ph=$c[1]; ?>
              <div class="eqf-eq-item">
                <div class="eqf-eq-item__top">
                  <label style="display:flex;align-items:center;gap:10px;margin:0;">
                    <input type="checkbox" class="eqf-eq-check" data-type="<?php echo h($type); ?>">
                    <span class="eqf-eq-item__name"><?php echo h($type); ?></span>
                  </label>
                </div>

                <div class="eqf-eq-item__controls">
                  <div class="eqf-eq-mini">
                    <label>Cantidad</label>
                    <select class="eqf-eq-qty" disabled>
                      <option value="1">1</option>
                      <option value="2">2</option>
                      <option value="3">3</option>
                    </select>
                  </div>
                  <div class="eqf-eq-mini">
                    <label>Descripción</label>
                    <input type="text" class="eqf-eq-desc" maxlength="500"
                           placeholder="<?php echo h($ph); ?>" disabled>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="eqf-field" id="extraReasonWrap" style="display:none; margin-top:12px;">
            <label>Razón para solicitar más equipo (obligatorio)</label>
            <textarea name="extra_reason" id="extraReason" rows="3"
              placeholder="Explica por qué requieres más equipo (ej. nueva apertura, reemplazo, incremento de operación, etc.)"></textarea>
          </div>
        </div>

        <div class="eqf-modal-footer">
          <button class="eqf-btn eqf-btn-secondary" type="button" onclick="closeModal('modal-equipment-create')">
            Cancelar
          </button>
          <button class="eqf-btn eqf-btn-primary" type="submit">
            Enviar requisición
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

  const extraReasonWrap = document.getElementById('extraReasonWrap');
  const extraReason = document.getElementById('extraReason');

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

  function evaluateReasonRequirement(){
    let anyMaxQty = false;
    let selectedTypes = 0;

    document.querySelectorAll('#equipCatalog .eqf-eq-item').forEach(card=>{
      const chk = card.querySelector('.eqf-eq-check');
      const qty = card.querySelector('.eqf-eq-qty');
      if (chk && chk.checked){
        selectedTypes++;
        if (String(qty?.value || '1') === '3') anyMaxQty = true;
      }
    });

    const needsReason = anyMaxQty || (selectedTypes > 3);
    extraReasonWrap.style.display = needsReason ? 'block' : 'none';
    if (!needsReason) extraReason.value = '';
  }

  function bindCatalog(){
    document.querySelectorAll('#equipCatalog .eqf-eq-check').forEach(chk=>{
      chk.addEventListener('change', ()=>{
        const card = chk.closest('.eqf-eq-item');
        const qty  = card.querySelector('.eqf-eq-qty');
        const desc = card.querySelector('.eqf-eq-desc');
        const on = chk.checked;

        qty.disabled = !on;
        desc.disabled = !on;

        if (!on){
          qty.value = '1';
          desc.value = '';
        }
        evaluateReasonRequirement();
      });
    });

    document.querySelectorAll('#equipCatalog .eqf-eq-qty').forEach(sel=>{
      sel.addEventListener('change', evaluateReasonRequirement);
    });
  }
  bindCatalog();

  function appendSelectedItems(fd){
    const cards = document.querySelectorAll('#equipCatalog .eqf-eq-item');
    let count = 0;

    cards.forEach(card=>{
      const chk = card.querySelector('.eqf-eq-check');
      if (!chk || !chk.checked) return;

      const type = (chk.getAttribute('data-type') || '').trim();
      const qty  = (card.querySelector('.eqf-eq-qty')?.value || '1');
      const desc = (card.querySelector('.eqf-eq-desc')?.value || '');

      if (!type) return;

      fd.append('item_type[]', type);
      fd.append('quantity[]', qty);
      fd.append('description[]', desc);
      count++;
    });

    return count;
  }

  function resetCatalog(){
    document.querySelectorAll('#equipCatalog .eqf-eq-check').forEach(chk => chk.checked = false);
    document.querySelectorAll('#equipCatalog .eqf-eq-qty').forEach(i => { i.value = '1'; i.disabled = true; });
    document.querySelectorAll('#equipCatalog .eqf-eq-desc').forEach(i => { i.value = ''; i.disabled = true; });
    extraReasonWrap.style.display = 'none';
    extraReason.value = '';
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
    const selected = appendSelectedItems(fd);
    if (selected < 1) return alert('Selecciona al menos 1 equipo.');

    if (extraReasonWrap.style.display !== 'none') {
      const r = (extraReason.value || '').trim();
      if (r.length < 10) return alert('Escribe una razón (mínimo 10 caracteres).');
    }

    try{
      const out = await apiJson('/HelpDesk_EQF/modules/dashboard/user/ajax/equipment_create.php', {
        method:'POST',
        body: fd
      });

      alert(out.msg || 'Requisición creada');
      closeModal('modal-equipment-create');

      reqForm.reset();
      resetCatalog();
      loadList();
    }catch(e){
      alert(e.message || 'No se pudo crear');
    }
  });

  loadList();
  setInterval(loadList, 8000);
})();
</script>

<script src="/HelpDesk_EQF/assets/js/script.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/../../../template/footer.php'; ?>
