(() => {
    const modal = document.getElementById('announceModal');
    if (!modal) return;

    const open = () => {
        modal.style.display = 'flex';
        modal.classList.add('show');
    };

    const close = () => {
        modal.classList.remove('show');
        modal.style.display = 'none';
    };

    // Abrir/cerrar usando delegación (funciona en TODAS las páginas)
    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-open-announcement]') || e.target.closest('#btnOpenAnnouncement')) {
            e.preventDefault();
            open();
            return;
        }

        if (
            e.target.closest('[data-close-announcement]') ||
            e.target.closest('[data-cancel-announcement]') ||
            e.target.closest('#btnCloseAnnouncement') ||
            e.target.closest('#btnCancelAnnouncement')
        ) {
            e.preventDefault();
            close();
            return;
        }

        // click fuera del modal
        const inner = modal.querySelector('[data-ann-modal]');
        if (modal.classList.contains('show') && inner && !inner.contains(e.target) && e.target === modal) {
            close();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('show')) close();
    });

    // Enviar aviso (también global)
    document.getElementById('btnSendAnnouncement')?.addEventListener('click', async () => {
        const getVal = (id) => (document.getElementById(id)?.value ?? '').trim();

        const title = getVal('ann_title');
        const body = getVal('ann_body');

        if (!title || !body) {
            alert('Faltan campos obligatorios');
            return;
        }

        const fd = new FormData();
        fd.append('title', title);
        fd.append('body', body);
        fd.append('level', getVal('ann_level'));
        fd.append('target_area', getVal('ann_area'));
        fd.append('starts_at', getVal('ann_starts'));
        fd.append('ends_at', getVal('ann_ends'));

        try {
            const res = await fetch('/HelpDesk_EQF/modules/dashboard/admin/ajax/create_announcement.php', {
                method: 'POST',
                body: fd
            });

            const data = await res.json().catch(() => null);
            if (!data || !data.ok) {
                alert((data && data.msg) ? data.msg : 'Error al guardar aviso');
                return;
            }

            alert('Aviso creado correctamente ✅');

            // limpiar
            ['ann_title', 'ann_body', 'ann_starts', 'ann_ends'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });

            close();

            // opcional: si estás en una página que tiene lista de anuncios, refrescar
            if (typeof window.refreshAnnouncements === 'function') {
                window.refreshAnnouncements();
            }

        } catch (err) {
            console.error(err);
            alert('Error de red al crear aviso');
        }
    });

    // Exponer por si lo ocupas
    window.openAnnounceModal = open;
    window.closeAnnounceModal = close;
})();
