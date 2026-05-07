

(function () {
  if (typeof window.HELPDESK_USER_ID === 'undefined' || !window.HELPDESK_USER_ID) return;

  let lastNotifId = parseInt(localStorage.getItem('lastNotifId') || '0', 10) || 0;

  function setBadge(n) {
    const el = document.getElementById('notifBadge');
    if (!el) return;
    const x = parseInt(n || 0, 10) || 0;
    el.textContent = x > 99 ? '99+' : String(x);
    el.style.display = x > 0 ? 'inline-flex' : 'none';
  }

  async function ensurePermission() {
    if (!('Notification' in window)) return false;
    if (Notification.permission === 'granted') return true;
    if (Notification.permission === 'denied') return false;

    try {
      const p = await Notification.requestPermission();
      return p === 'granted';
    } catch (e) {
      return false;
    }
  }

  function prefixByType(typeRaw) {
    const t = String(typeRaw || '').toLowerCase().trim();

    if (t === 'ticket_transfer') return '🔁 Ticket canalizado';
    if (t === 'ticket_status')   return '📝 Ticket actualizado';
    if (t === 'ticket_new')      return '🎫 Nuevo ticket';

    if (t === 'task_assigned')   return '✅ Tarea asignada';
    if (t === 'task_reassigned') return '🔁 Tarea reasignada';
    if (t === 'task_canceled')   return '⛔ Tarea cancelada';
    if (t === 'task_finished')   return '🏁 Tarea finalizada';
    
    if (t === 'ticket_assigned') return '👤 Ticket asignado';

    if (t.startsWith('ticket_')) return '🎫 Ticket';
    if (t.startsWith('task_'))   return '📋 Tarea';

    return 'HelpDesk EQF';
  }

  async function showDesktop(title, body, link, tag) {
    const ok = await ensurePermission();
    if (!ok) return;

    const iconPath = '/HelpDesk_EQF/assets/img/icon_desktop.png';

if ('serviceWorker' in navigator) {
  try {
    const reg = await navigator.serviceWorker.ready;
    if (reg && reg.showNotification) {
      await reg.showNotification(title || 'HelpDesk EQF', {
        body: body || '',
        icon: iconPath,
        badge: iconPath,
        tag: tag || undefined,
        data: { url: link || '/HelpDesk_EQF/' },
        renotify: false
      });
      return;
    }
  } catch (e) {}
}


    const n = new Notification(title || 'HelpDesk EQF', {
      body: body || '',
      icon: iconPath,
      tag: tag || undefined
    });

    if (link) {
      n.onclick = () => {
        try { window.open(link, '_blank'); } catch (e) {}
      };
    }
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

  let running = false;
async function initPushEQF() {
  if (!("serviceWorker" in navigator) || !("PushManager" in window)) return;

  // Registrar el Service Worker (debe existir /HelpDesk_EQF/sw.js)
const reg = await navigator.serviceWorker.register("/HelpDesk_EQF/sw.js", { scope: "/HelpDesk_EQF/" });

  // Pedir permiso
  const perm = await Notification.requestPermission();
  if (perm !== "granted") return;

  // Pedir publicKey al server
  const r = await fetch("/HelpDesk_EQF/api/push_public_key.php", { credentials: "include" });
  const j = await r.json();
  if (!j.ok || !j.publicKey) return;

  // Reusar suscripción si ya existe
  let sub = await reg.pushManager.getSubscription();
  if (!sub) {
    sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(j.publicKey),
    });
  }

  // Guardar suscripción en BD
  await fetch("/HelpDesk_EQF/api/push_subscribe.php", {
    method: "POST",
    credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(sub),
  });
}

function urlBase64ToUint8Array(base64String) {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
  const rawData = atob(base64);
  const out = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; i++) out[i] = rawData.charCodeAt(i);
  return out;
}

document.addEventListener("DOMContentLoaded", () => {
  initPushEQF().catch(() => {});
});

  async function poll() {
    if (running) return;
    running = true;

    try {
      const r = await fetch('/HelpDesk_EQF/modules/notifications/poll.php?since_id=' + encodeURIComponent(lastNotifId), {
        cache: 'no-store'
      });

      const ct = (r.headers.get('content-type') || '').toLowerCase();
      if (!ct.includes('application/json')) return;

      const data = await r.json();
      if (!data || !data.ok) return;

      setBadge(data.unread);

      const notifs = Array.isArray(data.notifications) ? data.notifications : [];
      if (!notifs.length) return;

      const ids = [];

      for (const n of notifs) {
        const id = parseInt(n.id, 10);
        if (!isNaN(id)) {
          lastNotifId = Math.max(lastNotifId, id);
          ids.push(id);
        }

        const prefix = prefixByType(n.type);

        const baseTitle = String(n.title || '').trim();
        const title = baseTitle ? `${prefix} · ${baseTitle}` : prefix;

        await showDesktop(
          title,
          String(n.body || ''),
          String(n.link || '/HelpDesk_EQF/'),
          'helpdesk-' + (id || Date.now())
        );
      }

      localStorage.setItem('lastNotifId', String(lastNotifId));
      await markRead(ids);

    } catch (e) {
    } finally {
      running = false;
    }
  }

  poll();
  setInterval(poll, 7000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

})();
