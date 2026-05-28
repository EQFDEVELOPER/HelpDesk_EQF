<?php
session_start();
require_once __DIR__ . '/../../../config/connectionBD.php';

include __DIR__ . '/../../../template/header.php';
include __DIR__ . '/../../../template/sidebar.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /HelpDesk_EQF/auth/login.php');
    exit;
}

$userEmail = strtolower(trim($_SESSION['user_email'] ?? ''));

$allowedEmails = [
    'proyectos@eqf.mx',
    'aux.proyectos@eqf.mx'
];

if (!in_array($userEmail, $allowedEmails, true)) {
    header('Location: /HelpDesk_EQF/modules/dashboard/user/user.php');
    exit;
}
?>

<link rel="stylesheet" href="/HelpDesk_EQF/assets/css/style.css?v=<?php echo time(); ?>">
<style>
#modal-maintenance-analyst-view .eqf-modal-body {
    max-height: calc(100vh - 220px);
    overflow: auto;
}

/* 🎨 Rediseño Moderno de Botones */
.btn {
    display: inline-block;
    font-weight: 600;
    text-align: center;
    white-space: nowrap;
    vertical-align: middle;
    user-select: none;
    border: 1px solid transparent;
    padding: 10px 20px;
    font-size: 14px;
    line-height: 1.5;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease-in-out;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
}
.btn:active {
    transform: scale(0.98);
}
.btn-primary {
    background-color: #0056b3;
    color: #fff;
}
.btn-primary:hover {
    background-color: #004085;
}
.btn-secondary {
    background-color: #6c757d;
    color: #fff;
}
.btn-secondary:hover {
    background-color: #5a6268;
}
.btn-success {
    background-color: #28a745;
    color: #fff;
}
.btn-success:hover {
    background-color: #218838;
}
.btn-danger {
    background-color: #dc3545;
    color: #fff;
}
.btn-danger:hover {
    background-color: #c82333;
}

/* Estilos para las filas dinámicas */
.dynamic-row-label {
    font-size: 12px;
    font-weight: bold;
    color: #444;
    display: block;
    margin-bottom: 4px;
}
</style>

<div class="user-main">
    <div class="user-main-inner">

        <div class="user-main-header">
            <div>
                <h1 class="login-brand">
                    <span class="eqf-e">E</span><span class="eqf-q">Q</span><span class="eqf-f">F</span>
                    · Solicitudes de mantenimiento
                </h1>
                <p class="user-main-subtitle">
                    Consulta y administra solicitudes de mantenimiento.
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
                            <th>Solicitante</th>
                            <th>Asunto</th>
                            <th>Estatus</th>
                            <th class="ta-right">Acciones</th>
                            <th class="ta-right">Ver</th>
                        </tr>
                    </thead>
                    <tbody id="reqTbody">
                        <tr>
                            <td colspan="6" class="panel-empty">Cargando...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<div class="eqf-modal-backdrop" id="modal-maintenance-analyst-view">
    <div class="eqf-modal">
        <div class="eqf-modal-header">
            <h3>Detalle solicitud</h3>
            <button class="eqf-modal-close" type="button" onclick="closeModal('modal-maintenance-analyst-view')">✕</button>
        </div>
        <div class="eqf-modal-body" id="adminViewBody">Cargando...</div>
    </div>
</div>

<div class="eqf-modal-backdrop" id="modal-cancel-maintenance">
    <div class="eqf-modal" style="max-width:500px;">
        <div class="eqf-modal-header">
            <h3>Cancelar mantenimiento</h3>
            <button class="eqf-modal-close" type="button" onclick="closeModal('modal-cancel-maintenance')">✕</button>
        </div>
        <div class="eqf-modal-body">
            <input type="hidden" id="cancelRequestId">
            <label>Motivo de cancelación</label>
            <textarea id="cancelReason" class="form-control" rows="5" style="width:100%; resize:none;" placeholder="Escribe la razón..."></textarea>
            <div style="margin-top:20px; text-align:right; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-cancel-maintenance')">Cerrar</button>
                <button type="button" class="btn btn-danger" onclick="confirmCancelMaintenance()">Confirmar cancelación</button>
            </div>
        </div>
    </div>
</div>

<div class="eqf-modal-backdrop" id="modal-finish-maintenance">
    <div class="eqf-modal" style="max-width:800px;">
        <div class="eqf-modal-header">
            <h3>Finalizar mantenimiento</h3>
            <button class="eqf-modal-close" type="button" onclick="closeModal('modal-finish-maintenance')">✕</button>
        </div>
        <div class="eqf-modal-body">
            <div id="maintenanceWizardContent">Cargando...</div>
        </div>
    </div>
</div>

<script>
(function(){

    const tbody = document.getElementById('reqTbody');
    const meta  = document.getElementById('reqMeta');

    let lastHash = '';
    let currentMaintenanceId = null;

    let maintenanceData = {
        performed_by: '',
        external_company: '',
        maintenance_type: '',
        activities: '',
        materials: [], // Ahora estructurado como un array dinámico
        staff: []
    };

    // 🔍 apiJson Modificado para depurar errores ocultos de PHP en Consola (F12)
    async function apiJson(url, opts = {}) {
        const r = await fetch(url, opts);
        const text = await r.text(); // Leemos respuesta como texto plano primero
        
        let j;
        try {
            j = JSON.parse(text); // Intentamos transformarlo a JSON legítimo
        } catch(e) {
            console.error("❌ ERROR CRÍTICO DEL BACKEND PHP:", text);
            throw new Error('Respuesta inválida del servidor. Abre la consola de desarrollador (F12) para ver el error real de PHP.');
        }

        if (!r.ok) {
            throw new Error(j.msg || 'Error procesando la solicitud');
        }
        return j;
    }

    function esc(s){
        return String(s ?? '').replace(/[&<>"']/g, m => ({
            '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
        }[m]));
    }

    function getStatusClass(status){
        switch(status){
            case 'PENDIENTE': case 'ABIERTO': return 'status-pendiente';
            case 'EN_PROCESO': return 'status-proceso';
            case 'FINALIZADO': return 'status-finalizado';
            case 'CANCELADO': return 'status-cancelado';
            default: return '';
        }
    }

    function formatStatus(status){
        switch(status){
            case 'EN_PROCESO': return 'En proceso';
            case 'FINALIZADO': return 'Finalizado';
            case 'CANCELADO': return 'Cancelado';
            case 'PENDIENTE': return 'Pendiente';
            default: return status;
        }
    }

    async function setStatus(id, status){
        return apiJson(
            '/HelpDesk_EQF/modules/dashboard/user/ajax/maintenance_update_status.php',
            {
                method:'POST',
                headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ request_id: String(id), status: String(status) })
            }
        );
    }

    function render(rows){
        if (!rows || !rows.length){
            tbody.innerHTML = `<tr><td colspan="6" class="panel-empty">Sin solicitudes</td></tr>`;
            meta.textContent = '0 solicitudes';
            return;
        }

        meta.textContent = rows.length + ' solicitudes';

        tbody.innerHTML = rows.map(r => {
            const st = String(r.status || '').toUpperCase();
            let acciones = `<span class="muted">—</span>`;

            if (st !== 'FINALIZADO' && st !== 'CANCELADO') {
                acciones = `
                    <button class="action-link action-pendiente" type="button" data-set="PENDIENTE" data-id="${Number(r.id)}">PENDIENTE</button>
                    &nbsp;|&nbsp;
                    <button class="action-link action-process" type="button" data-set="EN_PROCESO" data-id="${Number(r.id)}">EN PROCESO</button>
                    &nbsp;|&nbsp;
                    <button class="action-link action-finish" type="button" data-set="FINALIZADO" data-id="${Number(r.id)}">FINALIZAR</button>
                    &nbsp;|&nbsp;
                    <button class="action-link action-cancel" type="button" data-set="CANCELADO" data-id="${Number(r.id)}">CANCELAR</button>
                `;
            }

            return `
                <tr>
                    <td>#${Number(r.id)}</td>
                    <td>${esc(r.requester_email || '')}</td>
                    <td>${esc(r.title || '')}</td>
                    <td>
                        <span class="status-badge ${getStatusClass(st)}">
                            <span class="status-dot"></span>${formatStatus(st)}
                        </span>
                    </td>
                    <td class="ta-right">${acciones}</td>
                    <td class="ta-right">
                        <button class="task-link-blue" type="button" data-view="${Number(r.id)}">Ver</button>
                    </td>
                </tr>
            `;
        }).join('');

        tbody.querySelectorAll('button[data-view]').forEach(btn => {
            btn.addEventListener('click', () => openDetail(btn.getAttribute('data-view')));
        });

        tbody.querySelectorAll('button[data-set]').forEach(btn => {
            btn.addEventListener('click', () => changeStatus(btn.getAttribute('data-id'), btn.getAttribute('data-set')));
        });
    }

    window.confirmCancelMaintenance = async function(){
        const id = document.getElementById('cancelRequestId').value;
        const reason = document.getElementById('cancelReason').value.trim();

        if (!reason){
            alert('Debes escribir un motivo.');
            return;
        }

        try{
            const out = await apiJson(
                '/HelpDesk_EQF/modules/dashboard/user/ajax/maintenance_update_status.php',
                {
                    method:'POST',
                    headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ request_id: String(id), status: 'CANCELADO', cancel_reason: reason })
                }
            );
            alert(out.msg || 'Solicitud cancelada');
            closeModal('modal-cancel-maintenance');
            load();
        }catch(e){
            alert(e.message);
        }
    }

    async function load(){
        try{
            const out = await apiJson('/HelpDesk_EQF/modules/dashboard/user/ajax/maintenance_list.php');
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
        if (status === 'PENDIENTE') msgConfirm = '¿Marcar solicitud como pendiente?';
        if (status === 'EN_PROCESO') msgConfirm = '¿Marcar solicitud como en proceso?';
        if (status === 'FINALIZADO') { openFinishMaintenanceModal(id); return; }
        if (status === 'CANCELADO') {
            document.getElementById('cancelRequestId').value = id;
            document.getElementById('cancelReason').value = '';
            openModal('modal-cancel-maintenance');
            return;
        }

        if (!confirm(msgConfirm)) return;

        try{
            const out = await setStatus(id, status);
            alert(out.msg || 'Actualizado');
            load();
        }catch(e){
            alert(e.message);
        }
    }

    function openFinishMaintenanceModal(id){
        currentMaintenanceId = id;
        maintenanceData = {
            performed_by: '', external_company: '', maintenance_type: '',
            activities: '', materials: [], staff: []
        };
        openModal('modal-finish-maintenance');
        renderMaintenanceStep1();
    }

    function renderMaintenanceStep1(){
        const box = document.getElementById('maintenanceWizardContent');
        box.innerHTML = `
            <div style="text-align:center; padding: 20px 0;">
                <h2 style="margin-bottom:10px;">¿Quién realizó el mantenimiento?</h2>
                <p style="margin-bottom:25px; color:#666;">Selecciona una opción</p>
                <div style="display:flex; gap:20px; justify-content:center;">
                    <button type="button" class="btn btn-primary" onclick="selectPerformedBy('VSP')">VSP</button>
                    <button type="button" class="btn btn-secondary" onclick="selectPerformedBy('EXTERNO')">EXTERNO</button>
                </div>
            </div>
        `;
    }

    window.selectPerformedBy = function(type){
        maintenanceData.performed_by = type;
        if (type === 'VSP') { renderVSPUploadStep(); return; }
        renderExternalCompanyStep();
    }

    function renderVSPUploadStep(){
        const box = document.getElementById('maintenanceWizardContent');
        box.innerHTML = `
            <div>
                <h2 style="margin-bottom:10px;">Adjuntar reporte técnico</h2>
                <p style="margin-bottom:20px; color:#666;">Adjunta el archivo del proveedor</p>
                <input type="file" id="vspReportFile" accept=".pdf,.doc,.docx,.xls,.xlsx" class="form-control" style="margin-bottom: 20px;">
                <div style="text-align:right;">
                    <button type="button" class="btn btn-success" onclick="finishVSPMaintenance()">Finalizar mantenimiento</button>
                </div>
            </div>
        `;
    }

    window.finishVSPMaintenance = async function(){
        const fileInput = document.getElementById('vspReportFile');
        if (!fileInput.files.length){ alert('Debes adjuntar un archivo.'); return; }

        const formData = new FormData();
        formData.append('request_id', currentMaintenanceId);
        formData.append('performed_by', 'VSP');
        formData.append('vspReportFile', fileInput.files[0]);

        try {
            const out = await apiJson('/HelpDesk_EQF/modules/dashboard/user/ajax/maintenance_finish.php', {
                method: 'POST', body: formData
            });
            alert(out.msg || 'Mantenimiento finalizado con éxito.');
            closeModal('modal-finish-maintenance');
            load();
        } catch(e) {
            alert(e.message);
        }
    }

    function renderExternalCompanyStep(){
        const box = document.getElementById('maintenanceWizardContent');
        box.innerHTML = `
            <div>
                <h2 style="margin-bottom:10px;">Razón social</h2>
                <p style="margin-bottom:20px; color:#666;">Ingresa la razón social del proveedor</p>
                <input type="text" id="externalCompany" class="form-control" style="text-transform:uppercase; margin-bottom: 20px;" placeholder="RAZÓN SOCIAL">
                <div style="text-align:right;">
                    <button type="button" class="btn btn-primary" onclick="saveExternalCompany()">Siguiente</button>
                </div>
            </div>
        `;
    }

    window.saveExternalCompany = function(){
        const value = document.getElementById('externalCompany').value.trim();
        if (!value){ alert('Debes escribir la razón social.'); return; }
        maintenanceData.external_company = value.toUpperCase();
        renderMaintenanceTypeStep();
    }

    function renderMaintenanceTypeStep(){
        const box = document.getElementById('maintenanceWizardContent');
        box.innerHTML = `
            <div style="text-align:center; padding: 20px 0;">
                <h2 style="margin-bottom:10px;">Tipo de mantenimiento</h2>
                <p style="margin-bottom:25px; color:#666;">Selecciona una opción</p>
                <div style="display:flex; gap:20px; justify-content:center;">
                    <button type="button" class="btn btn-primary" onclick="selectMaintenanceType('PREVENTIVO')">PREVENTIVO</button>
                    <button type="button" class="btn btn-secondary" onclick="selectMaintenanceType('CORRECTIVO')">CORRECTIVO</button>
                </div>
            </div>
        `;
    }

    window.selectMaintenanceType = function(type){
        maintenanceData.maintenance_type = type;
        renderActivitiesStep();
    }

    function renderActivitiesStep(){
        const box = document.getElementById('maintenanceWizardContent');
        box.innerHTML = `
            <div>
                <h2 style="margin-bottom:10px;">Actividades realizadas</h2>
                <textarea id="activitiesInput" class="form-control" rows="8" style="width:100%; resize:none; text-transform:uppercase; margin-bottom:20px;" placeholder="DESCRIBE LAS ACTIVIDADES"></textarea>
                <div style="text-align:right;">
                    <button type="button" class="btn btn-primary" onclick="saveActivitiesStep()">Siguiente</button>
                </div>
            </div>
        `;
    }

    window.saveActivitiesStep = function(){
        const value = document.getElementById('activitiesInput').value.trim();
        if (!value){ alert('Debes escribir las actividades.'); return; }
        maintenanceData.activities = value.toUpperCase();
        renderMaterialsStep();
    }

    // 📦 PASO NUEVO: MULTI-MATERIALES DINÁMICOS
    function renderMaterialsStep(){
        const box = document.getElementById('maintenanceWizardContent');
        box.innerHTML = `
            <div>
                <h2 style="margin-bottom:20px;">Adquisición de materiales</h2>
                <div id="materialsContainer"></div>
                <div style="margin-top:15px; margin-bottom: 25px;">
                    <button type="button" class="btn btn-secondary" onclick="addMaterialRow()">+ Agregar material</button>
                </div>
                <div style="text-align:right;">
                    <button type="button" class="btn btn-primary" onclick="saveMaterialsStep()">Siguiente</button>
                </div>
            </div>
        `;
        addMaterialRow(); // Añadir la primera fila por defecto
    }

    window.addMaterialRow = function(){
        const container = document.getElementById('materialsContainer');
        const row = document.createElement('div');
        row.style.marginBottom = '15px';
        
        let qtyOptions = '';
        for(let i = 1; i <= 20; i++) qtyOptions += `<option value="${i}">${i}</option>`;

        row.innerHTML = `
            <div style="display:grid; grid-template-columns:90px 130px 1fr; gap:12px; align-items: end;">
                <div>
                    <label class="dynamic-row-label">Cantidad</label>
                    <select class="form-control mat-qty">${qtyOptions}</select>
                </div>
                <div>
                    <label class="dynamic-row-label">Unidad</label>
                    <input type="text" class="form-control mat-unit" style="text-transform:uppercase;" placeholder="PZA">
                </div>
                <div>
                    <label class="dynamic-row-label">Descripción</label>
                    <input type="text" class="form-control mat-desc" style="text-transform:uppercase;" placeholder="DESCRIPCIÓN DEL MATERIAL">
                </div>
            </div>
        `;
        container.appendChild(row);
    }

    window.saveMaterialsStep = function(){
        const qtys = document.querySelectorAll('.mat-qty');
        const units = document.querySelectorAll('.mat-unit');
        const descs = document.querySelectorAll('.mat-desc');
        const materials = [];

        for(let i = 0; i < qtys.length; i++){
            const qty = qtys[i].value;
            const unit = units[i].value.trim().toUpperCase();
            const desc = descs[i].value.trim().toUpperCase();

            if (unit || desc) { // Solo guarda si llenaron información en la fila
                materials.push({ qty, unit, desc });
            }
        }

        maintenanceData.materials = materials;
        renderStaffStep();
    }

    function renderStaffStep(){
        const box = document.getElementById('maintenanceWizardContent');
        box.innerHTML = `
            <div>
                <h2 style="margin-bottom:20px;">Personal que realizó el mantenimiento</h2>
                <div id="staffContainer"></div>
                <div style="margin-top:15px; margin-bottom: 25px;">
                    <button type="button" class="btn btn-secondary" onclick="addStaffRow()">+ Agregar persona</button>
                </div>
                <div style="text-align:right;">
                    <button type="button" class="btn btn-success" onclick="finishExternalMaintenance()">Finalizar mantenimiento</button>
                </div>
            </div>
        `;
        addStaffRow();
    }

    window.addStaffRow = function(){
        const container = document.getElementById('staffContainer');
        const row = document.createElement('div');
        row.style.marginBottom = '15px';
        row.innerHTML = `
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; align-items: end;">
                <div>
                    <label class="dynamic-row-label">Nombre completo</label>
                    <input type="text" class="form-control staff-name" placeholder="Nombre completo" style="text-transform:uppercase;">
                </div>
                <div>
                    <label class="dynamic-row-label">Puesto</label>
                    <input type="text" class="form-control staff-position" placeholder="Puesto" style="text-transform:uppercase;">
                </div>
            </div>
        `;
        container.appendChild(row);
    }

    window.finishExternalMaintenance = async function(){
        const names = document.querySelectorAll('.staff-name');
        const positions = document.querySelectorAll('.staff-position');
        const staff = [];

        for(let i = 0; i < names.length; i++){
            const name = names[i].value.trim();
            const position = positions[i].value.trim();
            if (name && position){
                staff.push({ name: name.toUpperCase(), position: position.toUpperCase() });
            }
        }

        if (staff.length === 0) {
            alert('Debes registrar al menos a una persona encargada del mantenimiento.');
            return;
        }

        maintenanceData.staff = staff;

        const formData = new FormData();
        formData.append('request_id', currentMaintenanceId);
        formData.append('performed_by', 'EXTERNO');
        formData.append('external_company', maintenanceData.external_company);
        formData.append('maintenance_type', maintenanceData.maintenance_type);
        formData.append('activities', maintenanceData.activities);
        // Enviamos ambos arreglos serializados estructuradamente en JSON Strings 🚀
        formData.append('materials', JSON.stringify(maintenanceData.materials));
        formData.append('staff', JSON.stringify(maintenanceData.staff));

        try {
            const out = await apiJson('/HelpDesk_EQF/modules/dashboard/user/ajax/maintenance_finish.php', {
                method: 'POST', body: formData
            });
            alert(out.msg || 'Mantenimiento externo registrado y finalizado con éxito.');
            closeModal('modal-finish-maintenance');
            load();
        } catch(e) {
            alert(e.message);
        }
    }

    async function openDetail(id){
        openModal('modal-maintenance-analyst-view');
        const box = document.getElementById('adminViewBody');
        box.textContent = 'Cargando...';

        try{
            const out = await apiJson(
                '/HelpDesk_EQF/modules/dashboard/user/ajax/maintenance_detail.php?id=' + encodeURIComponent(id)
            );
            box.innerHTML = out.html || 'Sin datos';
        }catch(e){
            box.textContent = e.message;
        }
    }

    load();
    setInterval(load, 8000);

})();
</script>
<script src="/HelpDesk_EQF/assets/js/script.js?v=<?php echo time(); ?>"></script>
<?php include __DIR__ . '/../../../template/footer.php'; ?>