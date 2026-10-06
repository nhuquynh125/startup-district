// The only place the frontend talks to the PHP backend.
// Backend replies use one envelope: { success: true, data } or { success: false, error: { message, status } }.
import { CONFIG } from './config.js';

export class ApiError extends Error {
  constructor(message, status = 0, details = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.details = details;
  }
}

async function request(method, path, { body, query, signal } = {}) {
  const url = new URL(CONFIG.API_BASE_URL + path, window.location.origin);
  if (query) Object.entries(query).forEach(([k, v]) => v != null && url.searchParams.set(k, v));

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), CONFIG.API_TIMEOUT_MS);
  signal?.addEventListener('abort', () => controller.abort());

  let response;
  try {
    response = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
      signal: controller.signal,
    });
  } catch (err) {
    const timedOut = err.name === 'AbortError';
    throw new ApiError(timedOut ? 'The server took too long to answer.' : 'Cannot reach the server.', 0);
  } finally {
    clearTimeout(timer);
  }

  let payload = null;
  try { payload = await response.json(); } catch { /* non-JSON reply handled below */ }

  if (!response.ok || !payload || payload.success !== true) {
    throw new ApiError(payload?.error?.message ?? `Request failed (${response.status}).`, response.status, payload?.error?.details ?? null);
  }
  return payload.data;
}

export const api = {
  get:    (path, options) => request('GET', path, options),
  post:   (path, body, options) => request('POST', path, { ...options, body }),
  put:    (path, body, options) => request('PUT', path, { ...options, body }),
  delete: (path, options) => request('DELETE', path, options),
};
