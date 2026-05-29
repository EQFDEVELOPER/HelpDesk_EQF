// ========================================
// INSTANCIA GLOBAL DE LA DATATABLE
// ========================================
let activitiesTable = null;

// ========================================
// CARGAR HISTORIAL DE ACTIVIDADES (Global)
// ========================================
async function loadActivitiesHistory() {
    try {
        const response = await fetch(
            '/HelpDesk_EQF/modules/dashboard/analyst/activities/activities_list.php'
        );

        const data = await response.json();
        if (!data.ok) return;

        // Formatear las filas para que DataTables las entienda nativamente
        const rows = (data.activities || []).map(item => {
            return [
                '#' + item.id,
                item.created_at || '—',
                `<strong>${item.total_activities}</strong>`,
                `
                <div class="activity-actions">
                    <button
                        type="button"
                        class="panel-link"
                        style="background:none; border:none; color:#14378A; cursor:pointer; font-weight:bold;"
                        onclick="openActivitiesDetail(${item.id})"
                    >
                        Ver
                    </button>
                </div>
                `
            ];
        });

        // Inicialización o Refresco usando la API limpia de DataTables
        if (!activitiesTable) {
            activitiesTable = $('#activitiesTable').DataTable({
                pageLength: 5,
                order: [[0, 'desc']],
                destroy: true,
                language: {
                    search: "Buscar:",
                    lengthMenu: "Mostrar _MENU_",
                    info: "Mostrando _START_ a _END_ de _TOTAL_",
                    paginate: { previous: "Anterior", next: "Siguiente" },
                    zeroRecords: "Sin registros de actividades hoy"
                }
            });
        }

        activitiesTable.clear();
        activitiesTable.rows.add(rows);
        activitiesTable.draw(false);

    } catch (err) {
        console.error("Error cargando el historial:", err);
    }
}

// ========================================
// MODAL DETALLE DE ACTIVIDADES (Global)
// ========================================
window.openActivitiesDetail = async function (id) {
    const modal = document.getElementById('activities-detail-modal');
    if (!modal) return;

    modal.classList.add('is-visible');
    const content = document.getElementById('activitiesDetailContent');

    content.innerHTML = `<div style="opacity:.7;">Cargando...</div>`;

    try {
        const response = await fetch(
            '/HelpDesk_EQF/modules/dashboard/analyst/activities/activities_list.php?id=' + id
        );

        const data = await response.json();

        if (!data.ok) {
            content.innerHTML = `<div style="color:#b91c1c;font-weight:700;">No se pudo cargar la información.</div>`;
            return;
        }

        const acts = data.activities || [];
        if (!acts.length) {
            content.innerHTML = `<div style="opacity:.7;">Sin actividades registradas.</div>`;
            return;
        }

        content.innerHTML = `
            <div style="display:flex; flex-direction:column; gap:10px;">
                ${acts.map((a, i) => `
                    <div style="padding:12px; border:1px solid #e5e7eb; border-radius:12px; background:#fff;">
                        <strong style="color:#14378A;">Actividad ${i + 1}</strong>
                        <div style="margin-top:6px; color:#333; line-height:1.4;">${a.activity}</div>
                    </div>
                `).join('')}
            </div>
        `;

    } catch (err) {
        console.error(err);
        content.innerHTML = `<div style="color:#b91c1c;font-weight:700;">Error interno.</div>`;
    }
};

// ========================================
// MANEJO DEL DOM Y EVENTOS DINÁMICOS
// ========================================
document.addEventListener('DOMContentLoaded', function () {

    const activitiesContainer = document.getElementById('activities-container');
    const addActivityBtn = document.getElementById('addActivityBtn');
    const activityCounter = document.getElementById('activity-counter');
    const activitiesForm = document.getElementById('activitiesForm');

    let totalActivities = 1;

    function updateActivityCounter() {
        if (activityCounter) {
            activityCounter.textContent = totalActivities;
        }
    }

    function addActivityRow(value = '') {
        totalActivities++;

        const row = document.createElement('div');
        row.className = 'activity-row';
        row.style.marginTop = '10px';

        row.innerHTML = `
            <input
                type="text"
                name="activities[]"
                class="activity-input"
                placeholder="Escribe una actividad..."
                value="${value}"
                required
            >
            <button
                type="button"
                class="btn-remove-activity"
            >
                ✕
            </button>
        `;

        activitiesContainer.appendChild(row);
        updateActivityCounter();
    }

    // Delegación de eventos para eliminar filas
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-remove-activity');
        if (!btn) return;

        const rows = document.querySelectorAll('.activity-row');
        if (rows.length <= 1) return;

        btn.closest('.activity-row').remove();
        totalActivities--;
        updateActivityCounter();
    });

    // CORRECCIÓN AQUÍ: Evita pasar el objeto PointerEvent al input
    if (addActivityBtn) {
        addActivityBtn.addEventListener('click', () => addActivityRow());
    }

    // Guardado del formulario vía AJAX
    if (activitiesForm) {
        activitiesForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const formData = new FormData(activitiesForm);

            try {
                const response = await fetch(
                    '/HelpDesk_EQF/modules/dashboard/analyst/activities/activities_save.php',
                    {
                        method: 'POST',
                        body: formData
                    }
                );

                const data = await response.json();

                if (!data.ok) {
                    alert(data.msg || 'Error al guardar');
                    return;
                }

                alert('Actividades guardadas y notificadas al Gerente de TI con éxito.');

                // Limpiar el contenedor y restaurar la primera fila limpia
                activitiesContainer.innerHTML = `
                    <div class="activity-row">
                        <input
                            type="text"
                            name="activities[]"
                            class="activity-input"
                            placeholder="Escribe una actividad..."
                            required
                        >
                    </div>
                `;

                totalActivities = 1;
                updateActivityCounter();

                // Recargar el historial en la tabla de forma inmediata
                loadActivitiesHistory();

            } catch (err) {
                console.error(err);
                alert('Error al intentar guardar las actividades.');
            }
        });
    }

    // Ejecutar la carga inicial del historial al montar el componente
    if (document.getElementById('activitiesTable')) {
        loadActivitiesHistory();
    }
});