# Newspack Insights — Data Viz Component Spec

This document is the authoritative reference for the six data viz components and two primitives in the Insights vocabulary. CC and human collaborators should implement against these values, not against principles or descriptions. When a specific value is wrong, fix the spec first, then the components.

Source of truth: extracted from the May 27 component gallery PDF that received the "your mind is blown" reaction. That version was lost to git resets and is being reconstructed.

## Cross-cutting principles

### Type scale

Use this scale across the family. No ad-hoc font sizes.

| Token | Size | Weight | Use |
|---|---|---|---|
| value-lg | 44px | 600 | Scorecard primary value, BoxPlot single-value variants |
| value-md | 32px | 600 | PieChart center total, Funnel side-label counts |
| value-sm | 22px | 600 | Inline counts in compact funnels |
| label | 12px | 600 | Card labels, section labels, axis labels (uppercase, letter-spacing 0.05em) |
| body | 14px | 400 | Description text within cards, table cells, tooltip body |
| body-sm | 13px | 400 | Footnotes, axis tick labels, legend labels |
| meta | 11px | 400 | "Updated X minutes ago" timestamps |

All numeric values use `font-variant-numeric: tabular-nums`.

### Spacing scale

8px base unit. Components only use multiples: 4, 8, 12, 16, 20, 24, 32, 40, 48.

Card-internal vertical rhythm:
- Label → value: 12px
- Value → description: 8px
- Description → footnote: auto (`margin-top: auto` on footnote pins to bottom)
- Card padding: 20px 24px (top/bottom × left/right)

Grid gaps:
- Component-internal grids (e.g., scorecard tile grid): 16px
- Section-level gaps (between Default / States / Tone sections): 32px
- Major section gaps (between Scorecard / Table / Funnel / etc.): 48px

### Color usage

Two-namespace convention with documented rules:

**`wp-colors` for backend admin neutrals:**
- `$gray-900` — primary text (values, headings)
- `$gray-700` — secondary text (descriptions)
- `$gray-600` — tertiary text (footnotes, metadata)
- `$gray-300` — borders, dividers
- `$gray-200` — card borders
- `$gray-100` — Skeleton shimmer, hover backgrounds

**`newspack-colors` for semantic and brand:**
- `$primary-500` — anchor blue (Funnel fills, LineChart anchor series, PieChart anchor)
- `$success-600` — positive tone values
- `$error-600` — negative tone values, error states
- `$alert-yellow` — StalePill base color (via color-mix)

**SERIES_PALETTE** for multi-series charts (LineChart, PieChart):
1. `primary-500` — anchor blue
2. `quaternary-700` — orange
3. `secondary-700` — green
4. `tertiary-700` — pink/mauve
5. `neutral-700` — dark gray
6. `primary-300` — mid blue (6+ series fallback)

Reserved (NOT in series palette): error reds, alert yellows. These mean state, not data.

**Known footgun:** `wp-colors.$gray-700` (#757575) ≠ `newspack-colors.$neutral-700` (#000000b3). Same number, different colors. Always use `wp-colors.$gray-700` for neutrals; only reach into `newspack-colors.$neutral-*` for semantic exceptions documented per-component.

### Card chrome

All component "cards" share this base treatment:
- Background: `#fff`
- Border: `1px solid wp-colors.$gray-200`
- Border-radius: 4px
- Padding: 20px 24px
- Min-height: 140px (Scorecard) — other components fluid

State variants do NOT change the card background. The state shows in the contents.

### Edge state vocabulary

Four states across all six components: `loading`, `error`, `empty`, `stale`. Visual treatment:

- **Loading:** card chrome unchanged. Value slot becomes a Skeleton primitive (shape="rect", height matches value font-size, width ~60% of typical value). Description, footnote, label remain visible.
- **Error:** card chrome unchanged. Value slot replaced with `$error-600` short text describing failure ("Query timed out," "Source unavailable"). Label remains. Description optional. `role="alert"`.
- **Empty:** card chrome unchanged. Value slot shows a single em-dash (`—`) in `wp-colors.$gray-600` at value-md size (smaller than usual value). Description explains why empty.
- **Stale:** card chrome unchanged. Value present. `<StalePill />` rendered top-right of card header row.

Precedence (mutually exclusive, top down): loading → error → empty → value. Stale is independent of all four — can co-occur with value.

### Responsive

Component-level responsive strategy:
- Row-based components (Funnel, Table) reflow internally based on container width via ResizeObserver
- Chart components (LineChart, BoxPlot) wrap in horizontal-scroll container with min-width 360px
- Tile components (Scorecard) use CSS grid `auto-fill, minmax(220px, 1fr)`
- PieChart uses flex-wrap so legend moves below donut at narrow widths

No global breakpoints — each component knows its own collapse threshold.

---

## Scorecard

Single-value metric tile. The base unit of an Insights tab.

### Layout

Column: label (top, with optional StalePill right-aligned in a header row) → value → description → footnote (pinned bottom).

### Dimensions and type

| Element | Spec |
|---|---|
| Card | bg `#fff`, border `1px solid wp-colors.$gray-200`, radius 4px, padding 24px 28px, min-height 160px |
| Header row | flex justify-between align-center, label left + StalePill right |
| Label | 12px / 600 / uppercase / letter-spacing 0.05em / color `wp-colors.$gray-700` |
| Value | 44px / 600 / line-height 1.05 / letter-spacing -0.01em / color `wp-colors.$gray-900` / tabular-nums |
| Value (empty/dash) | 32px / 400 / color `wp-colors.$gray-600` |
| Description | 14px / 400 / line-height 1.4 / color `wp-colors.$gray-700` |
| Footnote | 13px / 400 / color `wp-colors.$gray-600` / margin-top auto |

Gap between label-row and value: 16px. Gap between value and description: 8px. Footnote pinned bottom (margin-top: auto).

### Tone variants

Tone applies only to the value text color (and only when value is not in dash/loading/error state):
- Default: `wp-colors.$gray-900`
- Positive: `newspack-colors.$success-600`
- Negative: `newspack-colors.$error-600`

NO card-wide tinting under any condition.

### Demo content guidance

Default examples should be specific to Newspack use cases. E.g.:
- "REGWALL CONVERSION RATE" / "12.34%" / "Registrations attributed to a gate ÷ gate impressions that offered registration." / "Updated 12 minutes ago · last 30 days"
- "PAYWALL CONVERSION RATE" / "2.18%" / "Paid checkouts attributed to a gate ÷ gate impressions with a checkout button." / "Updated 12 minutes ago · last 30 days"
- "TOTAL REVENUE FROM PAYWALL" / "$8,432" / "Sum of paid-checkout amounts attributed to gates." / "Updated 12 minutes ago · last 30 days"

NOT generic ("Monthly Active Readers / 12,847"). The contextual labels demonstrate what the component is for.

---

## Table

Tabular metric data with sortable headers.

### Layout

Standard HTML table inside a card chrome. Optional `__chrome` strip above the table for the stale pill.

### Dimensions and type

| Element | Spec |
|---|---|
| Wrapper card | bg `#fff`, border `1px solid wp-colors.$gray-200`, radius 4px, padding 0 (table fills) |
| Stale chrome strip (when stale) | padding 12px 20px, border-bottom `1px solid wp-colors.$gray-200`, flex justify-end |
| Header row (`<thead> <tr>`) | border-bottom `1px solid wp-colors.$gray-300`, bg `wp-colors.$gray-100` |
| Header cell (`<th>`) | padding 12px 20px, 12px / 600 / uppercase / letter-spacing 0.04em / color `wp-colors.$gray-700` / text-align left (right for numeric) |
| Sort button (inside `<th>`) | full-width, flex align-center gap 4px, transparent bg, cursor pointer |
| Sort indicator (chevron) | 14×14 SVG, opacity 0.3 (inactive sortable), 0.7 (hover), 1.0 (active) |
| Body row (`<tr>`) | border-bottom `1px solid wp-colors.$gray-200` (last row none) |
| Body cell (`<td>`) | padding 14px 20px, 14px / 400 / color `wp-colors.$gray-900` / tabular-nums |
| Empty cell ("—") | color `wp-colors.$gray-600` |
| Empty/error full-row | padding 20px, text-align center, 14px / 400 italic, color varies by state |

### Edge states

- **Loading:** 5 skeleton rows by default (overridable). Each cell has a Skeleton rect, height 12px, width 40% (right-aligned columns) or 70% (left-aligned columns).
- **Empty:** thead preserved, body is single row with `colspan={columns.length}`, italic gray text ("No data").
- **Error:** thead preserved, single row with `colspan`, error text in `newspack-colors.$error-600`, role="alert".
- **Stale:** chrome strip above table with stale pill right-aligned. Table content unchanged.

### Demo content guidance

Tables in the gallery should show realistic Newspack data: distribution buckets, top pages by registration conversion, etc. Not "Article / Views / Conversion" generic — use specific publisher-shaped examples.

---

## Funnel

Multi-step conversion funnel as SVG trapezoids.

### Two modes

Mode selection is automatic via `ResizeObserver` on container + step count check:

- **Side-label mode** (default): `stepCount < 5 AND containerWidth >= 480px`. Step name + count + deltas render in a fixed 200px column to the right of each trapezoid.
- **Compact mode**: `stepCount >= 5 OR containerWidth < 480px`. Step count renders inside each trapezoid; full step names + counts + deltas move to an HTML legend below the SVG.

### SVG sizing (critical — got this wrong in rebuild)

- SVG `viewBox` is computed from container width × proportional height
- `preserveAspectRatio="xMidYMid meet"`
- SVG element fills container width: `width: 100%, height: auto, max-height: 480px`
- Trapezoid heights: total chart height ÷ step count (with minimum 32px per step)

Each trapezoid:
- Top-width: proportional to its count relative to first-step count
- Bottom-width: proportional to next step's count (last trapezoid is rectangle: top-width = bottom-width)
- Y position: stacked sequentially with no gap (trapezoids touch)

### Color and opacity

Single anchor color: `newspack-colors.$primary-500` for all trapezoid fills. Opacity interpolation:
- Step 1: opacity 1.0
- Last step: opacity 0.6
- Linear interpolation between

Text inside trapezoid (compact mode): `#fff` when fill opacity > 0.75, else `wp-colors.$gray-900`.

### Deltas

For each step beyond the first, display two delta values:
- "X.X% of top": neutral, `wp-colors.$gray-600`, 13px / 400
- "X.X% from previous": bold, tabular-nums, color depends on drop
  - Drop ≤ 20%: `wp-colors.$gray-900`
  - Drop > 20%: `newspack-colors.$error-500`

Named constant: `DROP_HIGHLIGHT_THRESHOLD = 0.20`.

### Demo content guidance

The gallery should show TWO funnel examples:
1. **Side-label mode** with 3-4 steps: e.g., "Anonymous (40,000) → Registered (8,000) → Subscriber (450)" with real readership funnel language
2. **Compact mode** with 5+ steps: e.g., "Anonymous → Engaged → Registered → Returning → Subscriber" demonstrating the legend pattern

Plus a States row showing loading/error/empty/stale.

---

## LineChart

Time-series line chart, single or multi-series, with optional reference lines.

### Layout

Card chrome → optional `__chrome` strip (stale pill) → `__scroll` wrapper (overflow-x auto) → `__inner` (min-width 360px) → Recharts ResponsiveContainer.

### Dimensions and type

| Element | Spec |
|---|---|
| Container height | 280px default, settable via prop |
| Margin | top: 16, right: 24, bottom: 32 (or 56 if xLabel), left: 24 (or 48 if yLabel) |
| Axis tick label | 13px / 400 / color `wp-colors.$gray-700` |
| Axis label (when present) | 13px / 600 / color `wp-colors.$gray-700` |
| Grid lines | strokeDasharray="3 3", stroke `wp-colors.$gray-200`, horizontal only |
| Line stroke | 2px, no animation (`isAnimationActive={false}`) |
| Dot | r=3, fill matches stroke |
| Active dot | r=5, fill matches stroke, stroke #fff 2px |
| Reference line | strokeDasharray="6 4", stroke `wp-colors.$gray-600` default, label position "insideTopRight" |

### Tooltip

White card (this is the family tooltip pattern, reused by BoxPlot and PieChart):
- bg #fff, border 1px solid `wp-colors.$gray-300`, radius 4px, padding 8px 12px
- box-shadow: 0 2px 8px rgba(0,0,0,0.08)
- min-width: 140px
- Header (x-axis value): 12px / 600 / color `wp-colors.$gray-900`, border-bottom 1px solid `wp-colors.$gray-200`, padding-bottom 4px
- Each row: flex align-center gap 8px
  - Swatch dot: 8px circle, color matches series
  - Series label: 13px / 400 / color `wp-colors.$gray-700`
  - Value: 13px / 600 / tabular-nums / color `wp-colors.$gray-900` / margin-left auto

### Value formatting

Shared `buildValueFormatter(format: 'number' | 'percent' | 'currency')` used by both axis ticks and tooltip values. Currency defaults to USD with `currencyCode` prop override.

### Demo content guidance

Three line chart examples in the gallery:
1. Single series with reference line — "Cohort retention by registration month" with horizontal dashed line at target (e.g., 30%)
2. Multi-series (2-3 lines) — "Daily registrations, last 14 days" with anonymous vs registered vs returning lines
3. Currency format — "ARPU cohort retention vs. 10% target"

---

## BoxPlot

Distribution visualization with min / Q1 / median / Q3 / max + outliers.

### Critical implementation notes (don't repeat yesterday's bugs)

1. **Invisible `<Bar dataKey="median">`** inside `<ComposedChart>` is required for Recharts to compute the band scale correctly. Without it, single-category renders draw at offset 0 with NaN positions.
2. **NEVER native SVG `<title>` for tooltips.** Use Recharts' `<Tooltip>` with custom content. Native title fails because Recharts' chart-level event capture intercepts pointer events.
3. **`pointer-events: none`** on the visible shapes `<g>` group. The invisible Bar owns hover detection.
4. **Min 2px box height** to prevent vanishingly small IQRs from disappearing.
5. **Explicit y-domain required for heavy right-skew data.** YAxis auto-domain only covers the median range; whiskers/outliers can extend beyond. The component should accept a `yDomain={[min, max]}` prop; demo gallery should compute domains explicitly per example.

### Dimensions and type

| Element | Spec |
|---|---|
| Container height | 320px default |
| Box width algorithm | `Math.min(40, Math.max(8, bandWidth * 0.6))` |
| Box fill | `newspack-colors.$primary-500` at 28% opacity |
| Box outline | `newspack-colors.$primary-500` at 100%, stroke-width 1.5 |
| Median line | `newspack-colors.$primary-600`, stroke-width 2 |
| Whisker line | `newspack-colors.$primary-500` at 100%, stroke-width 1 |
| Whisker cap | width 50% of box width |
| Outlier circle | r=3, fill #fff, stroke `newspack-colors.$primary-600` 1px |
| Y-axis ticks | 13px / 400 / `wp-colors.$gray-700` |
| X-axis categories | 13px / 400 / `wp-colors.$gray-700` |

### yScale prop

- `'linear'` (default)
- `'sqrt'` — opt-in for heavy-outlier cases where extreme values would otherwise compress the boxes. Data must be ≥0.

### Tooltip

Same family tooltip pattern as LineChart, but rows are: Max / Q3 / Median / Q1 / Min, plus a meta "N outliers" row at the bottom (with top-border separator) when outliers exist.

### Demo content guidance

Three BoxPlot examples:
1. Linear scale (default) — "Time to convert (days), Gates v. Anonymous" with 4 categories
2. Sqrt scale — same data, sqrt y-axis demonstrating the opt-in compression
3. Single category focused view — "Anonymous → Registration time, last 30 days" with one box

All three demonstrate explicit `yDomain` usage so boxes render correctly.

---

## PieChart

Donut chart with center total and auto-grouping into "Other (N more)" segment.

### Dimensions and geometry

| Element | Spec |
|---|---|
| Container | flex with flex-wrap, gap 24px, align-items center |
| Donut wrapper | flex 0 0 auto, position relative, square (height = width) |
| Donut height | 200px default, settable via prop |
| innerRadius | "60%" of outerRadius |
| outerRadius | "90%" of container half-width |
| paddingAngle | 1 (degree gap between segments) |
| Segment stroke | #fff 2px |
| Animation | `isAnimationActive={false}` |
| Center label container | absolute, top 50% / left 50%, transform translate(-50%,-50%), flex column align-center, pointer-events none, aria-hidden true |
| Center label "Total" | 12px / 600 / uppercase / `wp-colors.$gray-700` |
| Center value | 28px / 600 / `wp-colors.$gray-900` / tabular-nums |
| Legend wrapper | flex 1 1 200px, min-width 200px |
| Legend item | flex align-center gap 8px, padding 4px 0 |
| Legend swatch | 12×12 rounded 2px, color matches segment |
| Legend label | 13px / 400 / `wp-colors.$gray-700` |
| Legend "(N more)" | inline after label, 12px / 400 / `wp-colors.$gray-600` |
| Legend value | 13px / 600 / tabular-nums / `wp-colors.$gray-900` / margin-left auto |
| Legend percent | 13px / 400 / `wp-colors.$gray-600` / inline after value |

### Auto-grouping

- Sort segments by value descending
- If `segments.length > maxSegments` (default 7), top `maxSegments - 1` render individually with palette colors; remaining segments aggregate into single "Other" segment in `wp-colors.$gray-400`
- "Other" legend entry shows "(N more)" inline
- Hovering "Other" segment expands tooltip to show each child label + value + subtotal

### Demo content guidance

Six PieChart examples to demonstrate the full range:
1. Default (2 segments) — "Donor mix, last 90 days" simple binary
2. Multi-segment (5) — "Sessions by traffic source, last 30 days"
3. Content type (4) — "Views by content type, April 30 days"
4. Many segments (10+) — demonstrates default auto-grouping (maxSegments=7)
5. Aggressive grouping — same data but maxSegments=4 (most things aggregate to "Other")
6. Currency with custom center label — "Revenue by tier" with center showing total revenue and "Total revenue" label

---

## Demo gallery wizard

Page at `?page=newspack-data-viz-demo`. Hidden from main Newspack menu, accessible by URL.

### Layout

- Page title: "Data viz component gallery" 28px / 600
- Page subtitle: 14px / 400 / `wp-colors.$gray-700` describing purpose
- Sticky TOC at top: 6 component anchor links in a horizontal row, 13px / 500
- Each component section: 32px gap above (48px before first), h2 title 22px / 600, optional 14px description below

### Per-component sections

Each component gets:
1. Section header with name + 1-sentence description of what it does
2. **DEFAULT** subsection — realistic populated examples
3. **STATES** subsection — loading/error/empty/stale variants in a grid
4. Optional **variant** subsections (Scorecard tone, BoxPlot scales, PieChart aggressive grouping)

### Narrow container test

Last section: 4-column CSS grid at ~300px each showing Funnel/LineChart/BoxPlot/PieChart side-by-side, demonstrating the responsive behavior of each chart type at narrow widths.

---

## What's NOT in this spec

- Exact icon assets (chevron up/down for sort — use @wordpress/icons)
- Animation easing curves (we don't have animation; family rule is `isAnimationActive={false}` everywhere)
- Print styles
- High-contrast / dark mode (out of scope for v1)
- Internationalization details beyond `__()` wrapping (handled at React level)

## Open questions

- Whether to extract the tooltip styles to a shared `_tooltip.scss` after we have 3 chart components using them (currently duplicated per component). Decide when the 4th chart component arrives, if it does.
- Whether to standardize on `wp-colors` for all neutrals across the family vs. keeping current Scorecard exception. Defer until visual review of the rebuilt components is complete.

## Changelog

- 2026-06-03: Initial spec written from May 27 PDF after components rebuild lost the original code. See conversation history for context.
