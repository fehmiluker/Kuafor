import './bootstrap';

/**
 * Tenant calendar (FullCalendar). Loaded only on pages that contain #calendar,
 * so public pages stay light. Drag & drop calls the server, which re-validates
 * permission and availability inside the booking lock; on failure the event
 * snaps back and the server's message is shown.
 */
async function initCalendar(el) {
    const [{ Calendar }, dayGrid, timeGrid, interaction] = await Promise.all([
        import('@fullcalendar/core'),
        import('@fullcalendar/daygrid'),
        import('@fullcalendar/timegrid'),
        import('@fullcalendar/interaction'),
    ]);
    const trLocale = (await import('@fullcalendar/core/locales/tr')).default;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const canMove = el.dataset.canMove === '1';
    const status = document.getElementById('calendar-status');

    const calendar = new Calendar(el, {
        plugins: [dayGrid.default, timeGrid.default, interaction.default],
        locale: trLocale,
        timeZone: 'local',
        initialView: window.matchMedia('(max-width: 768px)').matches ? 'timeGridDay' : 'timeGridWeek',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'timeGridDay,timeGridWeek,dayGridMonth' },
        slotMinTime: el.dataset.min || '07:00:00',
        slotMaxTime: el.dataset.max || '23:00:00',
        allDaySlot: false,
        nowIndicator: true,
        editable: canMove,
        eventDurationEditable: false,
        height: 'auto',
        events: (info, success, failure) => {
            const url = new URL(el.dataset.feed, window.location.origin);
            url.searchParams.set('start', info.startStr);
            url.searchParams.set('end', info.endStr);
            url.searchParams.set('branch_id', el.dataset.branch);
            fetch(url, { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : Promise.reject(r)))
                .then((events) => {
                    const employee = document.getElementById('employee-filter')?.value;
                    success(employee ? events.filter((e) => String(e.extendedProps.employee_id) === employee) : events);
                })
                .catch(failure);
        },
        eventClick: (info) => { window.location.href = info.event.extendedProps.url; },
        eventDidMount: (info) => {
            info.el.setAttribute('title', `${info.event.title} · ${info.event.extendedProps.employee ?? ''} · ${info.event.extendedProps.status}`);
        },
        eventDrop: async (info) => {
            const start = info.event.startStr.slice(0, 19);
            if (status) status.textContent = 'Kaydediliyor…';
            const res = await fetch(el.dataset.dragUrl.replace('__ID__', info.event.id), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ start, employee_id: info.event.extendedProps.employee_id }),
            });
            const body = await res.json().catch(() => ({}));
            if (!res.ok || !body.ok) {
                info.revert();
                if (status) status.textContent = body.message || 'Randevu taşınamadı.';
            } else if (status) {
                status.textContent = 'Randevu taşındı. Müşteriye bildirim gönderilecek.';
            }
        },
        loading: (isLoading) => el.setAttribute('aria-busy', isLoading ? 'true' : 'false'),
    });
    calendar.render();
    document.getElementById('employee-filter')?.addEventListener('change', () => calendar.refetchEvents());
}

document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('calendar');
    if (el) initCalendar(el);
});
