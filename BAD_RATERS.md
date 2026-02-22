# Bad Rater Detection

Definitions and detection rules for identifying suspicious rating behaviour in the `ratings.log` NDJSON file.

Each log entry contains: `timestamp`, `timestamp_unix_ms`, `session_id`, `beer_id`, `beer_name`, `rating`, `brewery`, `style`, `country`, `alc`, `session`.

Ratings are deduplicated per user per beer (latest wins), so all raw entries — including overwrites — are available for analysis.

---

## 1. Spam Rater

**Definition:** A user who submits an unrealistically high number of ratings in a short time window, suggesting they are not genuinely tasting the beers.

**Detection rule:**
- Count ratings per `session_id` within a sliding 10-minute window.
- Flag if **20+ ratings in any 10-minute window**.

**Why it matters:** At a festival, physically tasting 20 beers in 10 minutes is not realistic. This likely indicates someone clicking through the list without drinking.

---

## 2. Flat-Liner

**Definition:** A user who gives the exact same score to a large number of beers, showing no discrimination between different beers.

**Detection rule:**
- Group deduplicated ratings by `session_id`.
- Find the most common rating value for that user.
- Flag if the user has **10+ ratings** and **80%+ are the same value**.

**Why it matters:** Real tasters naturally have variance in their scores. A wall of identical ratings (especially all 5.00 or all 0.25) suggests the user is not evaluating each beer individually.

---

## 3. Blitzer

**Definition:** A user who submits consecutive ratings with suspiciously short intervals, faster than anyone could realistically taste and evaluate a beer.

**Detection rule:**
- Sort all raw entries for a `session_id` by `timestamp_unix_ms`.
- Calculate the gap between consecutive ratings.
- Flag if **5+ consecutive gaps are under 15 seconds**.

**Why it matters:** Even a quick sip and a rating takes at least 15-20 seconds. Sub-15-second bursts suggest automated or mindless clicking.

---

## 4. Extremist

**Definition:** A user who almost exclusively rates at the extreme ends of the scale (minimum or maximum), never using the middle range.

**Detection rule:**
- Group deduplicated ratings by `session_id` (excluding `rating = 0` which means "no rating / tasted without score").
- Calculate the percentage of ratings that are either 0.25 (minimum) or 5.00 (maximum).
- Flag if the user has **10+ scored ratings** and **80%+ are extreme values** (0.25 or 5.00).

**Why it matters:** Genuine raters use the full scale. Persistent extreme scoring suggests trolling, spite-rating, or indiscriminate fan-rating.

---

## 5. Flip-Flopper

**Definition:** A user who repeatedly re-rates the same beer many times, suggesting they are playing with the UI rather than submitting a considered rating.

**Detection rule:**
- Count raw log entries (before deduplication) per `session_id` + `beer_id` pair.
- Flag if **any single beer has 5+ rating entries** from the same user.

**Why it matters:** Changing a rating once or twice is normal (e.g. correcting a mistake). Re-rating the same beer 5+ times indicates either UI abuse or deliberate log flooding.

---

## 6. Outlier Bomber

**Definition:** A user whose ratings systematically and significantly deviate from the festival-wide consensus, suggesting they may be intentionally skewing results.

**Detection rule:**
- Calculate the festival-wide mean rating per `beer_id` (from all deduplicated, scored ratings).
- For each user with **10+ scored ratings**, calculate their average signed deviation from the per-beer festival mean.
- Flag if the user's **average deviation is more than 2.0 points** above or below the consensus.

**Why it matters:** Consistent large deviations across many beers suggest the user is not reacting to individual beer quality but rather applying a blanket bias — either inflating everything or trashing everything.

---

## Severity Levels

| Pattern | Severity | Likely Cause |
|---|---|---|
| Spam Rater | High | Bot or bulk-clicking |
| Blitzer | High | Automated or mindless input |
| Flat-Liner | Medium | Lazy rating or trolling |
| Extremist | Medium | Trolling or fan-rating |
| Flip-Flopper | Low | UI experimentation |
| Outlier Bomber | Low-Medium | Personal bias or spite |

A single user can trigger multiple patterns. Users flagged by 2+ patterns simultaneously should be considered higher confidence bad raters.

---

## Recommended Actions

- **Display only:** Show flagged sessions on the stats dashboard with their triggered patterns. Let the organiser decide.
- **Soft exclusion:** Optionally exclude flagged sessions from aggregated statistics (mean ratings, top beers) while keeping their data in the log.
- **No deletion:** Never automatically delete log entries. The raw data should always be preserved for manual review.
