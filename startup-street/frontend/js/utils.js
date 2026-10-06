// Small, dependency-free helpers shared across the frontend.
import { CONFIG } from './config.js';

/* ---------- DOM ---------- */
export const $  = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

/** Create an element: el('button', { class: 'btn', onclick: fn }, 'Label', childNode). */
export function el(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (value == null || value === false) continue;
    if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2), value);
    else if (key === 'class') node.className = value;
    else node.setAttribute(key, value === true ? '' : value);
  }
  for (const child of children.flat()) {
    if (child == null || child === false) continue;
    node.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return node;
}

/**
 * Add an event listener. With a selector it delegates, so it also works
 * for elements added to the page later: on(document, 'click', '[data-x]', fn).
 */
export function on(target, type, selectorOrHandler, handler) {
  if (typeof selectorOrHandler === 'function') {
    target.addEventListener(type, selectorOrHandler);
    return () => target.removeEventListener(type, selectorOrHandler);
  }
  const listener = (event) => {
    const match = event.target.closest(selectorOrHandler);
    if (match && target.contains(match)) handler(event, match);
  };
  target.addEventListener(type, listener);
  return () => target.removeEventListener(type, listener);
}

/* ---------- Formatting ---------- */
export const formatCurrency = (amount, { currency = CONFIG.CURRENCY, decimals = 0 } = {}) =>
  new Intl.NumberFormat(CONFIG.LOCALE, {
    style: 'currency', currency, minimumFractionDigits: decimals, maximumFractionDigits: decimals,
  }).format(amount);

export const formatNumber = (value, { decimals = 0, compact = false } = {}) =>
  new Intl.NumberFormat(CONFIG.LOCALE, {
    notation: compact ? 'compact' : 'standard', minimumFractionDigits: decimals, maximumFractionDigits: decimals,
  }).format(value);

export const formatPercent = (ratio, decimals = 0) =>
  new Intl.NumberFormat(CONFIG.LOCALE, { style: 'percent', maximumFractionDigits: decimals }).format(ratio);

/* ---------- Misc ---------- */
export function debounce(fn, wait = 200) {
  let timer;
  return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), wait); };
}
