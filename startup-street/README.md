# Startup Street

A browser-based business simulation game. You rent a shop on a small neighborhood street, open a business and grow it.

**Status: Level 1 (foundation only).** The project has a design system, a landing page, reusable UI components, a JavaScript foundation and a PHP + MySQL backend skeleton. There is no gameplay, authentication or game database yet.

## Stack

HTML5, CSS3, vanilla JavaScript (ES modules) · PHP 8.1+ · MySQL / MariaDB. No frameworks, no Composer, no npm.

## Run locally

Requirements: PHP 8.1+ with the `pdo_mysql` extension, and MySQL or MariaDB (XAMPP, Laragon and MAMP all work).

```bash
# 1. Configure
cp .env.example .env          # edit DB_USER / DB_PASS if needed

# 2. Create the database (start MySQL first)
php database/migrate.php

# 3. Start the dev server from the project root
php -S localhost:8000 router.php
```

Open <http://localhost:8000>. The footer shows whether the server and database are reachable. API check: <http://localhost:8000/api/health>.

**With XAMPP/WAMP (Apache):** put the folder in `htdocs`, enable `mod_rewrite`, and open `http://localhost/startup-street/frontend/`. The root `.htaccess` routes `/api/*` to the backend.

The database name is set in two places: `DB_NAME` in `.env` and the first lines of `database/schema.sql`. Keep them identical.

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
│   ├── public/index.php  Front controller (the only PHP entry point)
│   ├── config/           Env, Config, Database, settings.php
│   ├── routes/           Router, api.php (route list)
│   ├── controllers/      HTTP handlers
│   ├── models/ services/ middleware/   empty, for later levels
│   └── utils/            Response, ErrorHandler
├── database/             schema.sql, migrate.php, migrations/
└── docs/architecture.md
```

See `docs/architecture.md` for how the pieces fit together.
