# My Beerfest

This is a simple web application designed for a beer festival, allowing users to browse a list of beers, filter and sort them, rate them, and manage their personal ratings. The application is built with PHP for server-side logic and HTML/CSS/JavaScript for the frontend, with a focus on functionality and usability.

## Features

* **Beer Listing:** Displays a list of beers fetched from a JSON object.

* **Filtering:** Filter beers by style, brewery, country, and session.

* **Sorting:** Sort beers by name, alcohol percentage, global rating, and your personal rating (high to low, low to high).

* **User Ratings:** Users can rate beers from 0.25 to 5 in increments of 0.25. These ratings are stored locally in the browser's `localStorage`.

* **Rating Management:** Filters to show only "My Rated Beers" or "Unrated Beers".

* **Untappd Link:** Each beer card includes a link to its Untappd page.

* **Rating Export/Import:** A feature to copy a shareable URL containing all your ratings, which can be used for backup or to import ratings on another device.

* **Collapsible Sections:** Filter and sorting sections are collapsible, defaulting to collapsed on mobile for better usability.

* **Persistent Settings:** Filter and sorting selections, along with their collapsed states, are saved in `localStorage` for a consistent user experience across sessions.

* **Multi-language Support:** The application's display text can be configured via environment variables to switch between different languages (Danish, English, Swedish, Norwegian, German, French, Polish, Czech).

## Configuration

The application's behavior can be configured using environment variables.

* `FESTIVAL_TITLE`: Sets the title displayed at the top of the application.
  * *Example:* `FESTIVAL_TITLE="My Awesome Beer Fest"`

* `BEER_DATA_URL`: The URL where the `beers.json` data can be fetched from.
  * *Example (for Docker Compose):* `BEER_DATA_URL="http://nginx/data/beers.json"`

* `FESTIVAL_INFO_TEXT`: A short, configurable text displayed in the information container at the bottom of the page.
  * *Example:* `FESTIVAL_INFO_TEXT="Enjoy your time at the festival!"`

* `APP_LANGUAGE`: Sets the language for the application's text. Supported values are `da` (Danish), `en` (English), `sv` (Swedish), `no` (Norwegian), `de` (German), `fr` (French), `pl` (Polish), `cs` (Czech).
  * *Example:* `APP_LANGUAGE="en"`

## Data Structure (beers.json)

The `beers.json` file should be an array of beer objects, each with the following structure:

```json
[
  {
    "id": "unique-beer-id-1",
    "name": "Super delight",
    "brewery": "Almond brewery",
    "alc": 8.5,
    "style": "New England IPA",
    "country": "Sweden",
    "untappd": "[https://untappd.com/beer/525252](https://untappd.com/beer/525252)",
    "rating": 4.2,
    "session": "Friday"
  },
  {
    "id": "unique-beer-id-2",
    "name": "Hazy Wonder",
    "brewery": "Cloudy Brews",
    "alc": 6.8,
    "style": "Hazy IPA",
    "country": "USA",
    "untappd": "[https://untappd.com/beer/123456](https://untappd.com/beer/123456)",
    "rating": 4.5,
    "session": "Saturday"
  }
]
```
Note: Each beer must have a unique id field.

## Deployment with Docker Compose

This application is designed for easy deployment using Docker Compose, which sets up an Nginx web server and a PHP-FPM application server. The beers.json file is served by the Nginx container itself.

Prerequisites
- Docker Engine and Docker Compose installed on your system.

Deploy:
```
docker compose up -d
```

You can now access the application in your browser at http://127.0.0.1:8888

## License

This project is licensed under the MIT License.

**Special Commercial Use Clause:**
While the MIT License generally permits commercial use and distribution, this application, "My BeerFest", is specifically intended for use by individual beer festival organizers for their *own* beer festival events.

You are permitted to:
* Use this application for your own commercial beer festival.
* Modify the application for your own use.
* Distribute modified or unmodified versions for non-commercial purposes (e.g., sharing with other hobbyists).

You are **NOT** permitted to:
* Sell, sublicense, or otherwise commercially redistribute this application (modified or unmodified) to *other* beer festival organizers or third parties for their use in separate commercial events.
* Offer this application as a paid service to other beer festival organizers.

For any use beyond the scope of your own beer festival, please contact the original author for a separate commercial license agreement.