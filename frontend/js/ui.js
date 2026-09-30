/**
 * Shared UI enhancements applied to every page: a responsive nav-bar toggle
 * for the top bar, and a show/hide button on every password field. Runs
 * automatically on DOMContentLoaded so no page markup needs to change.
 */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.topbar .container').forEach((bar) => {
    const nav = bar.querySelector('.nav-links');
    if (!nav || bar.querySelector('.nav-toggle')) return;

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'nav-toggle';
    toggle.setAttribute('aria-label', 'Toggle navigation menu');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.innerHTML = '<span></span><span></span><span></span>';
    bar.insertBefore(toggle, nav);

    const setOpen = (open) => {
      nav.classList.toggle('open', open);
      toggle.classList.toggle('open', open);
      toggle.setAttribute('aria-expanded', String(open));
    };
    toggle.addEventListener('click', () => setOpen(!nav.classList.contains('open')));
    nav.querySelectorAll('a').forEach((a) => a.addEventListener('click', () => setOpen(false)));
    document.addEventListener('click', (e) => {
      if (!nav.classList.contains('open')) return;
      if (bar.contains(e.target)) return;
      setOpen(false);
    });
  });

  document.querySelectorAll('input[type="password"]').forEach((input) => {
    if (input.closest('.pw-field')) return;
    const wrap = document.createElement('div');
    wrap.className = 'pw-field';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-toggle';
    btn.textContent = 'Show';
    btn.setAttribute('aria-label', 'Show password');
    btn.addEventListener('click', () => {
      const nowVisible = input.type === 'password';
      input.type = nowVisible ? 'text' : 'password';
      btn.textContent = nowVisible ? 'Hide' : 'Show';
      btn.classList.toggle('is-visible', nowVisible);
      btn.setAttribute('aria-label', nowVisible ? 'Hide password' : 'Show password');
    });
    wrap.appendChild(btn);
  });
});
