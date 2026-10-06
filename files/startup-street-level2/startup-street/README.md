# Startup Street

A browser-based business simulation game. You rent a shop on a small neighborhood street, open a business and grow it.

**Status: Level 2 (database and backend foundation).** On top of the Level 1 design system, landing page and UI components, the project now has the full game database (26 tables, seeded with the game catalog), a PDO database layer with transactions, and a consistent REST API foundation with a router, middleware hooks and error handling. There is no gameplay, authentication, renting, shops or AI yet.

## Stack

HTML5, CSS3, vanilla JavaScript (ES modules) · PHP 8.1+ · MySQL / MariaDB. No frameworks, no Composer, no npm.

## Run locally

Requirements: PHP 8.1+ with the `pdo_mysql` extension, and MySQL or MariaDB (XAMPP, Laragon and MAMP all work).

```bash
# 1. Configure
cp .env.example .env          # edit DB_USER / DB_PASS if needed

# 2. Create the database, tables and game data (start MySQL first)
php database/migrate.php --seed

# 3. Check everything works
php tests/run.php

# 4. Start the dev server from the project root
php -S localhost:8000 router.php
```

Open <http://localhost:8000>. The footer shows whether the server and database are reachable. API checks: <http://localhost:8000/api/health> and <http://localhost:8000/api/catalog/business-types>. With the server running, `php tests/run.php --base-url=http://localhost:8000` also tests the live API.

**With XAMPP/WAMP (Apache):** put the folder in `htdocs`, enable `mod_rewrite`, and open `http://localhost/startup-street/frontend/`. The root `.htaccess` routes `/api/*` to the backend and should also deny direct access to `backend/`, `database/`, `docs/` and `tests/`.

The database name is set in one place: `DB_NAME` in `.env`. `migrate.php` creates the database if it is missing.

### Database commands

```bash
php database/migrate.php            # create the database, apply schema.sql and pending migrations
php database/migrate.php --seed     # ...and load seed.sql (repeatable)
php database/migrate.php --fresh    # development only: drop all tables and rebuild
```

## Structure

```
startup-street/
├── router.php            Dev-server router (php -S)
├── .htaccess             Apache rewrite for /api/*
├── .env.example          Configuration template (copy to .env)
├── frontend/
│   ├── index.html        Landing page
│   ├── assets/           images, icons, fonts
│   ├── css/              reset, variables (design tokens), global, components, landing
│   └── js/               config, utils, api, ui, app
├── backend/
│   ├── bootstrap.php     Autoloader + .env + config (shared by web, CLI and tests)
│   ├── public/index.php  Front controller (the only PHP entry point)
│   ├── config/           Env, Config, Database (PDO layer), settings.php
│   ├── routes/           Router, api.php (module list), modules/ (one file per module)
│   ├── controllers/      HTTP handlers
│   ├── models/           Database access per table (Model base class)
│   ├── services/         empty, game rules arrive in later levels
│   ├── middleware/       MiddlewareInterface (authentication arrives later)
│   └── utils/            Request, Response, HttpException, DatabaseException, ErrorHandler, SqlScript
├── database/             schema.sql, seed.sql, migrate.php, migrations/
├── tests/run.php         Dependency-free checks for the database, router and API
└── docs/                 architecture.md, database.md (ERD and design decisions)
```

See `docs/architecture.md` for how the pieces fit together and `docs/database.md` for the database design.
