# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Behavior Standards

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

### 1. Think Before Coding

Don't assume. Don't hide confusion. Surface tradeoffs.

- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them — don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

### 2. Simplicity First

Minimum code that solves the problem. Nothing speculative.

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.
- Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

### 3. Surgical Changes

Touch only what you must. Clean up only your own mess.

- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it — don't delete it.
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

### 4. Goal-Driven Execution

Define success criteria. Loop until verified.

- Transform tasks into verifiable goals:
    - "Add validation" → "Write tests for invalid inputs, then make them pass"
    - "Fix the bug" → "Write a test that reproduces it, then make it pass"
    - "Refactor X" → "Ensure tests pass before and after"
- For multi-step tasks, state a brief plan before acting:
    1. [Step] → verify: [check]
    2. [Step] → verify: [check]
    3. [Step] → verify: [check]
- Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

**These guidelines are working if:** fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.

## Running the Server

**Windows:**
```bat
php windows.php
# or
windows.bat
```

**Linux/macOS:**
```bash
php start.php start        # foreground
php webman start           # via CLI tool
php webman stop|restart|reload|status
```

**Docker:**
```bash
docker-compose up
# App exposed on port 19505 (mapped to internal 8787)
# Connects to external Docker network: dbNet
```

The HTTP server listens on `http://0.0.0.0:8787`. The file monitor process auto-reloads workers on changes to `.php`, `.html`, `.htm`, `.env` files.

## Framework Architecture

This project uses **Webman** — a high-performance PHP HTTP framework built on **Workerman** (async, event-driven I/O). Worker count defaults to `cpu_count() * 4`.

Key distinction from traditional PHP: the application boots once per worker and stays resident in memory. Avoid storing state in static variables or singletons that shouldn't persist across requests.

**Request lifecycle:** HTTP request → Middleware pipeline (`config/middleware.php`) → Controller action → View/JSON response

**Routing:** Automatic routing by `Controller/action` convention. Explicit routes go in `config/route.php`. The default controller suffix is `Controller` (set in `config/app.php`).

## Key Directories

- `app/controller/` — HTTP handlers; return `response()`, `json()`, or `view()` helpers
- `app/middleware/` — PSR-15 middleware; `StaticFile.php` blocks dot-path requests
- `app/model/` — Eloquent ORM models extending `support\Model`
- `app/process/` — Custom Workerman process definitions (HTTP worker, file monitor)
- `config/` — All framework configuration; each file maps to a service
- `support/` — Framework wrappers (`Request`, `Response`, `bootstrap.php`)
- `php-ext/` — Custom `randcode` PHP extension with PHP wrapper class `XCode`
- `runtime/` — Generated logs, compiled views, sessions (gitignored)

## Configuration

Config files in `config/` are auto-loaded. Important ones:

| File | Purpose |
|---|---|
| `config/database.php` | MySQL connection (pool: min 1, max 5); uses Illuminate Eloquent |
| `config/redis.php` | Redis connection pool |
| `config/process.php` | Workerman process definitions and worker count |
| `config/session.php` | Session driver (file or Redis) |
| `config/log.php` | Monolog channels |
| `config/view.php` | View engine (Raw PHP by default; Blade available) |

Database credentials are placeholders — set host, database, username, and password before connecting.

## Models

Models extend `support\Model` (Illuminate Eloquent). Define `$table`, `$primaryKey`, `$fillable`/`$guarded` as needed. No migration files exist yet; schema must be managed manually or via a migration tool.

## Custom PHP Extension

`php-ext/randcode.php` exposes the `XCode` class wrapping the native `librandcode_php.so` extension. Methods: `machineId()`, `secret()`, `length()`, `format()`, `make()`. The extension is mounted into the Docker container via volume.

## Dependencies (composer.json)

- `workerman/webman-framework ^2.1` — core framework
- `webman/database ^2.1` — Eloquent ORM adapter
- `webman/redis ^2.1` — Redis integration
- `webman/blade ^1.5` — Blade templating
- `webman/validation ^2.2` — Laravel-style validation
- `webman/console ^2.2` — Artisan-style CLI commands
- `illuminate/pagination`, `illuminate/events ^12.59`
- `monolog/monolog ^2.0`
- PHP >= 8.1 required
