/* ============================================
   SCRIPTS GLOBALES · MESA DE AYUDA EQF
   Archivo: assets/js/script.js
============================================ */


/* ============================================
   DOM READY #1: MODALES + DIRECTORIO + ALERTAS
============================================ */
document.addEventListener('DOMContentLoaded', () => {

  /* ============================================
     MODALES GLOBALES
  ============================================ */
  window.openModal = function (id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('show');
  };

  window.closeModal = function (id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('show');
  };

  /* ============================================
     DIRECTORIO DE USUARIOS (solo si existe)
  ============================================ */
  const directoryTableEl = document.querySelector('.directory-table');
  let selectedRow = null;
  let currentArea = 'ALL';

  if (directoryTableEl) {

    function sortDirectoryRows(mode) {
      const tbody = directoryTableEl.querySelector('tbody');
      if (!tbody) return;

      const rowsArr = Array.from(tbody.querySelectorAll('.directory-row'));

      rowsArr.sort((a, b) => {
        const aLast  = (a.dataset.last  || '').toLowerCase();
        const bLast  = (b.dataset.last  || '').toLowerCase();
        const aName  = (a.dataset.name  || '').toLowerCase();
        const bName  = (b.dataset.name  || '').toLowerCase();
        const aEmail = (a.dataset.email || '').toLowerCase();
        const bEmail = (b.dataset.email || '').toLowerCase();

        if (mode === 'email') {
          if (aEmail < bEmail) return -1;
          if (aEmail > bEmail) return 1;
          return 0;
        } else {
          if (aLast < bLast) return -1;
          if (aLast > bLast) return 1;
          if (aName < bName) return -1;
          if (aName > bName) return 1;
          return 0;
        }
      });

      rowsArr.forEach(row => tbody.appendChild(row));
    }

    // Selección de fila (delegado)
    const tbody = directoryTableEl.querySelector('tbody');
    if (tbody) {
      tbody.addEventListener('click', (e) => {
        const row = e.target.closest('.directory-row');
        if (!row) return;

        if (selectedRow) selectedRow.classList.remove('row-selected');
        selectedRow = row;
        row.classList.add('row-selected');
      });
    }

    // Búsqueda + filtro por área
    const searchInput = document.getElementById('searchUser');
    const filterChips = document.querySelectorAll('.chip-filter');

    function applyFilter() {
      const term = (searchInput && searchInput.value ? searchInput.value : '')
        .trim()
        .toLowerCase();

      document.querySelectorAll('.directory-row').forEach(row => {
        const sap  = (row.dataset.sap  || '').toLowerCase();
        const name = (row.dataset.name || '').toLowerCase();
        const last = (row.dataset.last || '').toLowerCase();
        const area = (row.dataset.area || '').toLowerCase();

        const matchTerm =
          term === '' ||
          sap.includes(term) ||
          name.includes(term) ||
          last.includes(term);

        const matchArea =
          currentArea === 'ALL' ||
          area === currentArea.toLowerCase();

        row.style.display = (matchTerm && matchArea) ? '' : 'none';
      });
    }

    if (searchInput) {
      searchInput.addEventListener('input', applyFilter);
    }

    filterChips.forEach(chip => {
      chip.addEventListener('click', () => {
        filterChips.forEach(c => c.classList.remove('chip-active'));
        chip.classList.add('chip-active');

        currentArea = chip.dataset.area || 'ALL';

        if (currentArea === 'Sucursal') sortDirectoryRows('email');
        else sortDirectoryRows('name');

        if (selectedRow) {
          selectedRow.classList.remove('row-selected');
          selectedRow = null;
        }

        applyFilter();
      });
    });

    /* ============================================
       Acciones CRUD
    ============================================ */

    window.handleDeleteUser = function () {
      if (!selectedRow) {
        alert('Primero selecciona un usuario en la tabla.');
        return;
      }

      const fullName = (selectedRow.dataset.name || '') + ' ' + (selectedRow.dataset.last || '');
      if (!confirm('¿Eliminar al usuario: ' + fullName + '?')) return;

      const id = selectedRow.dataset.id;
      const deleteForm = document.getElementById('deleteForm');
      const deleteInput = document.getElementById('delete_id');

      if (!deleteForm || !deleteInput) {
        console.error('No se encontró el formulario de eliminación.');
        return;
      }

      deleteInput.value = id;
      deleteForm.submit();
    };

    window.openEditModal = function () {
      if (!selectedRow) {
        alert('Primero selecciona un usuario en la tabla.');
        return;
      }

      const id    = selectedRow.dataset.id;
      const sap   = selectedRow.dataset.sap   || '';
      const name  = selectedRow.dataset.name  || '';
      const last  = selectedRow.dataset.last  || '';
      const area  = selectedRow.dataset.area  || '';
      const email = selectedRow.dataset.email || '';
      const rol   = selectedRow.dataset.rol   || '';

      const idField    = document.getElementById('edit_id');
      const sapField   = document.getElementById('edit_sap');
      const nameField  = document.getElementById('edit_name');
      const lastField  = document.getElementById('edit_last');
      const areaField  = document.getElementById('edit_area');
      const emailField = document.getElementById('edit_email');
      const rolField   = document.getElementById('edit_rol');

      if (!idField || !sapField || !nameField || !lastField || !areaField || !emailField || !rolField) {
        console.error('No se encontraron los campos del formulario de edición.');
        return;
      }

      idField.value    = id;
      sapField.value   = sap;
      nameField.value  = name;
      lastField.value  = last;
      areaField.value  = area;
      emailField.value = email;
      rolField.value   = rol;

      openModal('modal-edit-user');
    };
  }

  /* ============================================
     ALERTAS CRUD
  ============================================ */
  (function initCrudAlerts() {
    const container = document.getElementById('eqf-alert-container');
    if (!container) return;

    setTimeout(() => {
      container.classList.add('eqf-alert-hide');
      setTimeout(() => {
        if (container.parentNode) container.parentNode.removeChild(container);
      }, 350);
    }, 2000);
  })();

});


/* ========== DASHBOARD USUARIO / CAPSULia ========== */
document.addEventListener('DOMContentLoaded', () => {
  const problemSelect   = document.getElementById('problema');
  const qaButtons       = document.querySelectorAll('.user-capsulia-qa-btn');
  const chatLog         = document.getElementById('capsuliaChatLog');
  const input           = document.getElementById('capsuliaInput');
  const sendBtn         = document.getElementById('capsuliaSend');
  const closeBtn        = document.querySelector('.user-capsulia-close');
  const capsuliaPanel   = document.querySelector('.user-capsulia');

  function addChatMessage(text, from = 'user') {
    if (!chatLog) return;
    const row = document.createElement('div');
    row.className = 'user-capsulia-chat-msg ' + from;
    const span = document.createElement('span');
    span.textContent = text;
    row.appendChild(span);
    chatLog.appendChild(row);
    chatLog.scrollTop = chatLog.scrollHeight;
  }

  qaButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const val = btn.dataset.problem || btn.textContent.trim();
      if (problemSelect) problemSelect.value = val;
      addChatMessage(val, 'user');
    });
  });

  if (sendBtn && input) {
    const send = () => {
      const text = input.value.trim();
      if (!text) return;
      addChatMessage(text, 'user');
      input.value = '';

      setTimeout(() => {
        addChatMessage(
          'He recibido tu mensaje. Si lo deseas, describe más a detalle y crea el ticket con el botón "Enviar ticket".',
          'bot'
        );
      }, 400);
    };

    sendBtn.addEventListener('click', send);
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        e.preventDefault();
        send();
      }
    });
  }

  if (closeBtn && capsuliaPanel) {
    closeBtn.addEventListener('click', () => {
      capsuliaPanel.classList.toggle('is-closed');
    });
  }
});


/* ============================================
   CAMBIO DE ESTATUS EN MIS TICKETS (GLOBAL)
   (Debe estar FUERA del modal, para no romperlo)
============================================ */
document.addEventListener('change', function (e) {
  const select = e.target.closest('.ticket-status-select');
  if (!select) return;

  const ticketId  = select.dataset.ticketId;
  const newStatus = select.value;
  if (!ticketId || !newStatus) return;

  // pintar clase inmediata
  const classes = select.className.split(' ').filter(c => !c.startsWith('status-'));
  classes.push('status-' + newStatus);
  select.className = classes.join(' ');

  fetch('/HelpDesk_EQF/modules/ticket/update_status.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'ticket_id=' + encodeURIComponent(ticketId) +
          '&estado='   + encodeURIComponent(newStatus)
  })
  .then(async (r) => {
    const raw = await r.text();
    let data;
    try {
      data = JSON.parse(raw);
    } catch (e) {
      console.error('Respuesta no es JSON válido en update_status:', raw);
      alert('Error al actualizar el estatus.');
      return;
    }

    if (!data.ok) {
      alert(data.msg || 'No se pudo actualizar el estatus.');
      return;
    }

    select.value = data.estado;

    // quitar clases anteriores status-*
    select.className = select.className
      .split(' ')
      .filter(c => !c.startsWith('status-'))
      .join(' ');

    select.classList.add('status-' + data.estado);

    if (typeof showTicketToast === 'function') {
      showTicketToast('Estatus del ticket #' + ticketId + ' actualizado a "' + data.estado_label + '".');
    }
  })
  .catch(err => {
    console.error('Error actualizando estatus:', err);
    alert('Error al actualizar el estatus.');
  });
});


/* ============================================
   Helper opcional (si lo usas en otras partes)
============================================ */
function addIncomingTicketRow(ticket) {
  if (!ticket || !ticket.id) return;

  const prioridadRaw   = (ticket.prioridad || 'media').toLowerCase();
  const prioridadLabel = prioridadRaw === 'alta'   ? 'Alta'
                       : prioridadRaw === 'baja'   ? 'Baja'
                       : prioridadRaw === 'critica' || prioridadRaw === 'crítica' ? 'Crítica'
                       : 'Media';

  const prioridadHtml = `
    <span class="priority-pill priority-${prioridadRaw}">
      ${prioridadLabel}
    </span>
  `;

  const rowData = [
    ticket.id,
    ticket.fecha || '',
    ticket.usuario || '',
    ticket.problema || '',
    prioridadHtml,
    ticket.descripcion || '',
    `<button type="button" class="btn-assign-ticket" data-ticket-id="${ticket.id}">Asignar</button>`
  ];

  // incomingDT puede existir en otras vistas
  if (typeof incomingDT !== 'undefined' && incomingDT) {
    incomingDT.row.add(rowData).draw(false);
  } else {
    const tbody = document.querySelector('#incomingTable tbody');
    if (!tbody) return;

    const tr = document.createElement('tr');
    tr.setAttribute('data-ticket-id', ticket.id);
    tr.innerHTML = `
      <td>${rowData[0]}</td>
      <td>${rowData[1]}</td>
      <td>${rowData[2]}</td>
      <td>${rowData[3]}</td>
      <td>${rowData[4]}</td>
      <td>${rowData[5]}</td>
      <td>${rowData[6]}</td>
    `;
    tbody.prepend(tr);
  }
}


/* ============================================
   MODAL CREAR TICKET (USUARIO)
============================================ */
document.addEventListener('DOMContentLoaded', function () {
  const noJefeCheckbox    = document.getElementById('noJefe');
  const sapDisplay        = document.getElementById('sapDisplay');
  const nombreDisplay     = document.getElementById('nombreDisplay');
  const emailDisplay      = document.getElementById('emailDisplay');
  const sapValueHidden    = document.getElementById('sapValue');
  const nombreValueHidden = document.getElementById('nombreValue');
  const problemaSelect    = document.getElementById('problemaSelect');
  const adjuntoContainer  = document.getElementById('adjuntoContainer');
  const ticketForm        = document.getElementById('ticketForm');
  const areaSoporte       = document.getElementById('areaSoporte');

  const prioridadDisplay  = document.getElementById('prioridadDisplay');
  const prioridadHidden   = document.getElementById('prioridadValue');

  // Si no está el formulario de ticket, no hacemos nada (este JS se usa en otras vistas)
  if (!sapDisplay || !nombreDisplay || !emailDisplay || !sapValueHidden || !nombreValueHidden || !ticketForm) {
    return;
  }

  if (adjuntoContainer) adjuntoContainer.style.display = 'block';

  const originalSap    = sapDisplay.value;
  const originalNombre = nombreDisplay.value;
  const originalEmail  = emailDisplay.value;

  sapDisplay.disabled = true;
  nombreDisplay.disabled = true;
  sapDisplay.style.backgroundColor = '#e5e5e5';
  nombreDisplay.style.backgroundColor = '#e5e5e5';
  emailDisplay.style.backgroundColor = '#e5e5e5';

  // Checkbox "No soy jefe de sucursal"
  if (noJefeCheckbox) {
    noJefeCheckbox.addEventListener('change', function () {
      if (this.checked) {
        sapDisplay.disabled = false;
        nombreDisplay.disabled = false;

        sapDisplay.value = '';
        nombreDisplay.value = '';

        sapDisplay.style.backgroundColor = '#ffffff';
        nombreDisplay.style.backgroundColor = '#ffffff';
      } else {
        sapDisplay.disabled = true;
        nombreDisplay.disabled = true;

        sapDisplay.value = originalSap;
        nombreDisplay.value = originalNombre;

        sapDisplay.style.backgroundColor = '#e5e5e5';
        nombreDisplay.style.backgroundColor = '#e5e5e5';

        sapValueHidden.value    = originalSap;
        nombreValueHidden.value = originalNombre;
      }
    });
  }

  /* -----------------------------
     Área de soporte → lista de problemas
  ----------------------------- */
  if (areaSoporte && problemaSelect) {

 async function fetchProblemas(areaRaw) {
  const area = (areaRaw || '').toUpperCase().trim();
  if (!area) return [];

  const res = await fetch(
    `/HelpDesk_EQF/modules/dashboard/user/get_problems.php?area=${encodeURIComponent(area)}`,
    { cache: 'no-store' }
  );

  // No confíes en content-type: intenta leer y parsear
  const text = await res.text();
  let data = null;
  try { data = JSON.parse(text); } catch (e) { data = null; }

  if (!data || !data.ok) {
    console.warn('get_problems.php respondió algo que no es JSON OK:', text);
    return [];
  }

  return Array.isArray(data.items) ? data.items : [];
}

    function resetProblemas() {
      problemaSelect.innerHTML = '';
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'Selecciona primero un área de soporte';
      problemaSelect.appendChild(opt);
      problemaSelect.value = '';

      if (prioridadDisplay && prioridadHidden) {
        prioridadDisplay.value = 'Media';
        prioridadHidden.value  = 'media';
      }
    }

    async function fillProblemas(areaRaw) {
      const area = (areaRaw || '').toUpperCase().trim();

      problemaSelect.innerHTML = '';
      problemaSelect.disabled = true;

      if (!area) {
        resetProblemas();
        problemaSelect.disabled = false;
        return;
      }

      const loading = document.createElement('option');
      loading.value = '';
      loading.textContent = 'Cargando...';
      problemaSelect.appendChild(loading);

      let items = [];
      try {
        items = await fetchProblemas(area);
      } catch (e) {
        items = [];
      }

      problemaSelect.innerHTML = '';

      if (!items.length) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = 'No hay problemas para esta área';
        problemaSelect.appendChild(opt);
        problemaSelect.value = '';
        problemaSelect.disabled = false;

        if (prioridadDisplay && prioridadHidden) {
          prioridadDisplay.value = 'Media';
          prioridadHidden.value  = 'media';
        }
        return;
      }

      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'Selecciona un problema';
      problemaSelect.appendChild(placeholder);

      items.forEach(p => {
        const opt = document.createElement('option');
        opt.value = String(p.id);
        opt.textContent = p.label;
        problemaSelect.appendChild(opt);
      });

      problemaSelect.value = '';
      problemaSelect.disabled = false;

      if (prioridadDisplay && prioridadHidden) {
        prioridadDisplay.value = 'Media';
        prioridadHidden.value  = 'media';
      }
    }

    areaSoporte.addEventListener('change', function () {
      fillProblemas(this.value);
    });

    problemaSelect.addEventListener('change', function () {
      const value = this.value;

      if (!prioridadDisplay || !prioridadHidden) return;

      if (value === 'otro' || value === '') {
        prioridadDisplay.value = 'Media';
        prioridadHidden.value  = 'media';
      } else {
        prioridadDisplay.value = 'Alta';
        prioridadHidden.value  = 'alta';
      }
    });

    // Estado inicial
    if (areaSoporte.value) fillProblemas(areaSoporte.value);
    else resetProblemas();
  }

  /* -----------------------------
     Antes de enviar: sincroniza hidden
  ----------------------------- */
  ticketForm.addEventListener('submit', function () {
    const sapFinal    = sapDisplay.value.trim();
    const nombreFinal = nombreDisplay.value.trim();

    sapValueHidden.value    = sapFinal;
    nombreValueHidden.value = nombreFinal;

    if (prioridadDisplay && prioridadHidden && !prioridadHidden.value) {
      const txt = (prioridadDisplay.value || '').toLowerCase();
      if (txt.includes('alta'))      prioridadHidden.value = 'alta';
      else if (txt.includes('baja')) prioridadHidden.value = 'baja';
      else                           prioridadHidden.value = 'media';
    }
  });

}); // FIN MODAL CREAR TICKET (USUARIO)


/* ============================================
   NOTIFICACIONES (POLLING + DESKTOP SIMPLE)
============================================ */
(function () {
  // si ya usas otro script de notis push, corta este
  if (window.HELPDESK_USE_NOTI_PUSH === true) return;

  let lastNotifId = parseInt(localStorage.getItem('lastNotifId') || '0', 10) || 0;

  function setBadge(n) {
    const el = document.getElementById('notifBadge');
    if (!el) return;
    const x = parseInt(n || 0, 10) || 0;
    el.textContent = x > 99 ? '99+' : String(x);
    el.style.display = x > 0 ? 'inline-flex' : 'none';
  }

  function showToast(msg) {
    if (typeof showTicketToast === 'function') return showTicketToast(msg);
    const t = document.createElement('div');
    t.className = 'eqf-toast-ticket';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => {
      t.classList.add('hide');
      setTimeout(() => t.remove(), 280);
    }, 3200);
  }

  function showDesktop(title, body) {
    if (!('Notification' in window)) return;
    if (Notification.permission !== 'granted') return;
    new Notification(title || 'HelpDesk EQF', {
      body: body || '',
      icon: '/HelpDesk_EQF/assets/img/capsulin_login.png'
    });
  }

  async function markRead(ids) {
    if (!ids.length) return;
    try {
      await fetch('/HelpDesk_EQF/modules/notifications/mark_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids })
      });
    } catch (e) {}
  }

  async function poll() {
    try {
      const r = await fetch('/HelpDesk_EQF/modules/notifications/poll.php?since_id=' + encodeURIComponent(lastNotifId), {
        cache: 'no-store'
      });

      const data = await r.json().catch(() => null);
      if (!data || !data.ok) return;

      setBadge(data.unread);

      const notifs = Array.isArray(data.notifications) ? data.notifications : [];
      if (!notifs.length) return;

      const ids = [];
      notifs.forEach(n => {
        const id = parseInt(n.id, 10);
        if (!isNaN(id)) lastNotifId = Math.max(lastNotifId, id);
        ids.push(id);

        showToast((n.title ? (n.title + ': ') : '') + (n.body || ''));
        showDesktop(n.title, n.body);
      });

      localStorage.setItem('lastNotifId', String(lastNotifId));
      await markRead(ids);

    } catch (err) {
      console.error('notif poll error', err);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    if ('Notification' in window && Notification.permission === 'default') {
      Notification.requestPermission();
    }
    poll();
    setInterval(poll, 7000);
  });
})();


/* ============================================
   PUSH REAL (SERVICE WORKER + SUBSCRIPTION)
============================================ */
async function registerHelpDeskPush() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

  try {
    const reg = await navigator.serviceWorker.register('/HelpDesk_EQF/sw.js', { scope: '/HelpDesk_EQF/' });

    let sub = await reg.pushManager.getSubscription();
    if (!sub) {
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') return;

      const vapidPublicKey = window.HELPDESK_VAPID_PUBLIC_KEY;
      if (!vapidPublicKey) return;

      const convertedKey = urlBase64ToUint8Array(vapidPublicKey);

      sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: convertedKey
      });
    }

    await fetch('/HelpDesk_EQF/modules/push/save_subscription.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(sub)
    });
  } catch (e) {
    console.warn('Push setup failed:', e);
  }
}

function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
  const rawData = atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
  return outputArray;
}

document.addEventListener('DOMContentLoaded', () => {
  if (window.HELPDESK_VAPID_PUBLIC_KEY) {
    registerHelpDeskPush();
  }
});


/* ============================================
   MODAL DE AVISO (ADMIN)
============================================ */
document.addEventListener('click', (e) => {
  const openBtn = e.target.closest('[data-open-announcement]');
  const modal = document.getElementById('announceModal');

  if (openBtn) {
    e.preventDefault();
    if (!modal) return console.warn('No existe #announceModal en esta vista');
    modal.classList.add('show');
    return;
  }

  const closeBtn = e.target.closest('[data-close-announcement],[data-cancel-announcement]');
  if (closeBtn) {
    e.preventDefault();
    if (!modal) return;
    modal.classList.remove('show');
    return;
  }

  if (modal && e.target === modal) {
    modal.classList.remove('show');
  }
});

document.addEventListener('click', async (e) => {
  const btn = e.target.closest('#btnSendAnnouncement');
  if (!btn) return;

  e.preventDefault();

  const payload = {
    title: (document.getElementById('ann_title')?.value || '').trim(),
    body: (document.getElementById('ann_body')?.value || '').trim(),
    level: document.getElementById('ann_level')?.value || 'INFO',
    target_area: document.getElementById('ann_area')?.value || 'ALL',
    starts_at: document.getElementById('ann_starts')?.value || null,
    ends_at: document.getElementById('ann_ends')?.value || null
  };

  if (!payload.title || !payload.body) {
    alert('Título y descripción son obligatorios.');
    return;
  }

  btn.disabled = true;
  const prev = btn.textContent;
  btn.textContent = 'Enviando...';

  try {
    const res = await fetch('/HelpDesk_EQF/modules/dashboard/admin/ajax/create_announcement.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const data = await res.json().catch(() => ({}));

    if (!res.ok || !data.ok) {
      alert(data.msg || ('No se pudo enviar. HTTP ' + res.status));
      return;
    }

    alert('Aviso enviado ✅');

    document.getElementById('announceModal')?.classList.remove('show');

    document.getElementById('ann_title').value = '';
    document.getElementById('ann_body').value  = '';
    document.getElementById('ann_level').value = 'INFO';
    document.getElementById('ann_area').value  = 'ALL';
    document.getElementById('ann_starts').value = '';
    document.getElementById('ann_ends').value   = '';

  } catch (err) {
    console.error(err);
    alert('Error de red / fetch.');
  } finally {
    btn.disabled = false;
    btn.textContent = prev || 'Enviar';
  }
});
