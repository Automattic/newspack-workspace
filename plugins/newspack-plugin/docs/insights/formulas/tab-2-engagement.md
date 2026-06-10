# Tab 2: Engagement — Formulas

Reference: see `formulas/README.md` for BigQuery conventions. See `../event-reference.md` for the canonical Newspack GA4 event params.

## Scope

Tab 2 answers "Are readers engaging?" — the engagement-quality deep dive after Tab 1's reach summary. No Woo joins, no Direct/Influenced attribution.

This is where we surface metrics intentionally pushed off Tab 1: scroll depth, time on article, author loyalty, mobile-vs-desktop content preferences, engagement by reader segment.

**v1 ships on the GA4 Data API.** BigQuery is the v1.1 swap target (pending the BQ proxy in NPPD-1630). Each metric below carries both its GA4 Data API query (active in v1) and its BigQuery query (the swap target, unchanged from the original BQ-only design). Metrics that GA4 can't express are tagged BQ-only and hidden in v1.

## Backend dispatch (v1: GA4 Data API, v1.1: BigQuery)

This tab ships v1 powered by GA4 Data API. v1.1 swaps to BigQuery via the proxy in NPPD-1630.

Dispatch: `Engagement_Metric` orchestrator reads constant `NEWSPACK_INSIGHTS_ENGAGEMENT_USE_GA4`. When true, calls the GA4 Data API path. When false, calls the BQ proxy path.

Constant defaults true. Flip false once this tab's BQ catalog ships and has been validated against GA4 baseline numbers.

GA4 connection: reuses Newspack's existing Google OAuth (`\Newspack\Google_OAuth::get_oauth2_credentials()`). Property ID detected via Site Kit's stored settings (`googlesitekit_analytics-4` option). No publisher reconnection needed for sites that already have Newspack Google connection configured. Reference implementation: `Automattic/newspack-gate-intelligence` on github.a8c.com (`includes/class-oauth.php`, `includes/class-ga4.php`). These will be extracted into newspack-plugin in a separate ticket; the orchestrator references the constant named above exactly.

Graceful failure: if the publisher's GA4 connection is missing, the entire tab renders a single banner ("Connect Google Analytics in Newspack → Connections to see this tab"). For per-metric custom dimension misses, render the affected card with an overlay ("Custom dimension `<param>` not detected — see [setup docs]") while the rest of the tab works normally.

**Custom dimension detection (graceful failure mechanism):** for any metric below tagged with a custom dimension dependency, the orchestrator issues the `runReport` with the `customEvent:<param>` dimension; if GA4 returns an empty result set for an otherwise valid query (no rows, no error), the orchestrator treats that as the "custom dimension not registered" condition and returns the overlay payload for that card. v1.1 replaces this per-call inference with a boot-time probe that warns admins up front.

**Pageview counting:** GA4 queries use the predefined `screenPageViews` metric rather than `eventCount` filtered to `eventName = 'page_view'`. For web-only publishers the two return identical numbers, and `screenPageViews` is the conventional Data API shape with no filter overhead.

## Dependencies and caveats

- **Scroll depth requires GA4 enhanced measurement's `scroll` event.** Enhanced measurement fires `scroll` once per page at 90% by default, so on the GA4 v1 path the scroll-dependent cards simply count `scroll` events scoped to articles — a scroll event already IS a 90% read. **No `percent_scrolled` custom dimension is queried on the GA4 path.** (The BQ v1.1 swap reads the raw `percent_scrolled` event param for finer-grained scroll averages.) This is a GA4 default, but publishers must have enhanced measurement enabled; if disabled, the scroll-dependent cards return empty — degrade gracefully by hiding the card with the diagnostic "Scroll tracking not enabled — enable in GA4 settings to see this metric."

- **Article detection uses the `post_id` custom dimension.** Newspack sets `post_id` on every singular page view (per `class-googlesitekit.php`). Article-scoped GA4 queries filter on `customEvent:post_id` being set (the `.+` regexp), which reliably catches singular content (posts, pages, custom post types) regardless of permalink structure. The remaining limitation is "singular content" vs "specifically article post_type" — NPPD-1621 tracks adding `post_type` as a custom dimension for the proper post_type-aware filter. Until that lands, Tab 2 article metrics include any singular content, not just article posts. For most Newspack publishers, the practical difference is small.

- **"Article freshness vs engagement" is BQ-only and hidden in v1.** Requires `post_published_date` custom dimension which isn't currently sent. NPPD-1621 will unblock once shipped.

- **`session_engaged` is GA4's standard engagement signal.** Session is engaged when duration ≥ 10s OR ≥ 2 pageviews OR ≥ 1 conversion event. GA4 Data API exposes engagement directly via `engagementRate` / `userEngagementDuration`; the BQ variants reconstruct it from the `session_engaged` and `engagement_time_msec` params.

## Conventions specific to this tab

- **Article filter:** wherever the metric is article-scoped, GA4 filters `customEvent:post_id` with the `.+` regexp; BQ applies `PARAM_INT('post_id') IS NOT NULL`. Once NPPD-1621 lands, tighten to `post_type = 'post'` for true article filtering.
- **Reader identity:** GA4 `totalUsers`; BQ `COALESCE(user_id, user_pseudo_id)`.
- **Session key:** GA4 `sessions` metric; BQ `CONCAT(user_pseudo_id, '|', CAST(PARAM_INT('ga_session_id') AS STRING))`.
- **GA4 date range:** examples use `{"startDate": "30daysAgo", "endDate": "today"}`; the orchestrator substitutes the user's selected window.
- **GA4 custom dimensions:** referenced in the `customEvent:<param>` form.

## Section: Overall engagement quality

### Avg Pages per Session

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "metrics": [{"name": "screenPageViewsPerSession"}]
}
```

**BigQuery query (v1.1 swap target):**

```sql
WITH sessions AS (
  SELECT
    CONCAT(user_pseudo_id, '|', CAST(PARAM_INT('ga_session_id') AS STRING)) AS session_key,
    COUNTIF(event_name = 'page_view') AS pages_in_session
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND PARAM_INT('ga_session_id') IS NOT NULL
  GROUP BY session_key
)
SELECT
  AVG(pages_in_session) AS avg_pages_per_session
FROM sessions;
```

Notes:
- GA4's `screenPageViewsPerSession` is the native pages-per-session metric. Includes all sessions, matching the BQ definition. For engaged-only, the BQ version can filter to `session_engaged = '1'`.

### Avg Engaged Session Duration

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "metrics": [{"name": "averageSessionDuration"}]
}
```

For a stricter engaged-only average, request `userEngagementDuration` and `activeUsers` and divide in PHP:

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "metrics": [{"name": "userEngagementDuration"}, {"name": "activeUsers"}]
}
```

**BigQuery query (v1.1 swap target):**

```sql
WITH session_engagement AS (
  SELECT
    CONCAT(user_pseudo_id, '|', CAST(PARAM_INT('ga_session_id') AS STRING)) AS session_key,
    SUM(IF(PARAM_INT('engagement_time_msec') > 0, PARAM_INT('engagement_time_msec'), 0)) AS total_engagement_ms
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND PARAM_INT('ga_session_id') IS NOT NULL
  GROUP BY session_key
)
SELECT
  AVG(total_engagement_ms) / 1000 AS avg_engaged_session_duration_sec
FROM session_engagement
WHERE total_engagement_ms > 0;
```

Notes:
- `averageSessionDuration` includes all sessions; the `userEngagementDuration / activeUsers` form is closer to the BQ "engaged-only" average (BQ filters to sessions with > 0 engagement to avoid skewing the average with bounces). Pick one and footnote which at swap.
- `engagement_time_msec` (BQ) is GA4's built-in engagement timer (active page time, excluding background tabs) — the same signal `userEngagementDuration` aggregates.

### Bounce Rate

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "metrics": [{"name": "bounceRate"}]
}
```

**BigQuery query (v1.1 swap target):**

```sql
WITH sessions AS (
  SELECT
    CONCAT(user_pseudo_id, '|', CAST(PARAM_INT('ga_session_id') AS STRING)) AS session_key,
    MAX(IF(PARAM('session_engaged') = '1', 1, 0)) AS engaged
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND PARAM_INT('ga_session_id') IS NOT NULL
  GROUP BY session_key
)
SELECT
  1 - (SUM(engaged) / COUNT(*)) AS bounce_rate
FROM sessions;
```

Notes:
- GA4's `bounceRate` is, by definition, `1 - engagementRate` — the same identity the BQ query computes from `session_engaged`. A session that's engaged is NOT a bounce.

### Article Completion Rate

% of article reads that reached the end. "Completion" is a binary signal — did
the reader get to the bottom — not an average depth. (GA4 enhanced measurement's
default scroll threshold is 90%, but that's an implementation detail; the metric
and label speak to completion, matching the "Articles by Completion Rate" table
on this tab.)

**v1 backend**: GA4 Data API
**Custom dimension dependency**: `post_id` (article scope); also requires GA4 enhanced-measurement scroll tracking
**Overlay if missing**: "Scroll tracking not enabled — see [GA4 setup docs] to enable"

Two `runReport` calls, ratio computed in PHP.

**GA4 Data API query (v1, active):**

Numerator — article-scoped scroll events (each is a completion signal under GA4's default enhanced measurement):

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "metrics": [{"name": "eventCount"}],
  "dimensionFilter": {
    "andGroup": {
      "expressions": [
        {"filter": {"fieldName": "eventName", "stringFilter": {"matchType": "EXACT", "value": "scroll"}}},
        {"filter": {"fieldName": "customEvent:post_id", "stringFilter": {"matchType": "FULL_REGEXP", "value": ".+"}}}
      ]
    }
  }
}
```

Denominator — total article-scoped reads (article page views):

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "metrics": [{"name": "screenPageViews"}],
  "dimensionFilter": {
    "filter": {"fieldName": "customEvent:post_id", "stringFilter": {"matchType": "FULL_REGEXP", "value": ".+"}}
  }
}
```

```
scroll_to_90_rate = numerator.eventCount / denominator.screenPageViews
```

**BigQuery query (v1.1 swap target):**

```sql
WITH article_reads AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    PARAM('page_location') AS page_url,
    PARAM_INT('ga_session_id') AS session_id,
    MAX(IF(event_name = 'scroll' AND PARAM_INT('percent_scrolled') >= 90, 1, 0)) AS reached_90
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name IN ('page_view', 'scroll')
    AND PARAM_INT('post_id') IS NOT NULL  -- article filter
  GROUP BY uid, page_url, session_id
)
SELECT
  SUM(reached_90) / NULLIF(COUNT(*), 0) AS scroll_to_90_rate
FROM article_reads;
```

Notes:
- **GA4 default-90 caveat.** GA4 enhanced measurement fires `scroll` once per page at 90% by default, so the numerator (article-scoped scroll events) divided by article page views approximates "% of reads that reached the bottom" — which is exactly the intent, with no `percent_scrolled` filter required. The BQ version computes the same ratio at the reader-session-article grain (and explicitly checks `percent_scrolled >= 90`). If a publisher has configured finer scroll thresholds (25/50/75/90), the GA4 scroll-event count would over-count on this default approach — that's the case the BQ swap's explicit `>= 90` check tightens. Footnote the approximation for v1.
- If the orchestrator gets an empty scroll-event result, treat scroll tracking as disabled and render the overlay rather than 0%.

## Section: Content engagement

### Most-Read Articles (Table)

**v1 backend**: GA4 Data API
**Custom dimension dependency**: `post_id`; the scroll signal in the ranking also requires GA4 enhanced-measurement scroll tracking
**Overlay if missing**: "Article tracking not detected — see [setup docs]"

Ranked by composite engagement score: `unique_readers × avg_scroll_depth × (1 + LN(avg_engagement_seconds + 1))` — combining reach AND depth, not just one. **Displayed columns are Article, Readers, and Avg time only**; `avg_scroll_depth` still feeds the ranking but is not shown (it implied an average-depth reading that confused publishers). This table absorbs the former standalone "Articles by Avg Time on Page" (same articles and columns, just a different sort).

**GA4 Data API query (v1, active):**

Multi-metric `runReport` per article (reach + engagement time):

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "customEvent:post_id"}, {"name": "pagePath"}, {"name": "pageTitle"}],
  "metrics": [{"name": "totalUsers"}, {"name": "userEngagementDuration"}],
  "dimensionFilter": {
    "filter": {"fieldName": "customEvent:post_id", "stringFilter": {"matchType": "FULL_REGEXP", "value": ".+"}}
  },
  "orderBys": [{"metric": {"metricName": "totalUsers"}, "desc": true}],
  "limit": 200
}
```

Scroll-completion per article comes from a separate scroll-scoped call (GA4 can't combine a scroll-event count with per-article session metrics in one report). Under GA4's default enhanced measurement each `scroll` event is a 90% read, so no `percent_scrolled` filter is needed:

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "customEvent:post_id"}],
  "metrics": [{"name": "eventCount"}],
  "dimensionFilter": {
    "andGroup": {
      "expressions": [
        {"filter": {"fieldName": "eventName", "stringFilter": {"matchType": "EXACT", "value": "scroll"}}},
        {"filter": {"fieldName": "customEvent:post_id", "stringFilter": {"matchType": "FULL_REGEXP", "value": ".+"}}}
      ]
    }
  }
}
```

PHP joins the two result sets on `post_id`, derives `avg_scroll_depth` (scroll-completion events ÷ readers as a depth proxy), `avg_engagement_seconds` (`userEngagementDuration ÷ totalUsers`), applies the `unique_readers >= 50` threshold, computes the composite, sorts, and truncates to 50.

**BigQuery query (v1.1 swap target):**

```sql
WITH article_metrics AS (
  SELECT
    PARAM('page_location') AS page_url,
    -- page_title can change over time; ANY_VALUE picks one representative
    ANY_VALUE(PARAM('page_title')) AS page_title,
    COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS unique_readers,
    AVG(COALESCE(PARAM_INT('percent_scrolled'), 0)) / 100 AS avg_scroll_depth,
    AVG(PARAM_INT('engagement_time_msec')) / 1000 AS avg_engagement_seconds
FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND PARAM_INT('post_id') IS NOT NULL
    AND event_name IN ('page_view', 'scroll')
  GROUP BY page_url
  HAVING unique_readers >= 50  -- minimum threshold
)
SELECT
  page_url,
  page_title,
  unique_readers,
  avg_scroll_depth,
  avg_engagement_seconds,
  unique_readers * GREATEST(avg_scroll_depth, 0.1) * (1 + LN(avg_engagement_seconds + 1)) AS engagement_score
FROM article_metrics
ORDER BY engagement_score DESC
LIMIT 50;
```

Notes:
- 50 unique_readers minimum threshold filters noise; adjust per publisher scale.
- The GA4 path approximates `avg_scroll_depth` from scroll-to-90 event share (GA4 can't `AVG()` a custom param directly the way BQ does); the BQ swap restores the true per-read average. Expect the composite ranking to be close but not identical across the two backends. `GREATEST(avg_scroll_depth, 0.1)` floor and `LN(... + 1)` compression preserved in BQ.
- Composite formula is configurable in v1.1; v1 ships with this default.

### Articles by Completion Rate

**v1 backend**: GA4 Data API
**Custom dimension dependency**: `post_id` (article scope); also requires GA4 enhanced-measurement scroll tracking
**Overlay if missing**: "Scroll tracking not enabled — see [GA4 setup docs] to enable"

% of readers who reached 90% scroll on each article.

**GA4 Data API query (v1, active):**

Two `runReport` calls keyed on `post_id`, ratio per row in PHP. Readers per article:

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "customEvent:post_id"}, {"name": "pagePath"}, {"name": "pageTitle"}],
  "metrics": [{"name": "totalUsers"}],
  "dimensionFilter": {
    "filter": {"fieldName": "customEvent:post_id", "stringFilter": {"matchType": "FULL_REGEXP", "value": ".+"}}
  },
  "limit": 1000
}
```

Scroll-completion events per article (each `scroll` event is a 90% read under GA4's default enhanced measurement):

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "customEvent:post_id"}],
  "metrics": [{"name": "eventCount"}],
  "dimensionFilter": {
    "andGroup": {
      "expressions": [
        {"filter": {"fieldName": "eventName", "stringFilter": {"matchType": "EXACT", "value": "scroll"}}},
        {"filter": {"fieldName": "customEvent:post_id", "stringFilter": {"matchType": "FULL_REGEXP", "value": ".+"}}}
      ]
    }
  },
  "limit": 1000
}
```

PHP joins on `post_id`, computes `completion_rate = scroll_completion_events ÷ readers` per row, applies the `readers >= 50` threshold, sorts by completion rate desc then readers desc, truncates to 50.

**BigQuery query (v1.1 swap target):**

```sql
SELECT
  PARAM('page_location') AS page_url,
  ANY_VALUE(PARAM('page_title')) AS page_title,
  COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS readers,
  SUM(CASE WHEN PARAM_INT('percent_scrolled') >= 90 THEN 1 ELSE 0 END) /
    COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS completion_rate
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND event_name IN ('page_view', 'scroll')
  AND PARAM_INT('post_id') IS NOT NULL
GROUP BY page_url
HAVING readers >= 50
ORDER BY completion_rate DESC, readers DESC
LIMIT 50;
```

Notes:
- "Completion" = scroll to 90%+. Approximation of "read to the end."
- Sort by completion_rate DESC then readers DESC — surfaces articles people actually finished, with reach as tiebreaker.

### Top Categories by Avg Engagement Time

**v1 backend**: BQ-only (hidden in v1)
**Custom dimension dependency**: `categories` (comma-separated; needs SPLIT + UNNEST)

> Hidden in v1. Will appear when BQ catalog ships per NPPD-1630.

**BigQuery query (v1.1 swap target):**

```sql
WITH category_engagement AS (
  SELECT
    -- categories is comma-separated; split and unnest
    cat AS category,
    PARAM_INT('engagement_time_msec') / 1000 AS engagement_sec
  FROM `{project}.{dataset}.events_*`,
UNNEST(SPLIT(COALESCE(PARAM('categories'), ''), ',')) AS cat
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND PARAM_INT('post_id') IS NOT NULL
    AND PARAM_INT('engagement_time_msec') > 0
    AND cat IS NOT NULL
    AND TRIM(cat) != ''
)
SELECT
  TRIM(category) AS category,
  COUNT(*) AS article_reads,
  AVG(engagement_sec) AS avg_engagement_seconds
FROM category_engagement
GROUP BY category
HAVING article_reads >= 100  -- minimum threshold
ORDER BY avg_engagement_seconds DESC
LIMIT 25;
```

Notes:
- **Why BQ-only:** `categories` is a comma-separated string. The GA4 Data API can't `SPLIT` + `UNNEST` a custom dimension value, so a per-category average is impossible there — each multi-category article would be attributed to its whole comma-joined string as a single dimension value. Needs raw rows.
- 100 article_reads minimum — categories with low volume can have wild averages from a single anomalous read.

### Top Authors by Avg Engagement Time

**v1 backend**: GA4 Data API
**Custom dimension dependency**: `author`, `post_id`
**Overlay if missing**: "Author tracking not detected — see [setup docs]"

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "customEvent:author"}],
  "metrics": [{"name": "totalUsers"}, {"name": "userEngagementDuration"}],
  "dimensionFilter": {
    "andGroup": {
      "expressions": [
        {"filter": {"fieldName": "customEvent:post_id", "stringFilter": {"matchType": "FULL_REGEXP", "value": ".+"}}},
        {"filter": {"fieldName": "customEvent:author", "stringFilter": {"matchType": "FULL_REGEXP", "value": ".+"}}}
      ]
    }
  },
  "orderBys": [{"metric": {"metricName": "userEngagementDuration"}, "desc": true}],
  "limit": 25
}
```

PHP derives `avg_engagement_seconds = userEngagementDuration ÷ totalUsers` per author and applies the read-count threshold.

**BigQuery query (v1.1 swap target):**

```sql
SELECT
  PARAM('author') AS author,
  COUNT(*) AS article_reads,
  COUNT(DISTINCT COALESCE(user_id, user_pseudo_id)) AS unique_readers,
  AVG(PARAM_INT('engagement_time_msec')) / 1000 AS avg_engagement_seconds,
  AVG(COALESCE(PARAM_INT('percent_scrolled'), 0)) / 100 AS avg_scroll_depth
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND PARAM_INT('post_id') IS NOT NULL
  AND PARAM_INT('engagement_time_msec') > 0
  AND PARAM('author') IS NOT NULL
  AND PARAM('author') != ''
GROUP BY author
HAVING article_reads >= 50
ORDER BY avg_engagement_seconds DESC
LIMIT 25;
```

Notes:
- The `avg_scroll_depth` column in the BQ version isn't reproducible in the GA4 path (GA4 can't average `percent_scrolled` alongside per-author engagement in one report); v1 ships the author table without the scroll-depth column and gains it at the BQ swap. Footnote in the card.
- For multi-author posts, the `author` param contains a single value. v1.1 may handle multi-author splitting if `author` becomes comma-separated.

## Section: Reader segments

### Engagement by Device Type (Table)

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "deviceCategory"}],
  "metrics": [
    {"name": "sessions"},
    {"name": "userEngagementDuration"},
    {"name": "screenPageViewsPerSession"}
  ],
  "orderBys": [{"metric": {"metricName": "sessions"}, "desc": true}]
}
```

**BigQuery query (v1.1 swap target):**

```sql
SELECT
  device.category AS device,
  COUNT(DISTINCT CONCAT(user_pseudo_id, '|', CAST(PARAM_INT('ga_session_id') AS STRING))) AS sessions,
  AVG(PARAM_INT('engagement_time_msec')) / 1000 AS avg_engagement_seconds,
  AVG(COALESCE(PARAM_INT('percent_scrolled'), 0)) / 100 AS avg_scroll_depth
FROM `{project}.{dataset}.events_*`
WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
  AND PARAM_INT('engagement_time_msec') > 0
  AND device.category IS NOT NULL
GROUP BY device
ORDER BY sessions DESC;
```

Notes:
- v1 GA4 columns: device, sessions, engagement duration, pages-per-session. The BQ `avg_scroll_depth` column isn't available in the GA4 path (custom param average) — it appears at the BQ swap. Footnote.
- Mobile typically dominates session count but has lower scroll depth.

### Mobile vs Desktop Content Preferences (Table)

**v1 backend**: BQ-only (hidden in v1)
**Custom dimension dependency**: `categories` (comma-separated; needs SPLIT + UNNEST)

> Hidden in v1. Will appear when BQ catalog ships per NPPD-1630.

For each category, what % of reads come from mobile vs desktop?

**BigQuery query (v1.1 swap target):**

```sql
WITH category_reads AS (
  SELECT
    TRIM(cat) AS category,
    device.category AS device,
    COUNT(*) AS reads
  FROM `{project}.{dataset}.events_*`,
UNNEST(SPLIT(COALESCE(PARAM('categories'), ''), ',')) AS cat
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND PARAM_INT('post_id') IS NOT NULL
    AND device.category IS NOT NULL
    AND cat IS NOT NULL
    AND TRIM(cat) != ''
  GROUP BY category, device
)
SELECT
  category,
  SUM(CASE WHEN device = 'mobile' THEN reads ELSE 0 END) AS mobile_reads,
  SUM(CASE WHEN device = 'desktop' THEN reads ELSE 0 END) AS desktop_reads,
  SUM(CASE WHEN device = 'tablet' THEN reads ELSE 0 END) AS tablet_reads,
  SUM(reads) AS total_reads,
  SUM(CASE WHEN device = 'mobile' THEN reads ELSE 0 END) / NULLIF(SUM(reads), 0) AS mobile_share
FROM category_reads
GROUP BY category
HAVING total_reads >= 100
ORDER BY total_reads DESC
LIMIT 25;
```

Notes:
- **Why BQ-only:** same `SPLIT` + `UNNEST` on the comma-separated `categories` param that GA4 Data API can't perform.
- Useful for editorial placement decisions — categories with high mobile share might benefit from mobile-first formatting; desktop-heavy categories may be lunchtime/work-hour reads.

### Engagement by traffic source

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none (`sessionMedium` is a GA4 standard dimension, always present)
**Minimum-sessions floor**: 100 newsletter sessions in the window. Below the floor the card renders "Not enough data in this timeframe." instead of a comparison.

Sessions are partitioned into two cohorts by traffic medium:

- **Newsletter:** sessions whose medium is `email` or `newsletter`.
- **Other:** all other sessions, including direct, organic, referral, social, paid, etc.

For each cohort, compute average engagement time per session.

#### GA4 Data API (v1, active)

Dimensions: `sessionMedium`
Metrics: `userEngagementDuration`, `sessions`

Per cohort: `SUM(userEngagementDuration) / SUM(sessions)`, partitioned by the IN-list above.

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "sessionMedium"}],
  "metrics": [
    {"name": "userEngagementDuration"},
    {"name": "sessions"}
  ]
}
```

PHP buckets rows where `sessionMedium IN ('email', 'newsletter')` into `newsletter` and all other rows into `other`, summing `sessions` and `userEngagementDuration` per bucket, then `avg_engagement_sec = userEngagementDuration / sessions` per bucket.

#### BigQuery (post-migration)

Per session, sum `engagement_time_msec` event params (from `events_*`). Identify session medium from `COALESCE(NULLIF(collected_traffic_source.manual_medium, ''), NULLIF(traffic_source.medium, ''))`. Per cohort: average per-session engagement seconds.

```sql
WITH session_engagement AS (
  SELECT
    CONCAT(user_pseudo_id, '|', CAST((SELECT value.int_value FROM UNNEST(event_params) WHERE key = 'ga_session_id') AS STRING)) AS session_key,
    ANY_VALUE(COALESCE(NULLIF(collected_traffic_source.manual_medium, ''), NULLIF(traffic_source.medium, ''))) AS medium,
    SUM((SELECT value.int_value FROM UNNEST(event_params) WHERE key = 'engagement_time_msec')) / 1000 AS engagement_seconds
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND (SELECT value.int_value FROM UNNEST(event_params) WHERE key = 'ga_session_id') IS NOT NULL
  GROUP BY session_key
)
SELECT
  CASE WHEN medium IN ('email', 'newsletter') THEN 'newsletter' ELSE 'other' END AS segment,
  COUNT(*) AS sessions,
  AVG(engagement_seconds) AS avg_engagement_seconds
FROM session_engagement
GROUP BY segment;
```

Notes:
- The data contract returned to the React layer is source-agnostic: `rows: [{ segment: 'newsletter'|'other', sessions, avg_engagement_seconds }]`. The GA4 and BigQuery formulas above must produce the same shape so the migration is a mechanical backend swap.
- Below the 100-session newsletter floor → "needs data" state, not a 0 comparison.
- Expected pattern: newsletter traffic engages substantially more per session (validation on Richland Source showed ~2× — newsletter 97.6s vs other 47.4s over a 28-day window). If it DOESN'T, that's a publisher diagnostic — the newsletter audience isn't converting to deeper site engagement.
- **Known limitations:** UTM-stripping email clients (Apple Mail Privacy Protection) push some email opens into the direct bucket, so the newsletter share is a floor; publishers using non-standard medium tags (e.g. `newsletter-weekly`) land in "other". The `email`/`newsletter` convention is required.

### Engagement by Returning vs New Readers

**v1 backend**: GA4 Data API
**Custom dimension dependency**: none

**GA4 Data API query (v1, active):**

```json
{
  "dateRanges": [{"startDate": "30daysAgo", "endDate": "today"}],
  "dimensions": [{"name": "newVsReturning"}],
  "metrics": [
    {"name": "sessions"},
    {"name": "screenPageViewsPerSession"},
    {"name": "userEngagementDuration"}
  ]
}
```

**BigQuery query (v1.1 swap target):**

```sql
WITH first_seen AS (
  SELECT
    user_pseudo_id,
    MIN(PARSE_DATE('%Y%m%d', event_date)) AS first_seen_date
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN
    FORMAT_DATE('%Y%m%d', DATE_SUB(PARSE_DATE('%Y%m%d', @start_date), INTERVAL 90 DAY))
    AND @end_date
  GROUP BY user_pseudo_id
),
session_engagement AS (
  SELECT
    e.user_pseudo_id,
    CONCAT(e.user_pseudo_id, '|', CAST(PARAM_INT('ga_session_id') AS STRING)) AS session_key,
    SUM(IF(e.event_name = 'page_view', 1, 0)) AS pages_in_session,
    SUM(PARAM_INT('engagement_time_msec')) / 1000 AS engagement_seconds
  FROM `{project}.{dataset}.events_*` e
  WHERE e._TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND PARAM_INT('ga_session_id') IS NOT NULL
  GROUP BY e.user_pseudo_id, session_key
)
SELECT
  CASE WHEN fs.first_seen_date < PARSE_DATE('%Y%m%d', @start_date) THEN 'returning' ELSE 'new' END AS reader_type,
  COUNT(*) AS sessions,
  AVG(pages_in_session) AS avg_pages_per_session,
  AVG(engagement_seconds) AS avg_engagement_seconds
FROM session_engagement se
LEFT JOIN first_seen fs USING (user_pseudo_id)
GROUP BY reader_type;
```

Notes:
- **GA4 vs BQ definition differs.** GA4's `newVsReturning` uses its built-in 540-day window; the BQ version uses a 90-day `first_seen` lookback. Same footnote as Tab 1's New vs Returning metrics. Map GA4's two dimension values to the two table rows.

## Section: Author loyalty

### Top Authors by Repeat Reader Rate

**v1 backend**: BQ-only (hidden in v1)
**Custom dimension dependency**: `author`, `post_id` (needs per-reader-per-author article counts)

> Hidden in v1. Will appear when BQ catalog ships per NPPD-1630.

% of an author's readers who read more than 1 of their articles in window.

**BigQuery query (v1.1 swap target):**

```sql
WITH reader_author_pairs AS (
  SELECT
    COALESCE(user_id, user_pseudo_id) AS uid,
    PARAM('author') AS author,
    COUNT(DISTINCT PARAM('page_location')) AS articles_read
  FROM `{project}.{dataset}.events_*`
  WHERE _TABLE_SUFFIX BETWEEN @start_date AND @end_date
    AND event_name = 'page_view'
    AND REGEXP_CONTAINS(PARAM('page_location'), r'/\d{4}/\d{2}/')
    AND PARAM('author') IS NOT NULL
    AND PARAM('author') != ''
  GROUP BY uid, author
)
SELECT
  author,
  COUNT(*) AS total_readers,
  COUNT(CASE WHEN articles_read >= 2 THEN 1 END) AS repeat_readers,
  COUNT(CASE WHEN articles_read >= 2 THEN 1 END) * 1.0 / NULLIF(COUNT(*), 0) AS repeat_reader_rate
FROM reader_author_pairs
GROUP BY author
HAVING total_readers >= 100
ORDER BY repeat_reader_rate DESC
LIMIT 25;
```

Notes:
- **Why BQ-only:** needs per-reader-per-author article counts (how many distinct articles each reader read from each author). The GA4 Data API is pre-aggregated — reader-grain detail crossed with author is unavailable, so the "read ≥2 of this author's articles" condition can't be computed there.
- High repeat reader rate = the author has a following. Useful for editorial deployment decisions.
- Threshold of 100 total_readers minimum filters out small-audience authors where the metric is noisy.
- Computationally expensive at the BQ swap (reader × author pairs). Pre-warm and cache aggressively.

## Section: Content recency (BQ-only, hidden in v1)

### Article Freshness vs Engagement (LineChart)

**v1 backend**: BQ-only (hidden in v1)
**Custom dimension dependency**: `post_published_date` (not yet sent — NPPD-1621)

> Hidden in v1. Will appear when BQ catalog ships per NPPD-1630.

**Why BQ-only / deferred:** requires the `post_published_date` custom dimension, which Newspack doesn't currently send. NPPD-1621 tracks adding it. When it ships:
- X-axis: days since publication (0, 1, 2, 3-7, 8-30, 31-90, 91+)
- Y-axis: avg engagement time per article read in that age bucket
- Reveals: do readers spend MORE time on fresh news (breaking, daily updates) or evergreen content (investigations, explainers)?

The BQ implementation buckets `DATE_DIFF(event_date, post_published_date)` and averages `engagement_time_msec` per bucket. Full SQL to be added once NPPD-1621 lands and the dimension is confirmed in the export.

## Open items specific to Tab 2

1. **Custom dimension detection (v1.1).** v1 infers a missing custom dimension from an empty `runReport` result. v1.1 should add a boot-time probe that warns admins if any expected custom dimensions (`post_id`, `author`, `is_newsletter_subscriber`) — or GA4 enhanced-measurement scroll tracking — are missing across the property, instead of inferring per-call.

2. **`post_type` and `post_published_date` not in custom dimensions yet.** NPPD-1621 tracks. v1 uses `customEvent:post_id` to filter to singular content (reliable, no per-publisher configuration); Article Freshness is BQ-only and hidden. The current filter includes pages and custom post types alongside article posts; NPPD-1621 will allow exact post_type filtering.

3. **Scroll tracking is opt-in.** Hide scroll-dependent cards gracefully when the publisher hasn't enabled enhanced-measurement scroll. GA4 default fires `scroll` only at 90%; the Article Completion Rate and Articles-by-Completion-Rate metrics are built to work with that default. Diagnostic: "Scroll tracking not enabled — see [GA4 setup docs]."

4. **Author param assumes single-author articles.** If `author` is comma-separated for multi-byline pieces, the metric treats the whole string as one entity. v1 documents the limitation; v1.1 may split.

5. **Composite engagement score is opinionated.** Default: `unique_readers × avg_scroll_depth × (1 + LN(avg_engagement_time))`. The GA4 v1 path approximates `avg_scroll_depth` from scroll-to-90 share; the BQ swap restores the true average. Configurable in v1.1.

6. **Scroll-depth columns degrade between backends.** The Device Type and Top Authors tables carry an `avg_scroll_depth` column in their BQ form that GA4 can't reproduce (custom-param average). v1 ships those tables without the scroll-depth column; it appears at the BQ swap. Confirm with design that the column appearing in v1.1 is acceptable (no UI restructure, just an added column).

7. **Engagement decay over content age** lands with Article Freshness once `post_published_date` (NPPD-1621) and the BQ catalog (NPPD-1630) are both available.

8. **Local wp_posts enrichment available as v1.1 improvement.** `post_id` is available in event params, enabling BQ → `wp_posts` joins for richer enrichment (canonical post title surviving edits, authoritative author/category lookup, publish date — partial substitute for NPPD-1621). Adds a join pattern v1 metrics don't use. v1.1 follow-up.

## Cross-references

- Event reference: `../event-reference.md`
- BQ conventions: `./README.md`
- GA4 Data API reference implementation: `Automattic/newspack-gate-intelligence` (`includes/class-oauth.php`, `includes/class-ga4.php`) on github.a8c.com
- Tab 1 (Audience Overview) for reach metrics — Tab 2 picks up where Tab 1 leaves off: `./tab-1-audience.md`
- Tab 3 (Conversion Journey) for engagement-to-conversion attribution: `./tab-3-conversion-journey.md`
