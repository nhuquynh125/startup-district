# Architecture (Level 1)

## Request flow

```
Browser ── fetch /api/health ──> backend/public/index.php
                                   1. autoload classes   (App\Config\Env -> backend/config/Env.php)
                                   2. load .env + settings.php
                                   3. register ErrorHandler (all errors -> JSON)
                                   4. Router matches method + path
                                   5. Controller returns Response::success()/error()
```

Every API reply has one shape:

```json
{ "success": true,  "data": { } }
{ "success": false, "error": { "message": "…", "status": 404 } }
```

## Backend conventions

- **Namespace `App\<Folder>`** maps to `backend/<folder>/`, so adding a class means adding a file. No Composer needed.
- **Config** comes only from `.env` through `settings.php`; read it with `Config::get('db.host')`. Secrets never live in code.
- **Database** is a lazy PDO singleton (`Database::connection()`). Always use prepared statements.
- **Layers for later levels:** controllers read the request and reply; services hold game rules; models talk to the database; middleware handles cross-cutting checks such as authentication.
- **Adding an endpoint:** create a controller method, then register it in `routes/api.php`.

## Frontend conventions

- ES modules, no build step. `config.js` holds constants, `utils.js` DOM/format helpers, `api.js` the only code that calls the backend, `ui.js` modal/notification/loader/empty-state behaviour, `app.js` page wiring.
- **CSS layers:** `variables.css` (tokens) → `global.css` (base + layout helpers) → `components.css` (reusable) → page CSS. Change colors, spacing or type only in `variables.css`.
- Components: header/nav, `.btn`, `.card`, `.input`/`.field`, `.badge`, `.modal`, toasts, `.loader`, `.empty-state`.

## Database

`schema.sql` creates the database and the `schema_migrations` table. Level 2 adds numbered files to `database/migrations/` (`001_create_players.sql`, …); `php database/migrate.php` applies the ones not yet recorded.
