<?php
// /HelpDesk_EQF/modules/dashboard/admin/equipment.php
session_start();
require_once __DIR__ . '/../../../config/connectionBD.php';

include __DIR__ . '/../../../template/header.php';
include __DIR__ . '/../../../template/sidebar.php';

if (!isset($_SESSION['user_id'])) {
  header('Location: /HelpDesk_EQF/auth/login.php');
  exit;
}

$rol = (int)($_SESSION['user_rol'] ?? 0);
// Permitir solo Admin(2) y Analista(3)
if (!in_array($rol, [4], true)) {
  header('Location: /HelpDesk_EQF/modules/dashboard/user/user.php');
  exit;
}
?>
<link rel="stylesheet" href="/HelpDesk_EQF/assets/css/style.css?v=<?php echo time(); ?>">

<div class="user-main">
  <div class="user-main-inner">

    <div class="user-main-header">
      <div>
        <h1 class="login-brand">
          <span class="eqf-e">E</span><span class="eqf-q">Q</span><span class="eqf-f">F</span>
          · Requisiciones de equipo
        </h1>
        <p class="user-main-subtitle">
          Consulta requisiciones y marca el envío.
        </p>
      </div>
    </div>

    <div class="panel-card panel-card-wide">
      <div class="panel-card-head">
        <h2>Listado</h2>
        <span class="panel-muted" id="reqMeta">—</span>
      </div>

      <div class="panel-table-wrap">
        <table class="panel-table" id="reqTable">
          <thead>
            <tr>
              <th>ID</th>
              <th>Sucursal (email)</th>
              <th class="ta-right">Acciones</th>
              <th class="ta-right">Ver</th>
            </tr>
          </thead>
          <tbody id="reqTbody">
            <tr><td colspan="4" class="panel-empty">Cargando...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- MODAL VER -->
<div class="eqf-modal-backdrop" id="modal-equipment-admin-view">
  <div class="eqf-modal">
    <div class="eqf-modal-header">
      <h3>Detalle requisición</h3>
      <button class="eqf-modal-close" type="button" onclick="closeModal('modal-equipment-admin-view')">✕</button>
    </div>
    <div class="eqf-modal-body" id="adminViewBody">
      Cargando...
    </div>
  </div>
</div>

<script>
(function(){
  const tbody = document.getElementById('reqTbody');
  const meta  = document.getElementById('reqMeta');

  let lastHash = '';

  async function apiJson(url, opts={}){
    const r = await fetch(url, opts);
    const j = await r.json().catch(()=>({ok:false,msg:'Respuesta inválida'}));
    if (!r.ok) throw new Error(j.msg || 'Error');
    return j;
  }

  function esc(s){
    return String(s ?? '').replace(/[&<>"']/g, m => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[m]));
  }

  // Cambiar estatus usando el endpoint del mismo módulo (user/equipment.php)
  async function setStatus(id, status){
    return apiJson('/HelpDesk_EQF/modules/dashboard/user/equipment.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        action: 'set_status',
        request_id: String(id),
        status: String(status)
      })
    });
  }

  function render(rows){
    if (!rows || !rows.length){
      tbody.innerHTML = `<tr><td colspan="4" class="panel-empty">Sin requisiciones</td></tr>`;
      meta.textContent = '0 requisiciones';
      return;
    }

    meta.textContent = rows.length + ' requisiciones';

    tbody.innerHTML = rows.map(r=>{
      const st = String(r.status || '').toUpperCase();

      // SOLO cuando está VERIFICANDO se dejan acciones
      const acciones = (st === 'VERIFICANDO')
        ? `
          <button class="equipment-link-dhl" type="button" data-set="ENVIADO_DHL" data-id="${Number(r.id)}">Enviado DHL</button>
          &nbsp;|&nbsp;
          <button class="equipment-link-levic" type="button" data-set="ENVIADO_LEVIC" data-id="${Number(r.id)}">Enviado Levic</button>
          &nbsp;|&nbsp;
          <button class="equipment-link-corpo" type="button" data-set="ENTREGADO_CORPO" data-id="${Number(r.id)}">Entregado en corpo</button>
        `
        : `<span class="muted">—</span>`;

      return `
        <tr>
          <td>#${Number(r.id)}</td>
          <td>${esc(r.requester_email || '')}</td>
          <td class="ta-right">${acciones}</td>
          <td class="ta-right">
            <button class="task-link-blue" type="button" data-view="${Number(r.id)}">Ver</button>
          </td>
        </tr>
      `;
    }).join('');

    // binds ver
    tbody.querySelectorAll('button[data-view]').forEach(btn=>{
      btn.addEventListener('click', ()=> openDetail(btn.getAttribute('data-view')));
    });

    // binds set status
    tbody.querySelectorAll('button[data-set]').forEach(btn=>{
      btn.addEventListener('click', ()=> changeStatus(btn.getAttribute('data-id'), btn.getAttribute('data-set')));
    });
  }

  async function load(){
    try{
      const out = await apiJson('/HelpDesk_EQF/modules/dashboard/admin/ajax/equipment_list.php');
      const rows = out.rows || [];
      const hash = JSON.stringify(rows);
      if (hash === lastHash) return;
      lastHash = hash;
      render(rows);
    }catch(e){
      console.error(e);
    }
  }

  async function changeStatus(id, status){
    let msgConfirm = '';

    if (status === 'ENVIADO_DHL') msgConfirm = '¿Confirmas marcar como enviado por DHL?';
    if (status === 'ENVIADO_LEVIC') msgConfirm = '¿Confirmas marcar como enviado por Levic?';
    if (status === 'ENTREGADO_CORPO') msgConfirm = '¿Confirmas marcar como "Entregado en corpo"? Esto cerrará la requisición.';

    if (!confirm(msgConfirm)) return;

    try{
      const out = await setStatus(id, status);
      alert(out.msg || 'Actualizado');
      load();
    }catch(e){
      alert(e.message || 'No se pudo actualizar');
    }
  }

  async function openDetail(id){
    openModal('modal-equipment-admin-view');
    const box = document.getElementById('adminViewBody');
    box.textContent = 'Cargando...';

    try{
      const out = await apiJson('/HelpDesk_EQF/modules/dashboard/admin/ajax/equipment_detail.php?id=' + encodeURIComponent(id));
      box.innerHTML = out.html || 'Sin datos';
    }catch(e){
      box.textContent = e.message || 'No se pudo cargar';
    }
  }

  load();
  setInterval(load, 8000);
})();
</script>

<script src="/HelpDesk_EQF/assets/js/script.js?v=<?php echo time(); ?>"></script>
<?php include __DIR__ . '/../../../template/footer.php'; ?>
