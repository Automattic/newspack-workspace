# Tab 1: Audience Overview — Product Spec

> Companion to `formulas/tab-1-audience.md`. This document specifies the UI structure, empty-state behavior, and product decisions. Formula references point at the formulas doc; no SQL lives here.

## Backend status

This tab ships **v1 powered by the GA4 Data API** and swaps to **BigQuery in v1.1** (pending the BQ proxy, NPPD-1630). The UI is identical across both backends; a per-tab constant (`NEWSPACK_INSIGHTS_AUDIENCE_USE_GA4`, default true) dispatches at the metric-orchestrator layer. GA4 reuses Newspack's existing Google OAuth and Site Kit property settings — no publisher reconnection. Some metrics that GA4 can't express are BQ-only and hidden until the BQ catalog ships (see the Hidden-in-v1 section). If a publisher has no GA4 connection, the whole tab shows a single connect banner; individual cards that depend on a custom dimension show a per-card overlay when that dimension isn't registered. See `formulas/tab-1-audience.md` for per-metric backends and queries.

## Summary

The Audience Overview tab answers "How big is my reach?" It's the entry tab — the first thing a publisher sees when they open Insights. It covers pure audience-composition and reach metrics: how many readers and sessions, where they come from, what devices and geographies they represent, and which content and authors pull the most readers. Unlike Gates, Subscribers, or Donors, there is no attribution or entity-state concept here; every metric is window-scoped reach.

## Visibility heuristic

The Audience tab is always visible — it's the home tab of Insights. Its only hard dependency is a working GA4 connection (v1) or BQ catalog (v1.1). When the GA4 connection is missing in v1, the tab still renders but every section is replaced by the connect banner described below.

## Tab-level connection banner (v1)

When the publisher has no usable GA4 connection, replace the tab body with a single banner:

> **Connect Google Analytics to see this tab.** Audience metrics come from your site's Google Analytics. Connect it in Newspack → Connections, then reload.

Style: light blue background, info icon. Not dismissible (there's nothing behind it). This is distinct from the per-card custom-dimension overlay below, which applies when the connection works but a specific dimension isn't registered.

## Per-card custom-dimension overlay (v1)

Cards whose metric depends on a GA4 custom dimension (`is_newsletter_subscriber`, `logged_in`, `post_id`, `author`) render an overlay when the orchestrator detects the dimension isn't registered (empty result on an otherwise valid query). The overlay reads:

> Custom dimension `<param>` not detected in this GA4 property — see [setup docs].

The rest of the tab functions normally. Two cards **degrade rather than overlay** (noted per-card below): Top Pages metrics fall back to all-URLs instead of hiding. v1.1 replaces per-call detection with a boot-time probe and an admin warning.

## Tab structure: 6 sections, top to bottom

1. **Reach** — top-of-tab scorecards
2. **Audience composition** — subscriber, logged-in, and device split pies
3. **Time trends** — readership over time, by day, by hour
4. **Traffic sources** — channel breakdown and campaigns
5. **Geographic** — countries, regions, cities, DMAs, local rate
6. **Content performance** — top pages and authors

Sections render in this order. Each has a header and a section caption (gray-700, 14px, line-height 1.5, immediately below the header), matching the Tab 6/7 convention.

---

## Section 1: Reach

**Header:** Reach
**Caption:** Your reach this period.

**Layout:** 4 scorecards in a single row: Active Readers, Pageviews, Avg Sessions
per Reader, Newsletter Signups. (Sessions was dropped — it's a definitional
middle-ground number publishers don't directly act on.)

### Card 1.1: Active Readers

- **Subtitle:** How many people read you
- **Value type:** Count
- **Comparison mode:** Yes (delta vs prior period)
- **Empty state:** GA4 returns 0 if no traffic; render 0
- **Formula:** `formulas/tab-1-audience.md` → "Active Readers (in window)"

### Card 1.2: Pageviews

- **Subtitle:** Total page views
- **Value type:** Count
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-1-audience.md` → "Pageviews (in window)"

### Card 1.3: Avg Sessions per Reader

- **Subtitle:** How often readers come back
- **Value type:** Decimal (1 place)
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-1-audience.md` → "Avg Sessions per Reader"

### Card 1.4: Newsletter Signups

- **Subtitle:** New subscribers this period
- **Value type:** Count
- **Comparison mode:** Yes
- **Empty state:** Zero is valid (new publisher / no signups yet) — render 0, not an error.
- **Source:** GA4 `np_newsletter_subscribed` event count. Fires on every successful Newspack newsletter signup (Registration block, Subscription Form block, account-creation modal, My Account → Newsletters).
- **Semantics:** Counts signup **events**, not unique readers — a reader who joins two lists in separate submissions contributes two; one multi-list submission is one event. Direct-from-ESP signups outside Newspack flows are not captured.
- **Formula:** `formulas/tab-1-audience.md` → "Newsletter Signups"

---

## Section 2: Audience composition

**Header:** Audience composition
**Caption:** Who's reading your stories.

**Layout:** Pies only — 4 equal-width pie cards in a single row (no scorecards).
The standalone rate scorecards were dropped; the composition pies already convey
the splits. Each card uses a three-slot layout so the row stays aligned
regardless of title/description length: title + description at top, pie
vertically centered in the middle, legend (with values) anchored at the bottom.
Cards are equal height (grid stretch).

### Viz 2.1: Newsletter Subscriber Composition (PieChart)

- **Subtitle:** Your newsletter subscribers vs the rest
- **Value type:** Two-slice pie
- **Custom dimension:** `is_newsletter_subscriber`
- **Empty state:** If the custom dimension is missing, overlay reads: "Custom dimension `is_newsletter_subscriber` not detected in this GA4 property — see [setup docs]."
- **Formula:** `formulas/tab-1-audience.md` → "Newsletter Subscriber Composition"

### Viz 2.2: Logged-In vs Anonymous Composition (PieChart)

- **Subtitle:** Who's signed in
- **Value type:** Two-slice pie
- **Custom dimension:** `logged_in`
- **Empty state:** If the custom dimension is missing, overlay reads: "Custom dimension `logged_in` not detected in this GA4 property — see [setup docs]."
- **Formula:** `formulas/tab-1-audience.md` → "Logged-In vs Anonymous Composition"

### Viz 2.3: Device Breakdown (PieChart)

- **Subtitle:** What devices your readers use
- **Value type:** Pie (mobile, desktop, tablet, other)
- **Formula:** `formulas/tab-1-audience.md` → "Device Breakdown"

### Viz 2.4: Supporter Type (PieChart)

- **Title:** Supporter Type
- **Description:** "Subscribers, donors, and registered readers among your logged-in audience."
- **Value type:** Pie whose slices adapt to the publisher's configured products (detected at the orchestrator), among logged-in readers only:
  - **Subscriptions + donations:** Subscriber only, Donor only, Both, Logged-in only
  - **Subscriptions only:** Subscriber, Logged-in only
  - **Donations only:** Donor, Logged-in only
  - **Neither:** the card is hidden entirely (`hidden_in_v1` with reason "no subscription or donation products configured") — there is nothing to segment by.
- **Custom dimensions:** `is_subscriber`, `is_donor` (both required). If either is missing, render the `custom_dimension_missing` overlay listing the missing dimension(s).
- **Formula:** `formulas/tab-1-audience.md` → "Supporter Type"

---

## Section 3: Time trends

> **Tab order:** Time trends renders **last** on the Audience tab (after Content
> performance). Section numbering here is historical.

**Header:** Time trends
**Caption:** When your readers show up across the period, by day of week, and by hour of day.

**Layout:** New vs Returning Over Time spans the full width on top; Readership by
Day of Week and by Hour of Day share the row below. Each chart card carries a
small temporal-scope **subhead** above its title tying the three together:
"Day to day", "Day of week", "Hour of day". (The standalone Active Readers Over
Time line was dropped — New vs Returning carries the same total and adds the more
useful split.)

### Viz 3.1: New vs Returning Readers Over Time (LineChart)

- **Subhead:** Day to day
- **Type:** Line chart, two color-coded series (new, returning) on a shared date axis, with a legend below and date labels on both edges.
- **Footnote (required):** Same 540-day "returning" definition note as the GA4 newVsReturning dimension.
- **Formula:** `formulas/tab-1-audience.md` → "New vs Returning Readers Over Time"

### Viz 3.2: Readership by Day of Week (LineChart / bar)

- **Subhead:** Day of week
- **Type:** Day-of-week chart, Sun–Sat order
- **Formula:** `formulas/tab-1-audience.md` → "Readership by Day of Week"

### Viz 3.3: Readership by Hour of Day (LineChart / bar)

- **Subhead:** Hour of day
- **Type:** Hour-of-day chart, 0–23
- **Note:** GA4 reports hours in the property's configured time zone; no extra offset needed in v1. (BQ v1.1 applies the publisher timezone offset to match.)
- **Formula:** `formulas/tab-1-audience.md` → "Readership by Hour of Day"

---

## Section 4: Traffic sources

**Header:** Traffic sources
**Caption:** Where your readers come from.

**Layout:** Two columns — the Traffic Sources Breakdown pie card (~35%, narrower)
beside the Top Campaigns table (~65%). The pie card uses the same three-slot
stack as the Audience-composition pies (title top, pie centered, legend below),
so the pie reads larger and the card is narrower-and-taller than a side-by-side
pie+legend would be.

### Viz 4.1: Traffic Sources Breakdown (PieChart)

- **Subtitle:** Readers by channel
- **Layout:** Pie stacked above its legend (not side-by-side); pie centered horizontally.
- **Note:** v1 uses GA4's default channel grouping (Organic Search, Direct, Email, Social, etc.).
- **Formula:** `formulas/tab-1-audience.md` → "Traffic Sources Breakdown"

### Viz 4.2: Top Campaigns (Table)

- **Columns:** Source, Medium, Campaign, Readers, Sessions
- **Sort:** Readers desc default
- **Row limit:** 50
- **Empty cell:** GA4 `(not set)` campaigns display as "(no campaign)"
- **Formula:** `formulas/tab-1-audience.md` → "Top Campaigns"

---

## Section 5: Geographic

**Header:** Geographic
**Caption:** Where your readers are.

**Layout:** Two tables (Regions/States, Cities).

**Country column auto-collapse:** when every row in a table shares the same
meaningful country value, the Country column is hidden and replaced with a
"Showing {country}" caption above the table (e.g. "Showing United States", or
"Showing United Kingdom" for a UK publisher). The column stays visible when rows
span multiple countries, or when any row's country is unset / null / `(not set)`
— so a multi-country audience and data-quality gaps both remain visible.

### Viz 5.1: Top Regions/States (Table)

- **Columns:** Country (auto-collapses), Region, Readers — Row limit 50
- **Formula:** `formulas/tab-1-audience.md` → "Top Regions/States"

### Viz 5.2: Top Cities (Table)

- **Columns:** Country (auto-collapses), Region, City, Readers — Row limit 50
- **Note:** City is the finest available granularity (no ZIP/neighborhood).
- **Formula:** `formulas/tab-1-audience.md` → "Top Cities"

---

## Section 6: Content performance

**Header:** Content performance
**Caption:** What's getting read.

**Layout:** Three tables (Top Categories is hidden in v1, so two render until the BQ catalog ships).

### Viz 6.1: Top Pages (Table)

- **Columns:** Page title, Readers, Pageviews — Row limit 10. Sorted by Readers descending.
- **Custom dimension:** `post_id`
- **Empty state (degrade, not hide):** If `post_id` is missing, the table falls back to all URLs (including homepage and archives) and shows the overlay: "Singular content filter unavailable; showing all URLs."
- **Formula:** `formulas/tab-1-audience.md` → "Top Pages"

### Viz 6.2: Top Authors by Reader Count (Table)

- **Columns:** Author, Unique readers, Pageviews — Row limit 10
- **Custom dimension:** `author`, `post_id`
- **Empty state:** If `author` is missing, overlay reads: "Custom dimension `author` not detected in this GA4 property — see [setup docs]." (This card hides behind the overlay rather than degrading — there is no useful author view without it.)
- **Formula:** `formulas/tab-1-audience.md` → "Top Authors by Reader Count"

### Viz 6.3: Top Categories (Table) — hidden in v1

- **Columns:** Category, Readers, Pageviews — Row limit 10. Sorted by Readers descending.
- **Hidden in v1.** Skip-renders until the BQ catalog ships (NPPD-1630). `categories` is a comma-separated event param; the GA4 Data API can't `UNNEST` it, and an exact-string match would double-count multi-category articles ("Politics, Local" as distinct from "Politics"). The orchestrator returns a `hidden_in_v1` payload with reason "available when BigQuery catalog ships".
- **Formula:** `formulas/tab-1-audience.md` → "Top Categories"

---

## Hidden in v1 (BQ-only, renders when BQ catalog ships per NPPD-1630)

> **Hidden in v1.** Renders when BQ catalog ships per NPPD-1630.

These metrics can't be expressed on the GA4 Data API and are not rendered in v1. The section structure is preserved so it's clear what's coming; no card appears until the BQ backend lands.

### Returning Reader Rate (strict pre-window definition)

% of readers in this period whose first-ever visit was strictly before the period started. GA4 only supports its built-in 540-day "returning" definition; the strict version needs raw event timestamps, so it waits for BQ. In the meantime, the New vs Returning cards (Section 2 / Section 3) ship using GA4's definition with the 540-day footnote.

---

## Comparison mode

Same behavior as Tabs 6 and 7. When the user toggles "Compare to previous period," every scorecard in Section 1 and the two rate scorecards in Section 2 render a delta below the value.

- Higher is better for all reach/composition rate metrics: increases render green, decreases red.
- No metric uses `lowerIsBetter` semantics on this tab.

Pie charts, line charts, and tables do not render comparison overlays in v1; comparison for visualizations is a v1.1 candidate.

## Date range picker behavior

Same as Tabs 6/7. The picker affects all sections — every metric on this tab is window-scoped. Changing the date range updates every value on the page. The dynamic section headers used on Tabs 6/7 ("In the last 30 days") are not used here; date scope is communicated through the global picker.

## Empty-state details

| Situation | Behavior |
|---|---|
| No GA4 connection | Whole tab replaced by connect banner |
| Custom dimension not registered (`is_newsletter_subscriber`, `logged_in`, `author`) | Per-card overlay with the dimension name |
| `post_id` not registered (Top Pages) | Degrade to all-URLs view with "showing all URLs" overlay |
| Zero traffic in window | Counts render 0, rates render 0%, charts render empty axes |

## Open questions

Surface during build, decide based on real data:

1. **Custom dimension detection.** v1.1 should add a boot-time probe that warns admins if any expected custom dimensions (`post_id`, `author`, `logged_in`, `is_newsletter_subscriber`) are missing across the property, rather than inferring per-card from empty query results.
2. **New vs Returning definition gap.** v1 uses GA4's 540-day definition; the strict pre-window definition is BQ-only and hidden. Confirm the footnote copy with the product team so publishers understand the two definitions won't match at the v1.1 swap.
3. **Active Readers definition.** Currently "any event" (GA4 `totalUsers`). Some publishers may want "1+ pageview." Could be configurable; default to GA4's convention.
4. **GA4 vs BQ baseline drift.** When the BQ backend ships, totals will differ slightly from GA4 (identity reconciliation, Signals, geo-per-user dedup). Decide acceptable tolerance and whether to annotate the swap in the UI.

## BQ query catalog (handoff to NPPD-1630)

When the BQ backend ships, each metric maps to a `query_name` in the Newspack Manager BQ query catalog. Naming convention: `audience_{metric_slug}`. The GA4 path is the v1 source of truth; the BQ catalog must be validated against GA4 baseline numbers before flipping `NEWSPACK_INSIGHTS_AUDIENCE_USE_GA4` to false.
