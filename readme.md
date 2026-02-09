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
Dockerfile            # Multi-stage: Node (Tailwind build) -> PHP-FPM
```

## Tech Stack

- **Backend:** PHP 8.2 (FPM Alpine)
- **Web Server:** Nginx (stable Alpine)
- **Frontend:** Vanilla JS (ES6+), Tailwind CSS
- **Containerization:** Docker Compose
- **Data:** JSON files + localStorage + NDJSON server logs
- **No database** — stateless by design

## Customization

### Color Themes

The app's color scheme is controlled by a single file: `src/config/theme.css`. It uses a two-tier CSS custom property system:

1. **Tier 1 — Base palette**: A handful of raw colors (primary, surface, text accent, etc.)
2. **Tier 2 — Component tokens**: Variables like `--card-background-color` that reference the palette

To change the theme, replace the Tier 1 palette values in `theme.css`. Three alternative themes are included as references:

| File | Look |
|---|---|
| `src/config/green.css` | Forest green + lime yellow (default) |
| `src/config/blue.css` | Navy blue + warm gold |
| `src/config/orange.css` | Orange + dark teal |

To switch themes, copy the contents of one of these files into `theme.css`:

```bash
cp src/config/blue.css src/config/theme.css
```

When creating a custom theme, follow the pattern used by the existing themes: keep `--palette-background` equal to `--palette-primary` for a cohesive monochromatic base, make `--palette-surface` a lighter shade and `--palette-interactive` a darker shade of the same hue, and pick a vibrant accent for `--palette-text-primary`.

If your theme changes the primary color significantly, also update the `THEME_COLOR` environment variable in `docker-compose.yml` to match `--palette-primary` — this controls the PWA status bar and splash screen color.

### App Icon

The PWA icon is served from two files:

- `src/images/icon-192.png` (192x192 px — home screen icon)
- `src/images/icon-512.png` (512x512 px — splash screen)

To use your own icon, replace these files with your own PNG images at the same sizes. The icons are referenced in `src/manifest.php` and should use the `any maskable` purpose format (safe area within the center 80% of the image).

## License

This project is licensed under the MIT License.

**Special Commercial Use Clause:**
This application is intended for use by individual beer festival organizers for their own events.

You **may**: use it for your own festival, modify it, and share it non-commercially.

You may **not**: sell, sublicense, or offer it as a paid service to other festival organizers. Contact the original author for a separate commercial license.
