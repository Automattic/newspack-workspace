# Tab 1: Audience Overview — Formulas

Reference: see `formulas/README.md` for BigQuery conventions used throughout (PARAM() shorthand, partition filtering, user_id fallback, etc.). See `../event-reference.md` for the canonical event spec, particularly the global event params (`logged_in`, `is_newsletter_subscriber`, `is_subscriber`, `is_donor`, `categories`, `author`) that this tab relies on heavily.

## Scope

Tab 1 answers "How big is my reach?" It's the entry tab for the publisher — the first thing they see when they open Insights. These are pure audience-composition and reach metrics.

Unlike Tabs 4, 5, 7 which have Direct/Influenced attribution splits, Tab 1 has no attribution concept.

**v1 ships on the GA4 Data API.** BigQuery is the v1.1 swap target (pending the BQ proxy in NPPD-1630). Each metric below carries both its GA4 Data API query (active in v1) and its BigQuery query (the swap target, unchanged from the original BQ-only design). See the backend dispatch section for how the orchestrator chooses.

## Backend dispatch (v1: GA4 Data API, v1.1: BigQuery)

This tab ships v1 powered by GA4 Data API. v1.1 swaps to BigQuery via the proxy in NPPD-1630.

Dispatch: `Audience_Metric` orchestrator reads constant `NEWSPACK_INSIGHTS_AUDIENCE_USE_GA4`. When true, calls the GA4 Data API path. When false, calls the BQ proxy path.

Constant defaults true. Flip false once this tab's BQ catalog ships and has been validated against GA4 baseline numbers.

GA4 connection: reuses Newspack's existing Google OAuth (`\Newspack\Google_OAuth::get_oauth2_credentials()`). Property ID detected via Site Kit's stored settings (`googlesitekit_analytics-4` option). No publisher reconnection needed for sites that already have Newspack Google connection configured. Reference implementation: `Automattic/newspack-gate-intelligence` on github.a8c.com (`includes/class-oauth.php`, `includes/class-ga4.php`). These will be extracted into newspack-plugin in a separate ticket; the orchestrator references the constant named above exactly.

Graceful failure: if the publisher's GA4 connection is missing, the entire tab renders a single banner ("Connect Google Analytics in Newspack → Connections to see this tab"). For per-metric custom dimension misses, render the affected card with an overlay ("Custom dimension `<param>` not detected — see [setup docs]") while the rest of the tab works normally.

**Custom dimension detection (graceful failure mechanism):** GA4 custom dimensions are registered per property. For any metric below tagged with a custom dimension dependency, the orchestrator issues the `runReport` with the `customEvent:<param>` dimension; if GA4 returns an empty result set for an otherwise valid query (no rows, no error), the orchestrator treats that as the "custom dimension not registered" condition and returns the overlay payload for that card. v1.1 replaces this per-call inference with a boot-time probe that warns admins up front.

**Pageview counting:** GA4 queries use the predefined `screenPageViews` metric rather than `eventCount` filtered to `eventName = 'page_view'`. For web-only publishers the two return identical numbers, and `screenPageViews` is the conventional Data API shape with no filter overhead.

## Conventions specific to this tab

- **Reader:** distinct identity. In GA4 this is the `totalUsers` metric. In BQ, `COUNT(DISTINCT COALESCE(user_id, user_pseudo_id))`.
- **Session:** in GA4 the `sessions` metric. In BQ a session key is `CONCAT(user_pseudo_id, '|', ga_session_id)` — `ga_session_id` is NOT globally unique on its own.
- **GA4 date range:** examples below use `{"startDate": "30daysAgo", "endDate": "today"}`. The orchestrator substitutes the user's selected window. `today` includes partial intraday data, matching GA4's reporting convention; the BQ variant documents its own intraday handling per `README.md`.
- **GA4 custom dimensions:** referenced in the `customEvent:<param>` form (e.g. `customEvent:post_id`, `customEvent:author`).

## Section: Reach

### Active Readers (in window)

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none

Distinct users who fired any event in the window.

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "metrics": [{"name": "totalUsers"}]
}
```

**BigQuery query (v1.1 swap target):**

```sql
SELECT COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS active_readers
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date;
```

Notes:
- GA4's `totalUsers` counts any user with an event in the window — including `page_view`, `scroll`, `session_start`, etc. Effectively "anyone who showed up," matching the BQ "any event" definition. For "reader who actually read something," the BQ version filters `event_name = 'page_view'`; the GA4 equivalent would add a `dimensionFilter` on `eventName`.
- This is the headline number on the tab. Display prominently.
- GA4 `totalUsers` and the BQ distinct-identity count can differ slightly: GA4 applies its own identity reconciliation (and Google Signals if enabled), while the BQ count is strictly cookie/`user_id`-scoped. Document the small expected drift when validating the v1.1 swap.

> **Note:** Sessions is no longer surfaced as a standalone scorecard (it's a
> definitional middle-ground number). The `sessions` GA4 metric is still queried
> internally as the numerator of Avg Sessions per Reader.

### Pageviews (in window)

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "metrics": [{"name": "screenPageViews"}]
}
```

**BigQuery query (v1.1 swap target):**

```sql
SELECT COUNT(*) AS pageviews
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND event_name = 'page_view';
```

Notes:
- `screenPageViews` is the predefined Data API metric; it equals the BQ `COUNT(*)` of `page_view` events for web-only publishers without the filter overhead.
- One `page_view` event per page load. AMP and standard pages both fire it.

### Avg Sessions per Reader

**v1 backend**: GA4 Data API (arithmetic)
**Custom dimension dependency**: none

```
avg_sessions_per_reader = sessions / active_readers
```

Plain arithmetic from the two metrics above. In v1 both inputs come from GA4 (`sessions` / `totalUsers`); in v1.1 both come from BQ.

Notes:
- Hyperlocal sites tend to see 2-4 sessions per reader per month. National sites trend lower (1.5-2).

### Newsletter Signups

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none (filters on the standard `eventName`)

Count of `np_newsletter_subscribed` events in the window. That event fires on
every successful newsletter signup via any Newspack method (Registration block,
Newsletter Subscription Form block, "Create an account" modal, My Account →
Newsletters).

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "metrics": [{"name": "eventCount"}],
  "dimensionFilter": {
    "filter": {
      "fieldName": "eventName",
      "stringFilter": {"matchType": "EXACT", "value": "np_newsletter_subscribed"}
    }
  }
}
```

Single metric value (`eventCount`) = signups for the window. Comparison is handled
by the orchestrator's standard current/previous window pass (same as the other
scorecards); no special compare payload is needed.

**BigQuery query (v1.1 swap target):**

```sql
SELECT COUNT(*) AS newsletter_signups
FROM `{project}.{dataset}.events_*`
WHERE event_name = 'np_newsletter_subscribed'
  AND _TABLE_SUFFIX BETWEEN @start_date AND @end_date;
```

Notes:
- **Signup events, not unique readers.** A reader who signs up to one list, then another a week later, fires two events (counts as two). A reader who selects multiple lists in one submission fires one event. This is the right framing for "signup activity this period"; a deduplicated person count is a separate v1.1 metric.
- **Newspack-method signups only.** Direct-from-ESP embed forms placed outside Newspack flows are not captured — document as an asterisk.
- **Zero is valid.** New publisher with no events yet → 0, rendered normally (not an error). No GA4 connection is handled by the tab-level `oauth_not_connected` flow.

## Section: Time trends

> **Note:** The standalone Active Readers Over Time line was dropped — New vs
> Returning Over Time carries the same daily total and adds the more useful
> new/returning split.

### New vs Returning Readers Over Time

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none

LineChart, two series (stacked or paired).

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "date"}, {"name": "newVsReturning"}],
  "metrics": [{"name": "totalUsers"}],
  "orderBys": [{"dimension": {"dimensionName": "date"}}]
}
```

**BigQuery query (v1.1 swap target):**

```sql
WITH daily_readers AS (
  SELECT
    event_date AS day,
    COALESCE(user_id, user_pseudo_id) AS uid
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  GROUP BY event_date, uid
),
first_seen AS (
  SELECT
    user_pseudo_id,
    MIN(event_date) AS first_seen_date
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN
    FORMAT_DATE('%Y%m%d', DATE_SUB(PARSE_DATE('%Y%m%d', @start_date), INTERVAL 90 DAY))
    AND @end_date
  GROUP BY user_pseudo_id
)
SELECT
  d.day,
  COUNT(DISTINCT IF(f.first_seen_date = d.day, d.uid, NULL)) AS new_readers,
  COUNT(DISTINCT IF(f.first_seen_date < d.day, d.uid, NULL)) AS returning_readers
FROM daily_readers d
LEFT JOIN first_seen f ON d.uid = f.user_pseudo_id
GROUP BY d.day
ORDER BY d.day;
```

Notes:
- Same definitional difference as New vs Returning Counts: GA4 pivots on its 540-day `newVsReturning`; the BQ version uses a 90-day `first_seen` lookback.
- **Two lines, not one.** The orchestrator pivots the long (date × newVsReturning) result into one wide row per date — `{ date, new, returning }` — so the LineChart renders two color-coded series on a shared x-axis (with a legend and a hover panel showing both values at the hovered date). Anything GA4 returns that isn't "returning" (including its occasional empty bucket) folds into `new`.
- BQ caveat preserved: readers who first appeared > 90 days ago AND haven't been back in 90 days count incorrectly as "new" on their first day. Acceptable v1.1 approximation; document.

### Readership by Day of Week

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "dayOfWeekName"}],
  "metrics": [{"name": "totalUsers"}]
}
```

**BigQuery query (v1.1 swap target):**

```sql
SELECT
  EXTRACT(DAYOFWEEK FROM PARSE_DATE('%Y%m%d', event_date)) AS day_of_week,
  COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS active_readers
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
GROUP BY day_of_week
ORDER BY day_of_week;
```

Notes:
- GA4's `dayOfWeekName` returns localized day names directly. (The `dayOfWeek` dimension returns 0-6 with Sunday=0 if numeric ordering is preferred.) BQ's `DAYOFWEEK` returns 1-7 (Sunday=1) — map either to a consistent display order.
- For "Avg readers by day of week" rather than total, divide each by the number of occurrences of that day in the window.

### Readership by Hour of Day

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "hour"}],
  "metrics": [{"name": "totalUsers"}]
}
```

**BigQuery query (v1.1 swap target):**

```sql
SELECT
  EXTRACT(HOUR FROM TIMESTAMP_MICROS(event_timestamp)) AS hour_of_day,
  COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS active_readers
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
GROUP BY hour_of_day
ORDER BY hour_of_day;
```

Notes:
- GA4's `hour` dimension returns 00–23 **in the property's configured reporting time zone** — which is the publisher-friendly behavior already. The BQ variant returns UTC and needs the publisher timezone offset applied manually (per the Insights timezone setting). When validating the swap, account for this offset difference.
- Hourly readership pattern is one of the most operationally useful charts — informs newsletter send times, social posting cadence, push notification timing.

## Section: Traffic sources

### Traffic Sources Breakdown (PieChart)

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "sessionDefaultChannelGroup"}],
  "metrics": [{"name": "totalUsers"}],
  "orderBys": [{"metric": {"metricName": "totalUsers"}, "desc": true}],
  "limit": 20
}
```

**BigQuery query (v1.1 swap target):**

```sql
SELECT
  COALESCE(NULLIF(traffic_source.medium, ''), '(direct)') AS medium,
  COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS readers
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
GROUP BY medium
ORDER BY readers DESC
LIMIT 20;
```

Notes:
- GA4's `sessionDefaultChannelGroup` returns clean channel labels (`Organic Search`, `Direct`, `Email`, `Social`, `Paid Search`, `Referral`, etc.) — higher-level and tidier than the BQ `traffic_source.medium` values (`organic`, `referral`, `email`, `cpc`, `(direct)`). The two won't label-match exactly; both are PieChart-friendly cardinality. If exact parity with the BQ version is required at swap time, GA4's `sessionMedium` dimension is the closer analogue.
- Auto-grouping in PieChart handles the long tail.

### Top Campaigns (Table)

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [
    {"name": "sessionSource"},
    {"name": "sessionMedium"},
    {"name": "sessionCampaignName"}
  ],
  "metrics": [{"name": "totalUsers"}, {"name": "sessions"}],
  "orderBys": [{"metric": {"metricName": "totalUsers"}, "desc": true}],
  "limit": 50
}
```

**BigQuery query (v1.1 swap target):**

```sql
SELECT
  traffic_source.source AS source,
  traffic_source.medium AS medium,
  COALESCE(NULLIF(traffic_source.name, ''), '(no campaign)') AS campaign,
  COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS readers,
  COUNT(DISTINCT CONCAT(user_pseudo_id, '|', CAST(PARAM_INT('ga_session_id') AS STRING))) AS sessions
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND traffic_source.source IS NOT NULL
GROUP BY source, medium, campaign
ORDER BY readers DESC
LIMIT 50;
```

Notes:
- Three-dimension grouping gives full attribution context. UTM-tagged links populate `sessionSource` / `sessionMedium` / `sessionCampaignName` in GA4 (and `source` / `medium` / `name` in BQ).
- GA4 reports empty campaign as `(not set)`; map to `(no campaign)` for display parity with the BQ version.
- Limit 50 rows; long tail caught by sorting.

## Section: Audience composition

### Device Breakdown (PieChart)

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "deviceCategory"}],
  "metrics": [{"name": "totalUsers"}],
  "orderBys": [{"metric": {"metricName": "totalUsers"}, "desc": true}]
}
```

**BigQuery query (v1.1 swap target):**

```sql
SELECT
  COALESCE(NULLIF(device.category, ''), 'unknown') AS device,
  COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS readers
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
GROUP BY device
ORDER BY readers DESC;
```

Notes:
- `deviceCategory` values: `mobile`, `desktop`, `tablet`, `smart tv`. Same value set as BQ's `device.category`.
- Mobile typically dominates for news (60-80% for most publishers).

### Newsletter Subscriber Composition (PieChart)

**v1 backend**: GA4 Data API
**Custom dimension dependency**: `is_newsletter_subscriber`
**Overlay if missing**: "Newsletter status custom dimension not detected — see [setup docs]"

Slice of active readers in window: newsletter subscribers vs not.

**GA4 Data API query (v1, active):**

A single `runReport` grouped by the `is_newsletter_subscriber` custom dimension — the two dimension rows (yes / no) ARE the pie slices.

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "customEvent:is_newsletter_subscriber"}],
  "metrics": [{"name": "totalUsers"}]
}
```

**BigQuery query (v1.1 swap target):**

```sql
WITH active_with_status AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    MAX(IF(PARAM('is_newsletter_subscriber') = 'yes', 1, 0)) AS is_newsletter_subscriber
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  GROUP BY uid
)
SELECT
  CASE WHEN is_newsletter_subscriber = 1 THEN 'newsletter subscriber' ELSE 'not subscribed' END AS segment,
  COUNT(*) AS reader_count
FROM active_with_status
GROUP BY segment;
```

Notes:
- Collapse non-`yes` dimension values into "not subscribed" at the application layer for the two-slice pie.

### Logged-In vs Anonymous Composition (PieChart)

**v1 backend**: GA4 Data API
**Custom dimension dependency**: `logged_in`
**Overlay if missing**: "Logged-in status custom dimension not detected — see [setup docs]"

**GA4 Data API query (v1, active):**

A single `runReport` grouped by the `logged_in` custom dimension — the dimension rows (yes / no) are the slices.

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "customEvent:logged_in"}],
  "metrics": [{"name": "totalUsers"}]
}
```

**BigQuery query (v1.1 swap target):**

```sql
WITH active_with_status AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    MAX(IF(PARAM('logged_in') = 'yes', 1, 0)) AS is_logged_in
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  GROUP BY uid
)
SELECT
  CASE WHEN is_logged_in = 1 THEN 'logged in' ELSE 'anonymous' END AS segment,
  COUNT(*) AS reader_count
FROM active_with_status
GROUP BY segment;
```

Notes:
- Collapse non-`yes` dimension values into "anonymous" for the two-slice pie.

### Supporter Type (PieChart)

**v1 backend**: GA4 Data API
**Custom dimension dependency**: `is_subscriber`, `is_donor` (both required)
**Overlay if missing**: "Supporter custom dimension not detected — see [setup docs]" (lists whichever of `is_subscriber` / `is_donor` is missing)

Composition of the **logged-in** audience by support status. The slices adapt to
which products the publisher actually sells; the orchestrator detects this
side-effect-free at compute time:

- **Donations** configured when the saved donation-product option (`newspack_donation_product_id`) resolves to a product.
- **Subscriptions** configured when at least one published WooCommerce Subscriptions product (`subscription` / `variable-subscription`) exists.

Slice sets:

- **Both products:** Subscriber only, Donor only, Both, Logged-in only
- **Subscriptions only:** Subscriber, Logged-in only
- **Donations only:** Donor, Logged-in only
- **Neither:** the metric is hidden entirely — `hidden_in_v1` payload with reason "no subscription or donation products configured".

**GA4 Data API query (v1, active):**

Grouped by `is_subscriber` × `is_donor`. `is_subscriber` is required present
(`.+`) to scope the report to logged-in readers without a third custom-dimension
dependency; the four (yes/no × yes/no) buckets fold into the slice set above.

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "customEvent:is_subscriber"}, {"name": "customEvent:is_donor"}],
  "metrics": [{"name": "totalUsers"}],
  "dimensionFilter": {
    "filter": {"fieldName": "customEvent:is_subscriber", "stringFilter": {"matchType": "FULL_REGEXP", "value": ".+"}}
  }
}
```

**BigQuery query (v1.1 swap target):**

```sql
WITH logged_in_readers AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    MAX(IF(PARAM('is_subscriber') = 'yes', 1, 0)) AS is_sub,
    MAX(IF(PARAM('is_donor') = 'yes', 1, 0)) AS is_donor
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND PARAM('logged_in') = 'yes'
  GROUP BY uid
)
SELECT
  CASE
    WHEN is_sub = 1 AND is_donor = 1 THEN 'Both'
    WHEN is_sub = 1 THEN 'Subscriber only'
    WHEN is_donor = 1 THEN 'Donor only'
    ELSE 'Logged-in only'
  END AS segment,
  COUNT(*) AS reader_count
FROM logged_in_readers
GROUP BY segment;
```

Notes:
- The BQ form scopes by `logged_in = 'yes'`; the GA4 form leans on `is_subscriber` being a logged-in-only param. Collapse the four buckets per the active slice set (e.g. on a subscriptions-only site, "Donor only" folds into "Logged-in only" and "Both" folds into "Subscriber").

## Section: Geographic

### Top Regions/States

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "country"}, {"name": "region"}],
  "metrics": [{"name": "totalUsers"}],
  "orderBys": [{"metric": {"metricName": "totalUsers"}, "desc": true}],
  "limit": 50
}
```

**BigQuery query (v1.1 swap target):**

```sql
SELECT
  COALESCE(NULLIF(geo.country, ''), 'unknown') AS country,
  COALESCE(NULLIF(geo.region, ''), 'unknown') AS region,
  COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS readers
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND geo.country IS NOT NULL
GROUP BY country, region
ORDER BY readers DESC
LIMIT 50;
```

Notes:
- GA4's `region` returns subdivision names following ISO 3166-2 for most countries; US states are "Illinois", "California", etc. — same as BQ's `geo.region`.

### Top Cities

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "country"}, {"name": "region"}, {"name": "city"}],
  "metrics": [{"name": "totalUsers"}],
  "orderBys": [{"metric": {"metricName": "totalUsers"}, "desc": true}],
  "limit": 50
}
```

**BigQuery query (v1.1 swap target):**

```sql
SELECT
  COALESCE(NULLIF(geo.country, ''), 'unknown') AS country,
  COALESCE(NULLIF(geo.region, ''), 'unknown') AS region,
  COALESCE(NULLIF(geo.city, ''), 'unknown') AS city,
  COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS readers
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND geo.city IS NOT NULL
GROUP BY country, region, city
ORDER BY readers DESC
LIMIT 50;
```

Notes:
- City is the finest standard GA4 granularity. ZIP codes, neighborhoods, lat/lng are NOT available without custom event implementation. Document this limitation in UI.

### Top Pages

Single content table, ranked by unique readers, surfacing both reader and
pageview counts (a superset of the old pageviews-only table, which was cut as
redundant — the same articles dominated both).

**v1 backend**: GA4 Data API
**Custom dimension dependency**: `post_id` (degrades — does not hide — if missing)
**Overlay if missing**: "Singular content filter unavailable; showing all URLs"

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [
    {"name": "customEvent:post_id"},
    {"name": "pagePath"},
    {"name": "pageTitle"}
  ],
  "metrics": [{"name": "totalUsers"}, {"name": "screenPageViews"}],
  "dimensionFilter": {
    "filter": {
      "fieldName": "customEvent:post_id",
      "stringFilter": {"matchType": "FULL_REGEXP", "value": ".+"}
    }
  },
  "orderBys": [{"metric": {"metricName": "totalUsers"}, "desc": true}],
  "limit": 50
}
```

**BigQuery query (v1.1 swap target):**

```sql
SELECT
  PARAM_INT('post_id') AS post_id,
  PARAM('page_location') AS page_url,
  PARAM('page_title') AS page_title,
  COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS unique_readers,
  COUNT(*) AS pageviews
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND event_name = 'page_view'
  AND PARAM_INT('post_id') IS NOT NULL  -- singular content only
GROUP BY post_id, page_url, page_title
ORDER BY unique_readers DESC
LIMIT 50;
```

Notes:
- **Degrade, don't hide.** If `customEvent:post_id` isn't registered (empty result with the regexp filter), the orchestrator re-issues the query WITHOUT the `dimensionFilter` and on `pagePath`/`pageTitle` only, then renders the card with the "showing all URLs" overlay.
- Ranked by unique readers; the pageviews column rides along. Reader-count ranking often differs from raw pageview ranking — a viral piece pulls many one-time readers; a recurring column pulls repeat readers from a smaller audience.
- GA4's `pagePath` is path-only (no domain); BQ's `page_location` is the full URL — trim domain client-side for parity.
- For canonical post titles (surviving edits), v1.1 may add a wp_posts join via post_id.

### Top Authors by Reader Count

**v1 backend**: GA4 Data API
**Custom dimension dependency**: `author`, `post_id`
**Overlay if missing**: "Author tracking not detected — see [setup docs]"

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "customEvent:author"}],
  "metrics": [{"name": "totalUsers"}, {"name": "screenPageViews"}],
  "dimensionFilter": {
    "andGroup": {
      "expressions": [
        {"filter": {"fieldName": "customEvent:post_id", "stringFilter": {"matchType": "FULL_REGEXP", "value": ".+"}}},
        {"filter": {"fieldName": "customEvent:author", "stringFilter": {"matchType": "FULL_REGEXP", "value": ".+"}}}
      ]
    }
  },
  "orderBys": [{"metric": {"metricName": "totalUsers"}, "desc": true}],
  "limit": 25
}
```

**BigQuery query (v1.1 swap target):**

```sql
SELECT
  PARAM('author') AS author,
  COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS unique_readers,
  COUNT(*) AS pageviews
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND event_name = 'page_view'
  AND PARAM_INT('post_id') IS NOT NULL  -- singular content only
  AND PARAM('author') IS NOT NULL
  AND PARAM('author') != ''
GROUP BY author
ORDER BY unique_readers DESC
LIMIT 25;
```

Notes:
- This card depends on `author` specifically; if `customEvent:author` is unregistered (empty result), render the overlay rather than degrading — there's no useful author view without it.
- For multi-author pages (`author` is comma-separated in some cases), v1 treats the entire string as one author. v1.1 should split and credit each.
- For authoritative current author names (vs whatever was set at page view time), v1.1 may add a wp_posts → wp_users join via post_id.

### Top Categories (Table)

**v1 backend**: BQ-only (hidden in v1)
**Custom dimension dependency**: `categories` (comma-separated; needs SPLIT + UNNEST)

> **Hidden in v1.** Renders when the BQ catalog ships per NPPD-1630. The orchestrator returns a `hidden_in_v1` payload (reason "available when BigQuery catalog ships") and the UI skip-renders the card.

**Why BQ-only:** `categories` is a comma-separated event param. The GA4 Data API can't `SPLIT`/`UNNEST` a custom dimension, so an exact-string match would treat "Politics, Local" as a distinct category from "Politics" — double-counting. Needs raw rows.

**BigQuery query (v1.1 swap target):**

```sql
SELECT
  TRIM(cat) AS category,
  COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS unique_readers,
  COUNTIF(event_name = 'page_view') AS pageviews
FROM `{project}.{dataset}.events_*`,
UNNEST(SPLIT(COALESCE(PARAM('categories'), ''), ',')) AS cat
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND PARAM_INT('post_id') IS NOT NULL
  AND TRIM(cat) != ''
GROUP BY category
ORDER BY unique_readers DESC
LIMIT 10;
```

## Section: Audience composition (BQ-only, hidden in v1)

### Returning Reader Rate (strict pre-window definition)

**v1 backend**: BQ-only (hidden in v1)
**Custom dimension dependency**: n/a (needs raw event timestamps)

> Hidden in v1. Will appear when BQ catalog ships per NPPD-1630.

What it measures: % of active readers in window whose `user_pseudo_id` first appeared BEFORE the window start.

**BigQuery query (v1.1 swap target):**

```sql
WITH active_in_window AS (
  SELECT DISTINCT user_pseudo_id
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
),
returning AS (
  SELECT DISTINCT user_pseudo_id
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX < @start_date
    AND user_pseudo_id IN (SELECT user_pseudo_id FROM active_in_window)
)
SELECT
  COUNT(DISTINCT returning.user_pseudo_id) /
  NULLIF(COUNT(DISTINCT active_in_window.user_pseudo_id), 0)
    AS returning_reader_rate
FROM active_in_window
LEFT JOIN returning USING (user_pseudo_id);
```

Notes:
- **Why BQ-only:** GA4 only supports its built-in 540-day `newVsReturning`. The strict version ("first-seen-before-window-start") needs raw event timestamps that the Data API doesn't expose. The looser New vs Returning Counts metric ships in v1 using GA4's definition with a footnote about the difference.
- "Returning" here means "we've seen this `user_pseudo_id` before the window started," NOT GA4's `first_visit`. Stricter check.
- Scan cost: this query touches partitions before the window. With a long-running site that's potentially a lot of data. Consider capping the "before" scan at ~90 days (`_TABLE_SUFFIX BETWEEN @start_date_minus_90 AND @start_date_minus_1`).

## Open items specific to Tab 1

1. **Custom dimension detection (v1.1).** v1 infers a missing custom dimension from an empty `runReport` result. v1.1 should add a boot-time probe that warns admins if any expected custom dimensions (`post_id`, `author`, `logged_in`, `is_newsletter_subscriber`) are missing across the property, instead of inferring per-call.

2. **New vs Returning definition difference.** v1 uses GA4's 540-day `newVsReturning`; the strict pre-window definition (Returning Reader Rate) is BQ-only and hidden until NPPD-1630. Footnote the GA4 definition on the New vs Returning cards in v1.

4. **Lookback cap on BQ "Returning" detection.** When the BQ path ships, the over-time variant uses a 90-day `first_seen` lookback. For long-running sites a reader who first visited > 90 days ago AND came back > 90 days later is counted as "new" again. Acceptable approximation; could be tunable per publisher.

5. **Multi-author handling.** `author` event param may be comma-separated for co-authored pieces. v1 treats the whole string as one entity. v1.1 should split and credit each author separately.

6. **Page URL normalization.** Some publishers have query-string-heavy URLs that fragment "Top Pages" counts. GA4's `pagePath` and BQ's `page_location` both carry query strings; v1.1 could canonicalize via stripping known UTM and tracking params.

7. **Active Readers definition** — currently "any event" (GA4 `totalUsers`). Some publishers want "1+ pageview." Could be configurable; default to "any event," consistent with GA4's reporting convention.

8. **Time zone.** GA4's `hour` dimension already reports in the property's configured time zone. The BQ `event_timestamp` is UTC and needs the publisher-local offset applied. Reconcile at swap so the hour-of-day chart doesn't shift between v1 and v1.1.

9. **`is_newsletter_subscriber` and `logged_in` reliability.** These global event params should fire on every event but might be missing on edge cases. In v1 a consistently-empty custom dimension surfaces as the not-detected overlay; v1.1's boot-time probe should make this a property-level admin warning.

10. **Local wp_posts enrichment available as v1.1 improvement.** `post_id` is in event params on every singular page view, enabling future BQ → `wp_posts` joins for canonical post titles (surviving edits), authoritative author/category lookup, publish date, and post_type filtering. Adds a join pattern v1 metrics don't use. v1.1 follow-up.

## Cross-references

- Event reference: `../event-reference.md` (global event params section is core)
- BQ conventions: `./README.md`
- Architecture: `../architecture.md`
- Schema reference: `./subscription-donation-schema.md` (used downstream of Tab 1 for cross-tab attribution)
- GA4 Data API reference implementation: `Automattic/newspack-gate-intelligence` (`includes/class-oauth.php`, `includes/class-ga4.php`) on github.a8c.com
- Tab 2 (Engagement) for deeper engagement quality metrics: `./tab-2-engagement.md`
- Tab 3 (Conversion Journey) for funnels that start with audience reach: `./tab-3-conversion-journey.md`
