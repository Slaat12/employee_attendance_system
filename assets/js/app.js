document.addEventListener('DOMContentLoaded', () => {
  // Show / hide panels (e.g. the employee form)
  document.querySelectorAll('[data-toggle]').forEach(button => {
    button.addEventListener('click', () => {
      const target = document.getElementById(button.dataset.toggle);
      if (!target) return;
      target.hidden = !target.hidden;
      if (!target.hidden) {
        target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        const first = target.querySelector('input:not([type=hidden])');
        if (first) first.focus();
      }
    });
  });

  // Live table search
  document.querySelectorAll('[data-table-search]').forEach(input => {
    input.addEventListener('input', () => {
      const table = document.getElementById(input.dataset.tableSearch);
      if (!table) return;
      const term = input.value.trim().toLocaleLowerCase();
      table.querySelectorAll('tbody tr').forEach(row => {
        row.hidden = term !== '' && !row.textContent.toLocaleLowerCase().includes(term);
      });
    });
  });

  // Confirm before risky actions
  document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
      if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
  });

  // Numeric-only fields (PIN, phone)
  document.querySelectorAll('input[inputmode="numeric"]').forEach(input => {
    input.addEventListener('input', () => { input.value = input.value.replace(/[^0-9]/g, ''); });
  });

  // Names: any letter in any script (matches the server rule), plus space . ' -
  document.querySelectorAll('input[name="first_name"], input[name="last_name"]').forEach(input => {
    input.addEventListener('input', () => { input.value = input.value.replace(/[^\p{L} .'’-]/gu, ''); });
  });

  // Auto-dismiss the kiosk confirmation so the next person doesn't see the last one's name
  document.querySelectorAll('[data-autohide]').forEach(el => {
    setTimeout(() => { el.style.display = 'none'; }, Number(el.dataset.autohide) || 8000);
  });

  // Kiosk: live Accra clock, and a quiet refresh so the page never goes stale
  const clock = document.getElementById('live-clock');
  const kiosk = document.querySelector('[data-kiosk]');
  if (clock) {
    const zone = 'Africa/Accra';
    const dayOf = () => new Intl.DateTimeFormat('en-CA', { timeZone: zone }).format(new Date());
    const startDay = dayOf();
    const idle = () => ![...document.querySelectorAll('#kiosk-form input:not([type=hidden])')].some(i => i.value !== '');
    const tick = () => {
      clock.textContent = new Intl.DateTimeFormat('en-GH', {
        hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true, timeZone: zone
      }).format(new Date());
      if (kiosk && dayOf() !== startDay && idle()) location.replace(location.pathname);   // new day -> new date
    };
    tick(); setInterval(tick, 1000);
    // Every 10 min while nobody is typing: reload so the session & security token never expire.
    if (kiosk) setInterval(() => { if (idle()) location.replace(location.pathname); }, 10 * 60 * 1000);
  }
});
