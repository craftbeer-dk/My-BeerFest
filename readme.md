# My BeerFest

A Progressive Web App for beer festival management. Users can browse, filter, search, and rate beers. Ratings are stored locally in the browser. Optional server-side statistics logging is consent-based and GDPR-compliant.

## Features

- **Beer browser** — filter by style, brewery, country, session; sort by name, ABV, rating
- **Personal ratings** — rate beers 0.25-5.0, stored in localStorage, export/import via shareable URL
- **Favorites** — star beers to find them quickly
- **Floor maps** — optional festival map from your own SVG floor plans; beer cards link to their stand, with zoom, pan, a magnifier and favourite markers
- **Statistics dashboards** — personal stats (`/my_stats.php`) and organizer stats (`/stats.php`, auth-protected) with tabs for consent, usage & PWA installs, filters & search, favorites and ratings
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
| `ENABLE_STATISTICS_LOGGING` | Enable server-side rating, consent and interaction logs | `true` |
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
  "session": "Friday",
  "location": "Main Hall #12"
}
```

`location` is optional: where the beer is served. It is shown on the card and, with a floor map, links to the stand (see [Floor Maps](#floor-maps)).

`served_by` is optional: the bar or importer pouring another brewery's beer. Keep `brewery` as the real producer (it drives the Untappd lookup, country and stats) and set `served_by` to the stand, e.g. `"brewery": "The Bruery", "served_by": "Frederiks"`. The card shows "The Bruery · Frederiks"; the brewery filter lists both, and choosing Frederiks shows everything Frederiks serves. Sorting by brewery keeps a pourer's beers together.

## Floor Maps

Optional. An interactive map of the festival built from your own SVG floor plans. Without map files the feature is hidden.

**Setting up a map**

1. Make one SVG per hall/floor with each stand marked by a `data-stand` attribute (format below).
2. Put the files in `src/data/maps/` (mounted into the container with the rest of `src/data/`). No restart is needed: the server picks up changed files right away; visitors with the app cached see them after their next reload (the service worker serves the cached page first).
3. Give the beers in `beers.json` a `location` that matches a stand, e.g. `"location": "Main Hall #12"`.
4. Open the app: a **Map** button appears next to the search field, and each beer's location on its card becomes a link to its stand.

**What visitors see**

- The **Map** button opens the map full screen, with a tab per floor.
- Tapping a beer's location on its card opens the map zoomed to that stand, ringed.
- Pinch or scroll to zoom (or double-tap), and drag to pan. The magnifier button shows a loupe you can drag around; pinching on the loupe zooms only the loupe.
- Stands with one of the visitor's favourites get a star (only favourites in the selected session, when a session filter is set).
- The phone's back button or Escape closes the map.

**SVG format**

Files are shown as tabs in file-name order (prefix with `1-`, `2-`, … to control it).

A floor can have several layouts: SVGs with the same floor name (see `data-location` below) become one tab, and the app shows the layout that draws the floor largest on the current screen. For example `hall.svg` with the legend below for phones and `hall-wide.svg` with the legend beside it for landscape and desktop. It switches when the phone is rotated.

The SVG is shown as authored, legend and styling included, with zoom, pan and an optional magnifier (loupe) for reading small details without zooming. A beer's `location` links its card to a stand: tapping the location opens the map with the stand ringed.

Mark each stand with a `data-stand` attribute, usually on a `<g>` around its dot and number:

```xml
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 30" data-location="Main Hall">
  <!-- walls, tables, labels, legend … -->
  <g data-stand="12" data-location="Main Hall #12">
    <circle cx="20" cy="4" r="0.4" fill="#ea733d"/>
    <text x="20" y="4" font-size="0.44" text-anchor="middle" dy="0.35em">12</text>
  </g>
</svg>
```

| Attribute | Where | Purpose |
|---|---|---|
| `data-location` | root `<svg>` | Floor name shown on the tab. Falls back to `<title>`, then the file name (`2-beer-garden.svg` → "Beer garden"). |
| `data-stand` | stand element | Stand number or label (required). Other elements with the same stand, e.g. a legend entry, can repeat it. |
| `data-location` | stand element | Full stand location. Defaults to `<floor name> #<stand>`. |
| `class="page"` or `class="background"` | top-level background rect | Left out when fitting the map to the screen; its fill becomes the colour around the map. |
| `class="map-overview"` | any element | Shown only when zoomed out, e.g. a "13–18" label per row of stands. |
| `class="map-detail"` | any element | Shown only when zoomed in far enough to read it (stands drawn about 12 px or larger) and inside the magnifier, e.g. the stand numbers. |

Other viewers ignore the two zoom classes and show everything, so the SVG still works standalone. A plan that is much taller than the screen (e.g. with a legend below it on a phone) opens fitted to the width and can be panned.

A beer's `location` is matched case-insensitively, ignoring `#`:

1. the stand's location (`"Main Hall #12"`, `"main hall 12"`) → that stand is ringed;
2. a bare stand label (`"12"`) if only one floor has that stand;
3. a floor name (`"Main Hall"`) → the map opens on that floor;
4. anything else → shown as plain text without a map link.

**No ready-made SVG?** Any floor plan picture works: create an SVG with the picture as an `<image>` and draw the stand dots on top, e.g. in Inkscape. Reference the picture by path (put it in `custom/`, e.g. `<image href="/custom/plan.png" …/>`) rather than embedding it as base64, since the SVG is inlined into every page load.

Each map is rendered in its own shadow DOM, so its CSS and ids can't clash with the app. The server (`src/map_loader.php`) only removes what could run code or load external resources: scripts, event handlers, animations, `foreignObject`, link targets, `@import` and external URLs (`data:` URIs and same-origin image paths are allowed). Raw files under `/data/maps/` are not served.

**Limits and troubleshooting**

- At most 10 SVG files of up to 2 MB each. The maps are inlined into the main page, so keep them lean (e.g. subset or leave out embedded fonts; the app already loads Inter).
- A file that isn't valid SVG (malformed XML, no `viewBox` or `width`/`height`, entity declarations) is skipped and logged as `MyBeerFest: skipping invalid map file …` in the PHP container's log (`docker compose logs php`).
- A beer's location shows as plain text instead of a link when it matches no stand or floor name; check the spelling against the stand's `data-location` (or `<floor name> #<stand>`).
- A stand number used on several floors (e.g. two halls both with stand 1) can't be matched by number alone; use the full location.

## Testing

Run the automated smoke tests (requires the app to be running):

```bash
./tests/smoke_test.sh
```

This runs ~85 assertions covering page loads, API validation, auth gates, XSS escaping, and CSRF protection. Override defaults with env vars:

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
  log_event.php       # POST API — log consent-gated interaction events
  beer_catalog.php    # Shared beer catalog loading and id validation
  map_loader.php      # Loads and sanitizes optional floor-map SVGs
  health.php          # GET /health — liveness probe (200/503)
  my_stats.php        # Personal statistics page
  privacy-policy.php  # GDPR privacy policy
  manifest.php        # PWA web manifest
  sw.js               # Service Worker
  data/               # beers.json, flags.json, optional maps/*.svg
  lang/               # Translation files (.conf)
  config/             # CSS theme files
nginx/
  nginx.conf          # Nginx config with auth and PHP-FPM proxy
  entrypoint.sh       # Generates .htpasswd files from env vars
tests/
  smoke_test.sh       # Automated smoke & security tests
  map_loader_test.php # Map sanitizer unit tests (run by smoke_test.sh)
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

This project is licensed under the [Elastic License 2.0 (ELv2)](LICENSE.md) — a source-available license.

You **may**: use, copy, modify, and distribute the software, including running it commercially for your own beer festival.

You may **not**: provide the software to third parties as a hosted or managed service (e.g. offering it to other festival organizers as a paid platform). You also may not remove or alter the license, copyright, or other notices.

For uses outside these terms, contact the author for a separate commercial license.
