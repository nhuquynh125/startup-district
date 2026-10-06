// Central frontend configuration. Nothing else in the app hard-codes these values.

// Works from both dev setups:
//   php -S localhost:8000 router.php      -> page at /frontend/..., API at /api
//   XAMPP at /startup-street/frontend/    -> API at /startup-street/api
const basePath = window.location.pathname.includes('/frontend/')
  ? window.location.pathname.split('/frontend/')[0]
  : '';

export const CONFIG = Object.freeze({
  APP_NAME: 'Startup Street',
  API_BASE_URL: `${basePath}/api`,
  API_TIMEOUT_MS: 10000,
  LOCALE: 'en-US',
  CURRENCY: 'USD',
});
