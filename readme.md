# My BeerFest

A Progressive Web App for beer festival management. Users can browse, filter, search, and rate beers. Ratings are stored locally in the browser. Optional server-side statistics logging is consent-based and GDPR-compliant.

## Features

- **Beer browser** — filter by style, brewery, country, session; sort by name, ABV, rating
- **Personal ratings** — rate beers 0.25-5.0, stored in localStorage, export/import via shareable URL
- **Favorites** — star beers to find them quickly
- **Statistics dashboards** — personal stats (`/my_stats.php`) and organizer stats (`/stats.php`, auth-protected)
- **Admin panel** — manage beer catalog via `/admin.php` (auth-protected)
- **PWA & offline** — installable, Service Worker caches app shell and data
- **Multi-language** — Danish, English, Swedish, Norwegian, German, French, Polish, Czech - Admin endpoints is only in English
- **Coming-soon mode** — gate the public site behind a localized "not yet brewed" page with optional countdown; share a `?key=…` preview link to grant stakeholders cookie-based bypass
- **Privacy-first** — no database, no server sessions, server logging is opt-in only

## Quick Start

Requires Docker Engine and Docker Compose.

1. Copy the example environment file and adjust the values:

```bash
cp .env.example .env
```

2. Start the app:

```bash
docker compose up -d
```

App available at **http://127.0.0.1:8181**

```bash
docker compose down   # stop
```

## Configuration

Environment variables are defined in a `.env` file in the project root (see `.env.example` for a template). Docker Compose reads this file automatically. Auth variables are passed to the `nginx` service, the rest to `php`:

| Variable | Purpose | Default |
|---|---|---|
| `FESTIVAL_TITLE` | Full festival name | `Lorem Ipsum Festival 2025` |
| `FESTIVAL_TITLE_SHORT` | Short name for PWA | `Ølfestival` |
| `APP_LANGUAGE` | Language code (`da`, `en`, `sv`, `no`, `de`, `fr`, `pl`, `cs`) | `da` |
| `DOMAIN` | CORS allowed origin | _(none — same-origin only)_ |
| `ENABLE_STATISTICS_LOGGING` | Enable server-side rating/consent logs | `true` |
| `ENABLE_MAINSTYLE_FILTERING` | Group beer styles by main category | `true` |
| `FESTIVAL_INFO_TEXT` | Custom info text in the app | _(none)_ |
| `CONTACT_EMAIL` | Email shown in privacy policy | _(none)_ |
| `DEV_MODE` | Disable Service Worker for development | `false` |
| `NOT_PUBLIC` | Gate the main page behind a "coming soon" view | `false` |
| `NOT_PUBLIC_BYPASS_KEY` | Shared secret for `?key=…` preview links (sets a 30-day cookie). Empty disables bypass. | _(none)_ |
| `FESTIVAL_DATE` | RFC3339 timestamp the countdown counts down to (e.g. `2026-06-15T18:00:00+02:00`) | _(none)_ |
| `SHOW_COUNTDOWN` | Render the countdown on the coming-soon page | `false` |
| `STATS_USER` / `STATS_PASSWORD` | Basic auth for `/stats.php` (nginx) | `stats` / `changeme` |
| `ADMIN_USER` / `ADMIN_PASSWORD` | Basic auth for `/admin.php` and `/admin_api.php` (nginx) | `admin` / `changeme` |

## Beer Data Format

The beer catalog lives in `src/data/beers.json`. Each beer needs a unique `id`:

```json
{
  "id": "unique-id",
  "name": "Beer Name",
  "brewery": "Brewery Name",
  "alc": 5.5,
  "style": "IPA - New England",
  "country": "Denmark",
  "untappd": "https://untappd.com/b/beer/123456",
  "rating": 4.2,
  "session": "Friday"
}
```

## Testing

Run the automated smoke tests (requires the app to be running):

```bash
./tests/smoke_test.sh
```

This runs ~39 assertions covering page loads, API validation, auth gates, XSS escaping, and CSRF protection. Override defaults with env vars:

```bash
BASE_URL=http://localhost:8181 ADMIN_USER=admin ADMIN_PASS=changeme ./tests/smoke_test.sh
```

## Project Structure

```
src/                  # Application source (mounted into containers)
  index.php           # Main app — beer browser & rater
  stats.php           # Organizer statistics dashboard
  admin.php           # Admin panel for beer catalog management
  admin_api.php       # Admin REST API
  log_rating.php      # POST API — log a beer rating
  log_cookie_consent.php  # POST API — log consent choice
  my_stats.php        # Personal statistics page
  privacy-policy.php  # GDPR privacy policy
  manifest.php        # PWA web manifest
  sw.js               # Service Worker
  data/               # beers.json, flags.json
  lang/               # Translation files (.conf)
  config/             # CSS theme files
nginx/
  nginx.conf          # Nginx config with auth and PHP-FPM proxy
  entrypoint.sh       # Generates .htpasswd files from env vars
tests/
  smoke_test.sh       # Automated smoke & security tests
docker-compose.yml
Dockerfile            # Multi-stage: Node (Tailwind) -> PHP-FPM + Nginx targets
```

## Tech Stack

- **Backend:** PHP 8.2 (FPM Alpine)
- **Web Server:** Nginx (stable Alpine)
- **Frontend:** Vanilla JS (ES6+), Tailwind CSS
- **Containerization:** Docker Compose
- **Data:** JSON files + localStorage + NDJSON server logs
- **No database** — stateless by design

## Customization

The `custom/` directory lets you customize theme colors and PWA icons per deployment without modifying git-tracked files. This means you can `git pull` on a production server without merge conflicts.

### Setup

```bash
./init-custom.sh
```

This copies `src/config/theme.css` into `custom/theme.css` as a starting point. The entire `custom/` directory is gitignored.

### Custom Theme Colors

Edit `custom/theme.css` and change the palette variables:

```css
:root {
    --palette-primary: #1a365d;
    --palette-background: #1a365d;
    --palette-surface: #2a4a7f;
    /* ...etc */
}
```

When `custom/theme.css` exists, it is loaded **instead of** the default `config/theme.css` — not in addition to it. This avoids CSS cascade issues and gives you full control. The component tokens in the same file inherit from the palette variables automatically.

You can also use one of the preset themes as a starting point by copying it into `custom/`:

| File | Look |
|---|---|
| `src/config/green.css` | Forest green + lime yellow |
| `src/config/blue.css` | Navy blue + warm gold |
| `src/config/orange.css` | Orange + dark teal |

When creating a custom theme, follow the pattern: keep `--palette-background` equal to `--palette-primary`, make `--palette-surface` a lighter shade and `--palette-interactive` a darker shade, and pick a vibrant accent for `--palette-text-primary`.

If your theme changes the primary color significantly, also update the `THEME_COLOR` environment variable to match `--palette-primary` — this controls the PWA status bar and splash screen color.

### Custom PWA Icons

Drop replacement PNG icons into `custom/`:

```
custom/icon-192.png   (192x192 — home screen)
custom/icon-512.png   (512x512 — splash screen)
```

The app checks for custom icons first and falls back to the defaults in `src/images/`. Icons should use the `any maskable` purpose format (safe area within the center 80% of the image).

### How It Works

- `custom/` is mounted into containers at `/var/www/html/custom/` (read-only)
- PHP pages load `custom/theme.css` if it exists, otherwise fall back to `config/theme.css`
- `manifest.php` and `index.php` resolve icon paths with a custom-first fallback
- Default files in `src/config/` and `src/images/` remain tracked in git and are never modified on the server

## License

This project is licensed under the MIT License.

**Special Commercial Use Clause:**
This application is intended for use by individual beer festival organizers for their own events.

You **may**: use it for your own festival, modify it, and share it non-commercially.

You may **not**: sell, sublicense, or offer it as a paid service to other festival organizers. Contact the original author for a separate commercial license.
