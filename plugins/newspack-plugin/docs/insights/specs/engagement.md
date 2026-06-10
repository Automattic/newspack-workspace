# Tab 2: Engagement — Product Spec

> Companion to `formulas/tab-2-engagement.md`. This document specifies the UI structure, empty-state behavior, and product decisions. Formula references point at the formulas doc; no SQL lives here.

## Backend status

This tab ships **v1 powered by the GA4 Data API** and swaps to **BigQuery in v1.1** (pending the BQ proxy, NPPD-1630). The UI is identical across both backends; a per-tab constant (`NEWSPACK_INSIGHTS_ENGAGEMENT_USE_GA4`, default true) dispatches at the metric-orchestrator layer. GA4 reuses Newspack's existing Google OAuth and Site Kit property settings — no publisher reconnection. Several engagement metrics depend on GA4 custom dimensions (`post_id`, `author`, `is_newsletter_subscriber`), and the scroll-completion cards additionally require GA4 enhanced-measurement scroll tracking; cards render a per-card overlay when their dependency isn't met. A few metrics that GA4 can't express (category splits, per-author repeat-reader detail, content freshness) are BQ-only and hidden until the BQ catalog ships. If a publisher has no GA4 connection, the whole tab shows a single connect banner. See `formulas/tab-2-engagement.md` for per-metric backends and queries.

## Summary

The Engagement tab answers "Are readers engaging?" It's the quality deep-dive after Tab 1's reach summary: pages per session, time on article, scroll completion, which articles and authors hold attention, and how engagement differs by device, newsletter status, and new-vs-returning. There is no attribution or entity-state concept here; every metric is window-scoped engagement quality.

## Visibility heuristic

The Engagement tab is always visible alongside Audience. Its hard dependency is a working GA4 connection (v1) or BQ catalog (v1.1). When the GA4 connection is missing in v1, the tab renders the connect banner in place of its sections.

## Tab-level connection banner (v1)

When the publisher has no usable GA4 connection, replace the tab body with a single banner:

> **Connect Google Analytics to see this tab.** Engagement metrics come from your site's Google Analytics. Connect it in Newspack → Connections, then reload.

Style: light blue background, info icon. Not dismissible.

## Per-card custom-dimension overlay (v1)

Cards whose metric depends on a GA4 custom dimension render an overlay when the orchestrator detects the dimension isn't registered (empty result on an otherwise valid query). The overlay reads:

> Custom dimension `<param>` not detected in this GA4 property — see [setup docs].

Scroll-dependent cards use a scroll-specific variant of this overlay ("Scroll tracking not enabled — see [GA4 setup docs] to enable"). The rest of the tab functions normally. v1.1 replaces per-call detection with a boot-time probe and an admin warning.

## Tab structure: sections, top to bottom

1. **Overall engagement quality** — scorecards
2. **Content engagement** — most-read articles, completion, top authors
3. **Reader segments** — engagement by device, newsletter status, new vs returning
4. **Author loyalty** — repeat-reader performance (BQ-only, hidden in v1)

Three sections render in v1 (Overall engagement quality, Content engagement,
Reader segments); Author loyalty is BQ-only and stays hidden until NPPD-1630. The
former "Time patterns" section (engagement by day of week) was cut — Audience's
Readership by Day of Week is the actionable day-of-week view; this was redundant.

Sections render in this order. Each has a header and a section caption (gray-700, 14px, line-height 1.5, immediately below the header), matching the Tab 6/7 convention.

---

## Section 1: Overall engagement quality

**Header:** Overall engagement quality
**Caption:** How deeply readers engage.

**Layout:** 4 scorecards in a single row.

### Card 1.1: Avg Pages per Session

- **Subtitle:** Pages viewed per visit
- **Value type:** Decimal (1 place)
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-2-engagement.md` → "Avg Pages per Session"

### Card 1.2: Avg Engaged Session Duration

- **Subtitle:** Time spent per visit
- **Value type:** Duration (mm:ss)
- **Comparison mode:** Yes
- **Formula:** `formulas/tab-2-engagement.md` → "Avg Engaged Session Duration"

### Card 1.3: Bounce Rate

- **Subtitle:** % bounced
- **Value type:** Percentage
- **Comparison mode:** Yes (lower is better)
- **Formula:** `formulas/tab-2-engagement.md` → "Bounce Rate"

### Card 1.4: Article Completion Rate

- **Subtitle:** % finished reading
- **Value type:** Percentage
- **Comparison mode:** Yes
- **Custom dimension:** `post_id`; also requires GA4 enhanced-measurement scroll tracking
- **Empty state:** If scroll tracking is disabled, overlay reads: "Scroll tracking not enabled — see [GA4 setup docs] to enable."
- **Formula:** `formulas/tab-2-engagement.md` → "Article Completion Rate"

---

## Section 2: Content engagement

> **Tab order:** Reader segments (below) renders *before* Content engagement on
> the Engagement tab — order is Quality → Reader segments → Content engagement.
> Section numbering here is historical.

**Header:** Content engagement
**Caption:** What holds reader attention.

**Layout:** Three tables in a 2-column grid (`--cols-2`). Most-Read Articles and
Articles by Completion Rate share row 1; Top Authors by Avg Engagement Time wraps
to row 2 in a single column (~50% width, left-aligned) so it isn't stretched. All
three render up to 10 rows (matching Audience Content performance); fewer if the
publisher has fewer qualifying articles/authors (no padding rows).

### Viz 2.1: Most-Read Articles (Table)

- **Columns:** Article title, Readers, Avg time
- **Sort:** Composite engagement score desc (`unique_readers × scroll × engagement_time`). Scroll still factors into the ranking even though it is no longer a displayed column. This table absorbs the former standalone "Articles by Avg Time on Page" (same articles, same columns, different sort).
- **Row limit:** 10 (50-reader minimum threshold)
- **Custom dimension:** `post_id`; the scroll signal in the ranking also requires GA4 enhanced-measurement scroll tracking
- **Empty state:** If `post_id` is missing, overlay reads: "Custom dimension `post_id` not detected in this GA4 property — see [setup docs]."
- **Formula:** `formulas/tab-2-engagement.md` → "Most-Read Articles"

### Viz 2.2: Articles by Completion Rate (Table)

- **Columns:** Article title, Readers, **Read to end** (the completion-rate %; the column header reads "Read to end" rather than "Completion", which scanned as ambiguous). No inline description below the title — the clarified header carries the meaning, and dropping it keeps the three Content-engagement table tops aligned.
- **Sort:** Read-to-end rate desc, readers desc
- **Row limit:** 10 (50-reader minimum threshold)
- **Custom dimension:** `post_id`; also requires GA4 enhanced-measurement scroll tracking
- **Empty state:** If scroll tracking is disabled, overlay reads: "Scroll tracking not enabled — see [GA4 setup docs] to enable."
- **Formula:** `formulas/tab-2-engagement.md` → "Articles by Completion Rate"

### Viz 2.3: Top Authors by Avg Engagement Time (Table)

- **Columns:** Author, Unique readers, Avg engagement time
- **Sort:** Avg engagement time desc
- **Row limit:** 25
- **Custom dimension:** `author`, `post_id`
- **Empty state:** If `author` is missing, overlay reads: "Custom dimension `author` not detected in this GA4 property — see [setup docs]."
- **Note:** v1 omits the avg-scroll-depth column present in the BQ version (GA4 can't average a custom param alongside per-author metrics); the column appears at the BQ swap.
- **Formula:** `formulas/tab-2-engagement.md` → "Top Authors by Avg Engagement Time"

---

## Section 3: Reader segments

**Header:** Reader segments
**Caption:** How engagement varies by segment.

**Layout:** Three **takeaway cards** (not tables) — each a one-line comparison
headline (~18px / weight 500), a muted sub-line with the raw figures, and an
inline mini bar chart (~60px). Same scorecard chrome (border, top accent,
padding). The comparison logic derives from the same orchestrator metrics that
previously rendered as tables; the dense tables were unscannable.

### Viz 3.1: Device engagement (Takeaway card)

- **Headline:** "{Device} readers spend {X}% longer per session" — {Device} is the longest-avg-time device; baseline is mobile (or the shortest device if mobile leads).
- **Sub:** "than {baseline} readers ({subject time} vs {baseline time})"
- **Mini chart:** bars for mobile / desktop / tablet, heights ∝ avg engaged session duration.
- **Formula:** `formulas/tab-2-engagement.md` → "Engagement by Device Type"

### Viz 3.2: Returning vs new (Takeaway card)

- **Headline:** "Returning readers view {X}% more pages per session" (flips to "New readers…" if new leads).
- **Sub:** "than new readers ({returning pages} vs {new pages})"
- **Mini chart:** bars for new / returning, heights ∝ avg pages per session.
- **Footnote:** "Returning" uses GA4's standard 540-day definition.
- **Formula:** `formulas/tab-2-engagement.md` → "Engagement by Returning vs New Readers"

### Viz 3.3: Newsletter status (Takeaway card)

- **Headline:** "Subscribers engage {X}% longer than non-subscribers" (flips if non-subscribers lead).
- **Sub:** "{subscriber time} per session vs {non-subscriber time}"
- **Mini chart:** bars for subscriber / non-subscriber, heights ∝ avg engaged session duration.
- **Custom dimension:** `is_newsletter_subscriber` — if missing, the card shows the custom-dimension overlay.
- **Formula:** `formulas/tab-2-engagement.md` → "Engagement by Newsletter Status"

---

## Section 4: Author loyalty

**Header:** Author loyalty
**Caption:** Which authors readers come back for.

This section is BQ-only in v1 — its metric needs per-reader-per-author detail that the GA4 Data API doesn't expose.

> **Hidden in v1.** Renders when BQ catalog ships per NPPD-1630.

### Viz 4.1: Top Authors by Repeat Reader Rate (Table)

% of an author's readers who read more than one of their articles in the period. Needs reader-grain detail crossed with author — pre-aggregated GA4 can't compute it, so it waits for BQ.

- **Columns (when it ships):** Author, Total readers, Repeat readers, Repeat reader rate
- **Formula:** `formulas/tab-2-engagement.md` → "Top Authors by Repeat Reader Rate"

---

## Hidden in v1 (BQ-only, renders when BQ catalog ships per NPPD-1630)

> **Hidden in v1.** Renders when BQ catalog ships per NPPD-1630.

These metrics can't be expressed on the GA4 Data API and are not rendered in v1. The structure is preserved so it's clear what's coming; no card appears until the BQ backend lands. (Top Authors by Repeat Reader Rate, Section 4, is also BQ-only and marked in place above.)

### Top Categories by Avg Engagement Time

Average engagement time per content category. `categories` is a comma-separated event param; GA4 can't split/unnest it into per-category rows, so it waits for BQ.

### Mobile vs Desktop Content Preferences

Per-category share of mobile vs desktop reads. Same comma-separated `categories` split/unnest limitation as above.

### Article Freshness vs Engagement

Engagement time bucketed by days-since-publication. Requires the `post_published_date` custom dimension, which Newspack doesn't yet send (NPPD-1621). Lands once both NPPD-1621 and the BQ catalog (NPPD-1630) are available.

---

## Comparison mode

Same behavior as Tabs 6 and 7. When the user toggles "Compare to previous period," every scorecard in Section 1 renders a delta below the value.

- Higher is better for Avg Pages per Session, Avg Engaged Session Duration, and Article Completion Rate: increases render green, decreases red.
- Bounce Rate uses `lowerIsBetter` semantics: a decrease renders green.

Tables and the line chart do not render comparison overlays in v1; comparison for visualizations is a v1.1 candidate.

## Date range picker behavior

Same as Tabs 6/7. The picker affects all sections — every metric is window-scoped. Changing the date range updates every value on the page. The dynamic section headers used on Tabs 6/7 ("In the last 30 days") are not used here; date scope is communicated through the global picker.

## Empty-state details

| Situation | Behavior |
|---|---|
| No GA4 connection | Whole tab replaced by connect banner |
| Scroll tracking disabled (enhanced measurement off) | Scroll-dependent cards (Article Completion Rate, Articles by Completion Rate, and the scroll signal in Most-Read Articles' ranking) show the scroll overlay |
| `post_id` not registered (Most-Read Articles) | Per-card overlay with the dimension name |
| `author` not registered (Top Authors by Avg Engagement Time) | Per-card overlay with the dimension name |
| `is_newsletter_subscriber` not registered | Per-card overlay with the dimension name |
| Zero traffic in window | Scorecards render 0 / 0% / 0:00, tables render empty state, chart renders empty axes |

## Open questions

Surface during build, decide based on real data:

1. **Custom dimension detection.** v1.1 should add a boot-time probe that warns admins if any expected custom dimensions (`post_id`, `author`, `is_newsletter_subscriber`) — or GA4 enhanced-measurement scroll tracking — are missing across the property, rather than inferring per-card from empty query results.
2. **Scroll-depth columns appearing at the BQ swap.** The Device Type and Top Authors tables gain an avg-scroll-depth column when BQ ships. Confirm with design that an added column (no restructure) is acceptable.
3. **Composite engagement score.** The Most-Read Articles ranking score is opinionated and v1 approximates scroll depth from scroll-to-90 share. Decide whether the formula should be publisher-configurable in v1.1 and whether the v1→v1.1 ranking shift needs annotation.
4. **Scroll threshold granularity / GA4 overcount edge case.** GA4 enhanced measurement fires `scroll` only at 90% by default, so the v1 GA4 path counts any article-scoped scroll event as "reached 90%" — exact for the default-configured publishers who are the large majority. If a publisher has customized scroll to also fire at 25/50/75%, the GA4 query overcounts (it treats every scroll event as a 90% read); those publishers get accurate scroll-completion numbers only after the BQ swap, which filters `percent_scrolled >= 90` explicitly. Not a v1 blocker. Decide whether to also document recommended finer thresholds for publishers who want richer scroll metrics.
5. **GA4 vs BQ baseline drift.** When BQ ships, engagement numbers will differ slightly from GA4 (engaged-only averaging, returning definition, scroll-depth averaging). Decide acceptable tolerance and whether to annotate the swap.

## BQ query catalog (handoff to NPPD-1630)

When the BQ backend ships, each metric maps to a `query_name` in the Newspack Manager BQ query catalog. Naming convention: `engagement_{metric_slug}`. The GA4 path is the v1 source of truth; the BQ catalog must be validated against GA4 baseline numbers before flipping `NEWSPACK_INSIGHTS_ENGAGEMENT_USE_GA4` to false.
