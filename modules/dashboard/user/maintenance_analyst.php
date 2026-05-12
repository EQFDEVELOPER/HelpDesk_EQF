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
                            <td colspan="6" class="panel-empty">
                                Cargando...
                            </td>
                        </tr>
                    </tbody>

                </table>

            </div>

        </div>

    </div>
</div>

<!-- MODAL VER -->
<div class="eqf-modal-backdrop" id="modal-maintenance-analyst-view">

    <div class="eqf-modal">

        <div class="eqf-modal-header">

            <h3>Detalle solicitud</h3>

            <button
                class="eqf-modal-close"
                type="button"
                onclick="closeModal('modal-maintenance-analyst-view')">
                ✕
            </button>

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

    async function apiJson(url, opts = {}) {

        const r = await fetch(url, opts);

        const j = await r.json().catch(() => ({
            ok:false,
            msg:'Respuesta inválida'
        }));

        if (!r.ok) {
            throw new Error(j.msg || 'Error');
        }

        return j;
    }

    function esc(s){
        return String(s ?? '').replace(/[&<>"']/g, m => ({
            '&':'&amp;',
            '<':'&lt;',
            '>':'&gt;',
            '"':'&quot;',
            "'":'&#039;'
        }[m]));
    }
function getStatusClass(status){

    switch(status){

        case 'PENDIENTE':
        case 'ABIERTO':
            return 'status-pendiente';

        case 'EN_PROCESO':
            return 'status-proceso';

        case 'FINALIZADO':
            return 'status-finalizado';

        case 'CANCELADO':
            return 'status-cancelado';

        default:
            return '';
    }
}

function formatStatus(status){

    switch(status){

        case 'EN_PROCESO':
            return 'En proceso';

        case 'FINALIZADO':
            return 'Finalizado';

        case 'CANCELADO':
            return 'Cancelado';

        case 'PENDIENTE':
            return 'Pendiente';

        default:
            return status;
    }
}
    async function setStatus(id, status){

        return apiJson(
            '/HelpDesk_EQF/modules/dashboard/user/ajax/maintenance_update_status.php',
            {
                method:'POST',

                headers:{
                    'Content-Type':'application/x-www-form-urlencoded'
                },

                body: new URLSearchParams({
                    request_id: String(id),
                    status: String(status)
                })
            }
        );
    }

    function render(rows){

        if (!rows || !rows.length){

            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="panel-empty">
                        Sin solicitudes
                    </td>
                </tr>
            `;

            meta.textContent = '0 solicitudes';

            return;
        }

        meta.textContent = rows.length + ' solicitudes';

        tbody.innerHTML = rows.map(r => {

            const st = String(r.status || '').toUpperCase();

            let acciones = `<span class="muted">—</span>`;

            if (st !== 'FINALIZADO' && st !== 'CANCELADO') {

                acciones = `
                    <button
                        class="action-link action-pendiente"
                        type="button"
                        data-set="PENDIENTE"
                        data-id="${Number(r.id)}">
                        PENDIENTE
                    </button>

                    &nbsp;|&nbsp;

                    <button
                        class="action-link action-process"
                        type="button"
                        data-set="EN_PROCESO"
                        data-id="${Number(r.id)}">
                        EN PROCESO
                    </button>

                    &nbsp;|&nbsp;

                    <button
    class="action-link action-finish"
                        type="button"
                        data-set="FINALIZADO"
                        data-id="${Number(r.id)}">
                        FINALIZAR
                    </button>

                    &nbsp;|&nbsp;

                    <button
                        class="action-link action-cancel"
                        type="button"
                        data-set="CANCELADO"
                        data-id="${Number(r.id)}">
                        CANCELAR
                    </button>
                `;
            }

            return `
                <tr>

    <td>
        #${Number(r.id)}
    </td>

    <td>
        ${esc(r.requester_email || '')}
    </td>

    <td>
        ${esc(r.title || '')}
    </td>

    <td>

    <span class="status-badge ${getStatusClass(st)}">

        <span class="status-dot"></span>

        ${formatStatus(st)}

    </span>

</td>

    <td class="ta-right">
        ${acciones}
    </td>

    <td class="ta-right">

        <button
            class="task-link-blue"
            type="button"
            data-view="${Number(r.id)}">
            Ver
        </button>

    </td>

</tr>
            `;

        }).join('');

        // VER DETALLE
        tbody.querySelectorAll('button[data-view]').forEach(btn => {

            btn.addEventListener('click', () => {
                openDetail(btn.getAttribute('data-view'));
            });

        });

        // CAMBIAR STATUS
        tbody.querySelectorAll('button[data-set]').forEach(btn => {

            btn.addEventListener('click', () => {

                changeStatus(
                    btn.getAttribute('data-id'),
                    btn.getAttribute('data-set')
                );

            });

        });
    }

    async function load(){

        try{

            const out = await apiJson(
                '/HelpDesk_EQF/modules/dashboard/user/ajax/maintenance_list.php'
            );

            const rows = out.rows || [];

            const hash = JSON.stringify(rows);

            if (hash === lastHash) {
                return;
            }

            lastHash = hash;

            render(rows);

        }catch(e){

            console.error(e);

        }
    }

    async function changeStatus(id, status){

        let msgConfirm = '';

        if (status === 'PENDIENTE') {
            msgConfirm = '¿Marcar solicitud como pendiente?';
        }

        if (status === 'EN_PROCESO') {
            msgConfirm = '¿Marcar solicitud como en proceso?';
        }

        if (status === 'FINALIZADO') {
            msgConfirm = '¿Finalizar solicitud?';
        }

        if (status === 'CANCELADO') {
            msgConfirm = '¿Cancelar solicitud?';
        }

        if (!confirm(msgConfirm)) {
            return;
        }

        try{

            const out = await setStatus(id, status);

            alert(out.msg || 'Actualizado');

            load();

        }catch(e){

            alert(e.message || 'No se pudo actualizar');

        }
    }

    async function openDetail(id){

        openModal('modal-maintenance-analyst-view');

        const box = document.getElementById('adminViewBody');

        box.textContent = 'Cargando...';

        try{

            const out = await apiJson(
                '/HelpDesk_EQF/modules/dashboard/user/ajax/maintenance_detail.php?id='
                + encodeURIComponent(id)
            );

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