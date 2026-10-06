# Architecture (Level 2)

## Request flow

```
Browser ── fetch /api/health ──> backend/public/index.php
                                   1. backend/bootstrap.php: autoloader, .env, settings.php
                                   2. register ErrorHandler (all errors -> JSON)
                                   3. Request::fromGlobals()   (path relative to /api, JSON body helper)
                                   4. Router matches method + path, runs middleware, calls the controller
                                   5. Controller -> Model -> Database (PDO) ... Response::success() / throw HttpException
```

## API response format

Every reply has the same top-level keys. Money is always integer **cents**.

```json
{ "success": true,  "data": { }, "message": null }

{ "success": false, "data": null, "message": "Business type not found.",
  "error": { "code": "not_found", "status": 404, "message": "Business type not found." } }
```

- `error.code` is machine-readable: `bad_request`, `unauthorized`, `forbidden`, `not_found`, `method_not_allowed`, `conflict`, `payload_too_large`, `unsupported_media_type`, `validation_failed`, `server_error`.
- `error.details` appears for field errors (`422`) and, only when `APP_DEBUG=true`, for server errors.
- `error.message` repeats `message` so the Level 1 `api.js` (which reads `payload.error.message`) works unchanged.
- Send `throw HttpException::notFound('...')` from anywhere; `ErrorHandler` turns it into the envelope. Anything else thrown becomes a generic `500` (full detail goes to the server log).

## Backend conventions

- **Namespace `App\<Folder>`** maps to `backend/<folder>/`, so adding a class means adding a file. No Composer needed.
- **Config** comes only from `.env` through `settings.php`; read it with `Config::get('db.host')`. Secrets never live in code or reach the browser.
- **Database layer** (`App\Config\Database`): one lazy PDO connection (real prepared statements, strict SQL mode, UTC), helpers `fetchAll / fetchOne / fetchValue / execute / insert / update`, `transaction()` with savepoints and deadlock retry, and `DatabaseException` with helpers such as `isDuplicateKey()`. Identifiers are validated; values are always bound.
- **Layers:** controllers read the request and reply; services (later levels) hold game rules; models talk to the database (`App\Models\Model` base class); middleware handles cross-cutting checks such as authentication (`MiddlewareInterface`).
- **Routing:** `backend/routes/api.php` loads one file per module from `routes/modules/`. Each module groups its paths under a prefix:

  ```php
  $router->group('/shops', function (Router $r) {
      $r->get('/{id:int}', [ShopController::class, 'show']);
      $r->post('/{id:int}/close', [ShopController::class, 'close']);
  }, [AuthMiddleware::class]);
  ```
  Parameter types: `{name}` any segment, `{name:int}` digits, `{name:slug}` lower-case words with dashes. Handlers receive `(Request $request, array $params)`.
- **Adding an endpoint:** controller method -> route in the module file. **Adding a module:** copy `routes/modules/catalog.php`, add its name to the list in `routes/api.php`.
- Implemented so far: `GET /api/health`, `GET /api/catalog/business-types`, `GET /api/catalog/business-types/{slug}` (read-only reference data; exists to exercise the whole stack).

## Frontend conventions

- ES modules, no build step. `config.js` holds constants, `utils.js` DOM/format helpers, `api.js` the only code that calls the backend, `ui.js` modal/notification/loader/empty-state behaviour, `app.js` page wiring.
- **CSS layers:** `variables.css` (tokens) → `global.css` (base + layout helpers) → `components.css` (reusable) → page CSS. Change colors, spacing or type only in `variables.css`.
- Components: header/nav, `.btn`, `.card`, `.input`/`.field`, `.badge`, `.modal`, toasts, `.loader`, `.empty-state`.

## Database

See `docs/database.md` for the ERD, design decisions and the rules for writing gameplay code safely. In short: `schema.sql` is the baseline for all 26 tables, `seed.sql` holds the game catalog, `php database/migrate.php [--seed] [--fresh]` applies them, and later schema changes go into numbered files in `database/migrations/`.
