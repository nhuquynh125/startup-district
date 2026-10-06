// Landing page entry point: wires up navigation, modal, and the backend status check.
import { api } from './api.js';
import { $, $$, el, on } from './utils.js';
import { createEmptyState, createLoader, notify, openModal } from './ui.js';

function initNav() {
  const toggle = $('.nav-toggle');
  const nav = $('#main-nav');
  const setOpen = (open) => {
    nav.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
  };
  on(toggle, 'click', () => setOpen(!nav.classList.contains('is-open')));
  on(nav, 'click', 'a', () => setOpen(false));

  // Highlight the nav link of the section currently on screen.
  const links = $$('.nav__link');
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      links.forEach((l) => l.setAttribute('aria-current', String(l.hash === `#${entry.target.id}`)));
    });
  }, { rootMargin: '-45% 0px -50% 0px' });
  $$('main section[id]').forEach((s) => observer.observe(s));
}

function initActions() {
  on(document, 'click', '[data-action="start-playing"]', () => {
    openModal({
      title: 'Start Playing',
      content: createEmptyState({
        title: 'The street is still under construction',
        text: 'Gameplay arrives in a later level. For now, explore the businesses you will be able to open.',
      }),
      actions: [{ label: 'Got it', variant: 'primary' }],
    });
  });
}

async function checkBackend() {
  const status = $('#backend-status');
  status.replaceChildren(createLoader('Checking server…'));
  try {
    const health = await api.get('/health');
    const db = health.database.connected;
    status.replaceChildren(
      el('span', { class: 'badge badge--success' }, 'Server online'),
      el('span', { class: `badge ${db ? 'badge--success' : 'badge--warning'}` }, db ? 'Database connected' : 'Database not connected'),
    );
    if (!db) notify('warning', 'The server is running, but the database is not connected yet. Check your .env file.', { duration: 7000 });
  } catch (err) {
    status.replaceChildren(el('span', { class: 'badge badge--error' }, 'Server offline'));
    notify('error', `${err.message} Start it with: php -S localhost:8000 router.php`, { duration: 8000 });
  }
}

initNav();
initActions();
checkBackend();
