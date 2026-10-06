// Behaviour for reusable UI components: notifications, modal, loader, empty state.
// Markup/styling for each lives in css/components.css.
import { el, $ } from './utils.js';

/* ---------- Notifications ---------- */
function toastRegion() {
  let region = $('.toast-region');
  if (!region) {
    region = el('div', { class: 'toast-region', role: 'status', 'aria-live': 'polite' });
    document.body.append(region);
  }
  return region;
}

/** notify('success' | 'warning' | 'error' | 'info', message, { duration }) */
export function notify(type, message, { duration = 4500 } = {}) {
  const toast = el('div', { class: `toast toast--${type}` },
    el('p', { class: 'toast__message' }, message),
    el('button', { class: 'toast__close', type: 'button', 'aria-label': 'Dismiss', onclick: () => toast.remove() }, '×'),
  );
  toastRegion().append(toast);
  if (duration > 0) setTimeout(() => toast.remove(), duration);
  return toast;
}

/* ---------- Modal ---------- */
let activeModal = null;

/**
 * openModal({ title, content, actions })
 *  content: string (plain text) or DOM node
 *  actions: [{ label, variant, onClick, closes = true }]
 */
export function openModal({ title, content, actions = [] }) {
  closeModal();
  const previouslyFocused = document.activeElement;
  const titleId = 'modal-title';

  const footer = actions.length
    ? el('div', { class: 'modal__footer' }, actions.map((a) =>
        el('button', {
          class: `btn btn--sm btn--${a.variant ?? 'primary'}`, type: 'button',
          onclick: () => { a.onClick?.(); if (a.closes !== false) closeModal(); },
        }, a.label)))
    : null;

  const dialog = el('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId },
    el('div', { class: 'modal__header' },
      el('h2', { class: 'modal__title', id: titleId }, title),
      el('button', { class: 'modal__close', type: 'button', 'aria-label': 'Close', onclick: closeModal }, '×')),
    el('div', { class: 'modal__body' }, content),
    footer,
  );
  const backdrop = el('div', { class: 'modal-backdrop' }, dialog);
  backdrop.addEventListener('mousedown', (e) => { if (e.target === backdrop) closeModal(); });

  const onKey = (e) => {
    if (e.key === 'Escape') return closeModal();
    if (e.key !== 'Tab') return;
    const focusable = dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    const first = focusable[0], last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  };
  document.addEventListener('keydown', onKey);
  document.body.style.overflow = 'hidden';
  document.body.append(backdrop);
  dialog.querySelector('.modal__close').focus();

  activeModal = { backdrop, onKey, previouslyFocused };
}

export function closeModal() {
  if (!activeModal) return;
  const { backdrop, onKey, previouslyFocused } = activeModal;
  document.removeEventListener('keydown', onKey);
  backdrop.remove();
  document.body.style.overflow = '';
  previouslyFocused?.focus?.();
  activeModal = null;
}

/* ---------- Loading indicator ---------- */
export function createLoader(label = 'Loading…', { block = false } = {}) {
  return el('div', { class: `loader${block ? ' loader--block' : ''}`, role: 'status' },
    el('span', { class: 'loader__spinner', 'aria-hidden': 'true' }), label);
}

/* ---------- Empty state ---------- */
export function createEmptyState({ title, text, action } = {}) {
  return el('div', { class: 'empty-state' },
    el('img', { class: 'empty-state__art', src: 'assets/images/empty-lot.svg', alt: '', width: 120, height: 90 }),
    el('h3', { class: 'empty-state__title' }, title),
    text && el('p', { class: 'empty-state__text' }, text),
    action && el('button', { class: 'btn btn--sm btn--primary', type: 'button', onclick: action.onClick }, action.label),
  );
}
