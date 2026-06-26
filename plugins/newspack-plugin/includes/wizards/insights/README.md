# Newspack Insights

Native data hub for Newspack publishers. Surfaces audience, engagement, conversion, gates, prompts, subscribers, donors, and advertising data inside a single `wp-admin` wizard, replacing publisher reliance on Looker Studio dashboards.

This README documents what is actually implemented today. Keep it in sync as the code changes — there is no separate spec doc.

## Feature flags

All gated by PHP constants. The wizard registers nothing when `NEWSPACK_INSIGHTS_ENABLED` is off.

| Constant | Effect |
| --- | --- |
| `NEWSPACK_INSIGHTS_ENABLED` | Master switch. When off, no admin page, no REST routes, no asset enqueue. |
| `NEWSPACK_INSIGHTS_GATES_PREVIEW` | Shows the Gates (Tab 4) nav entry and activates its REST route. Requires `NEWSPACK_INSIGHTS_ENABLED` to be on as well — the section bails before either check otherwise. The two flags are kept separate so the Phase 1 preview can ship to a subset of Insights-enabled environments. |
| `NEWSPACK_INSIGHTS_ADVERTISING_ENABLED` | Shows the Advertising (Tab 8) nav entry and activates its GAM-backed orchestrator. Also requires `NEWSPACK_INSIGHTS_ENABLED`. |
| `NEWSPACK_INSIGHTS_FIXTURE_MODE` | REST controllers that wrap a metric with a `get_fixture()` method short-circuit to fixtures instead of live data. Used for UI smoke testing. (Conversion, Subscribers, and Donors don't implement fixtures today; see [Metric orchestrators](#metric-orchestrators-metricsclass--metricphp).) |
| `NEWSPACK_INSIGHTS_CACHE_DISABLED` | Bypass the server-side transient cache entirely. Dev/debug only. |
| `NEWSPACK_INSIGHTS_AUDIENCE_USE_GA4`, `NEWSPACK_INSIGHTS_ENGAGEMENT_USE_GA4` | Per-tab backend dispatch (default on). When off, the metric would route to the BigQuery proxy path — currently a stub until NPPD-1630. |

See [`class-insights-wizard.php`](class-insights-wizard.php) for the canonical definitions and tab-visibility rules.

## Layout

```
includes/wizards/insights/
├── class-insights-wizard.php       Admin page registration, boot config, tab visibility
├── class-insights-section-*.php    Per-tab init: loads metric + REST controller, registers route
├── class-cache.php                 Shared transient wrapper with per-source TTLs + BQ cooldown
├── class-bigquery-proxy-client.php Hub-proxied BigQuery queries (Newspack Manager auth)
├── api/                            REST controllers (one per tab) + Cached_Controller_Trait
├── classifiers/                    Donation product classifier (cached)
├── fixtures/                       Per-tab fixture payloads for FIXTURE_MODE
├── ga4/                            GA4 Data API client (runReport primitives)
├── gam/                            Google Ad Manager async SOAP reporting client
├── metrics/                        Per-tab orchestrators (compose clients, normalize, cache)
└── storage/                        HPOS- and legacy-Woo storage adapters behind a shared interface

src/wizards/insights/               (frontend)
├── index.tsx                       Reads window.newspackInsights, mounts InsightsWizard
├── components/InsightsWizard.tsx   Shell: tab nav, date picker, comparison toggle, refresh menu
├── state/                          Cache, refresh registry, URL-persisted date range + comparison
├── hooks/use*Data.ts               Per-tab data hooks (one for each tab)
├── api/                            Per-tab fetch wrappers (typed responses)
├── tabs/                           Per-tab views; shared atoms in tabs/components/
└── types.d.ts                      Shared types (MetricPayload, WindowMeta, etc.)
```

## Backend pieces

### Sections (`class-insights-section-*.php`)

One per tab. Each section's `init()` is called from [`includes/class-wizards.php`](../../class-wizards.php) during wizard bootstrap. The section bails immediately when `Insights_Wizard::is_enabled()` is false; Gates and Advertising sections also gate on their per-tab preview/enabled constants. When the gate passes, the section:

1. Loads the tab's `metrics/class-*-metric.php` and `api/class-*-rest-controller.php` files.
2. Registers the REST route on the `rest_api_init` hook.
3. (Subscribers only) Wires up `Donation_Product_Classifier` cache-invalidation hooks for donation-product meta and option changes.

Sections hold no state — they're thin route-registration shims.

### Metric orchestrators (`metrics/class-*-metric.php`)

The unit of work per tab. Each metric class exposes:

- `get_all( $start, $end, $compare = null )` — full tab payload (current window plus optional comparison window).
- `connection_error()` — early gate check returning `{ tab_error, banner_text }` when preconditions (OAuth, GAM activation, etc.) fail. Returns `null` when ready.
- `get_fixture( ... )` — deterministic mock payload used by `FIXTURE_MODE`. Implemented on Audience, Engagement, Gates, Prompts, and Advertising; not on Conversion, Subscribers, or Donors (those tabs either return inline placeholders or read from local Woo data already, so the fixture path hasn't been needed).

Internally orchestrators compose data-client calls, normalize results into payload envelopes, and cache windows via [`class-cache.php`](class-cache.php). The envelope shapes used by the React layer are:

| Type | Shape |
| --- | --- |
| Scalar | `{ value, computable, type: 'count' \| 'decimal' }` |
| Rate | `{ value, computable, type: 'rate', numerator?, denominator? }` |
| Rows | `{ rows, computable, type: 'breakdown' \| 'table' \| 'timeseries' }` |
| Overlay | `{ value: null, computable: false, overlay: { type, dimensions } }` |
| Hidden | `{ value: null, computable: false, hidden_in_v1: true }` |

Current tab status:

| Tab | Source | Notes |
| --- | --- | --- |
| Audience (1) | GA4 Data API | Default path; BQ v1.1 path is stubbed behind `NEWSPACK_INSIGHTS_AUDIENCE_USE_GA4`. |
| Engagement (2) | GA4 Data API | Same dispatch pattern (`NEWSPACK_INSIGHTS_ENGAGEMENT_USE_GA4`). |
| Conversion (3) | Inline placeholders | The metric returns synthetic payloads with `pending: true` per shape until NPPD-1630 Phase 2 lands. No BigQuery calls and no fixtures wired today. |
| Gates (4) | BigQuery | Preview-flag gated. |
| Prompts (5) | BigQuery | Conversion funnels. |
| Subscribers (6) | Local Woo | Reads via [`storage/`](storage/). |
| Donors (7) | Local Woo | Donation-scoped queries via the donors storage interface. |
| Advertising (8) | GAM async SOAP | Polls async report jobs; gated by `NEWSPACK_INSIGHTS_ADVERTISING_ENABLED` and a runtime "GAM active" check. |

### Data clients

- **[`class-bigquery-proxy-client.php`](class-bigquery-proxy-client.php)** — `wp_remote_post` to the hub's `/wp-json/newspack-manager-admin/v1/bigquery-query` endpoint. Auth via Newspack Manager admin signing. Date inputs normalized to UTC `Ymd` (GA4 daily-shard format). Returns `WP_Error` on every failure path; logs to Logstash with `NEWSPACK-INSIGHTS-BIGQUERY` header.
- **[`ga4/class-client.php`](ga4/class-client.php)** — `runReport` primitives. Reuses Newspack's existing Google OAuth (`analytics` scope already granted; no re-auth). Pre-flight check inspects `customEvent:<param>` references against `GA4_Custom_Dimensions::get_registered_parameter_names()` and returns a `custom_dimension_missing` `WP_Error` when the property doesn't have the dimension registered. Per-request memo cache on registered-dimension lookups.
- **[`gam/class-client.php`](gam/class-client.php)** — Async SOAP via the vendored `googleads-php-lib` (`NEWSPACK_ADS_COMPOSER_ABSPATH`). OAuth-only (no service-account fallback — service accounts are an OSS path and don't apply to managed customers). `is_gam_active()` reads the Ad Providers option; `can_run_reports()` does a one-shot tokeninfo + network-code check (don't call on every poll).

### REST API (`api/`)

Namespace: `newspack-insights/v1`. The standard shape used by every cached tab is:

- `GET  /newspack-insights/v1/<tab>` — initial fetch.
- `POST /newspack-insights/v1/<tab>/refresh` — manual cache invalidation. Always returns 200; `cooldown_until` in the envelope signals throttle to the client.

Schematic response envelope (illustrative — actual `cache` values are populated strings/timestamps):

```
{ cache: { source, computed_at, cooldown_until }, data: { ... } }
```

`Cached_Controller_Trait` ([`trait-cached-controller.php`](api/trait-cached-controller.php)) wraps GET/POST in cache orchestration. Concrete controllers declare `cache_source()` (one of `SOURCE_EXTERNAL`, `SOURCE_BIGQUERY`, `SOURCE_LOCAL`) and `tab_slug()`. Cached responses set `Cache-Control: no-store, private` so the browser never caches over the server-side transient.

**Exception:** Conversion (Tab 3) does *not* use `Cached_Controller_Trait` while it returns inline placeholders. It registers a single `GET /newspack-insights/v1/conversion` route, returns the metric output directly, and has no `/refresh` route or cache envelope. It will adopt the standard pattern when NPPD-1630 lands.

### Caching (`class-cache.php`)

| Source | TTL | Notes |
| --- | --- | --- |
| `SOURCE_BIGQUERY` | 1 day | Expensive; 10-minute refresh cooldown per tab via `bq_cooldown_until()`. |
| `SOURCE_EXTERNAL` | 10 minutes | GA4. |
| `SOURCE_LOCAL` | none | Direct pass-through; the local DB is already cheap. |

Transient keys: `newspack_insights_<tab>_<md5(start,end,compare_start,compare_end)>`. Each tab maintains a key index (`newspack_insights_index_<tab>`, FIFO-capped at 200) so refreshes can sweep all windows for a tab; transients still expire naturally on TTL.

`NEWSPACK_INSIGHTS_CACHE_DISABLED` short-circuits the wrapper entirely.

### Storage (`storage/`)

Two backends implement [`class-storage-interface.php`](storage/class-storage-interface.php):

- [`class-hpos-storage.php`](storage/class-hpos-storage.php) — queries `wp_wc_orders` + `wp_wc_orders_meta`.
- [`class-legacy-storage.php`](storage/class-legacy-storage.php) — mirrors the same surface against `wp_posts` + `wp_postmeta`.

[`class-storage-detector.php`](storage/class-storage-detector.php) caches `woocommerce_custom_orders_table_enabled` for 24h (one-way migration, safe to cache aggressively). Subscribers/Donors metrics call `Storage_Detector::detect()` at request time and dispatch.

Donors has its own narrower interface ([`class-donors-storage-interface.php`](storage/class-donors-storage-interface.php) plus HPOS/legacy impls) because Tab 7 queries donation products exclusively and doesn't need the "non-donation" exclusion paths.

### Classifiers (`classifiers/`)

[`class-donation-product-classifier.php`](classifiers/class-donation-product-classifier.php) computes the union of (a) products flagged `_newspack_is_donation`, (b) their variations, and (c) the canonical Newspack donation family. Caches the resulting ID set for 1h and invalidates on the relevant `*_post_meta` and `update_option_newspack_donation_product_id` hooks. Used by Subscribers and Donors metrics and by the wizard's `has_donation_activity()` visibility check.

### Fixtures (`fixtures/`)

One per tab. Returned by `Metric::get_fixture()` when `NEWSPACK_INSIGHTS_FIXTURE_MODE` is on. Values are computed from `current_datetime()` so they never go stale. Fixtures only cover the happy path — error and overlay states are exercised by component tests, not fixtures.

## Frontend pieces

### Entry & shell

- [`src/wizards/insights/index.tsx`](../../../src/wizards/insights/index.tsx) reads `window.newspackInsights` (set by the PHP wizard via `wp_localize_script`) and renders `InsightsWizard`.
- [`InsightsWizard.tsx`](../../../src/wizards/insights/components/InsightsWizard.tsx) hosts the shell via the shared `Wizard` component from `packages/components/src`. Tab routing is hash-based (`#/audience`); a one-shot mount effect rewrites legacy `?tab=X` URLs. Each tab is a lazy chunk inside a `TabSection` error boundary + `Suspense`.
- Header chrome above tab sections: `DateRangePicker`, `ComparisonToggle`. `RefreshMenu` lives in the Wizard footer area and dispatches via the refresh registry.

### State (`state/`)

- [`insightsCache.ts`](../../../src/wizards/insights/state/insightsCache.ts) — module-level fetch-dedupe cache. Slot keys embed tab + range + optional comparison window. Shared across lazy chunks via `window.__newspackInsightsCache`. `ensureFetched()` dedupes; `refresh()` re-runs unconditionally and respects `cooldown_until` from the server.
- [`useDateRange.ts`](../../../src/wizards/insights/state/useDateRange.ts) — URL-persisted (`?range=&start=&end=`), with preset computation (`last-7`, `last-30`, `last-90`, `this-month`, `last-month`, `custom`). Falls back to boot config.
- [`useComparisonMode.ts`](../../../src/wizards/insights/state/useComparisonMode.ts) — URL-persisted toggle. Computes a same-length prior window when enabled.
- [`refreshRegistry.tsx`](../../../src/wizards/insights/state/refreshRegistry.tsx) — `useRegisterRefresh(tab, fn)` from each data hook; `useInvokeRefresh()` from the header. Decouples the refresh button from the active tab.

### Data hooks (`hooks/use*Data.ts`)

Eight hooks, one per tab, all the same shape:

```ts
const { status, data, error, refetch, computedAt, source, cooldownUntil }
  = useFooData( range, previousRange );
```

Each hook builds a cache key, subscribes via `useSyncExternalStore`, kicks off `ensureFetched` on mount/range change, and registers its `refetch` with the refresh registry. The API call goes through [`api/`](../../../src/wizards/insights/api/) which thinly wraps `@wordpress/api-fetch`.

### Tabs (`tabs/`)

Each tab is a lazy-loaded `.tsx` file that calls its data hook, hands the result to `TabStateView` for loading/error/empty chrome, and renders sections. Section layouts differ — some tabs use `sections/` + `viz/` subdirs (Audience, Engagement, Gates, Advertising), others compose inline (Subscribers, Conversion). See each tab's directory for shape.

### Shared tab atoms (`tabs/components/`)

| Component | Purpose |
| --- | --- |
| `MetricCard` | Scorecard atom — label/value/delta/description. Wraps `Card` from `@wordpress/components` (`__experimentalCoreCard`). Renders overlay / error / not-configured states. |
| `MetricTable` | Tabular metric display with optional expandable row limit. |
| `SectionHeading` | h2 + optional description + optional actions slot. Wraps newspack `SectionHeader`. |
| `InfoCallout` | Dismissible info banner (persistent or session) via `@wordpress/components` Notice. |
| `CooldownNotice` | Live-ticking countdown banner wired to `cooldownUntil`. Auto-dismisses on tick-out. |
| `TabStateView` | Centralized fetch-lifecycle chrome (spinner / error / muted-refetch). |
| `TabErrorBanner`, `ConnectBanner`, `FinishConnectingDiagnostic`, `DataLagIndicator`, `TabLoading`, `TabSpinner` | Tab-specific UI helpers. |
| `metrics.ts` | Shared types for the payload envelopes the PHP layer emits. |
| `format.ts` | Number / currency / percent / duration / delta formatters with tone (green/red) logic. |

The shell and these atoms intentionally lean on `newspack-components` (`Wizard`, `SectionHeader`, `Badge`, `Notice`, `Waiting`, imported from `packages/components/src`) and `@wordpress/components` (`Card`, `Notice`, `Button`) for design-system alignment.

## Empty-state voice & tone (canonical reference)

The standard every Insights tab's empty-state copy conforms to, established by the NPPD-1698 cross-tab audit. New per-tab surfaces follow it; where a tab's data shape genuinely differs, pick the closest pattern below rather than inventing a new one.

### 1. Altitude — which empty primitive to use

| Situation | Primitive |
| --- | --- |
| A *scorecard* section whose cards are one coherent story and would all read zero together | whole-section `EmptyMetricSection` (`no_opportunity` / `no_conversions` / `configuration_missing`) |
| A single zero card inside an otherwise-populated scorecard section | per-card treatment (`zeroFallback`, or a `MetricCard` secondary line) |
| A *collection* metric (table / funnel / chart) with no rows | `SectionEmpty` (the plain "no data" paragraph) |

Gates' Paid reader conversion uses a whole-section `no_conversions` because its four cards are a single funnel — collapsing them is honest. The mixed-content `WindowedSection`s (Donors, Subscribers) and Advertising's Reach & revenue use *per-card* treatments for the same logical state, because a whole-section collapse there would hide real data in sibling cards. Both altitudes are correct for their data shape; don't force one onto the other.

### 2. Vocabulary

- **"timeframe"** is the term for the selected reporting period. Never "window" in reader-facing copy. ("window" stays fine for internal field/variable names, and for genuinely different concepts — e.g. the fixed *14-day attribution window* / *lookback window*, which is not the selected timeframe.)
- **"date range"** is reserved for *imperative closers* that tell the publisher to act on the picker control ("Worth expanding the date range…"). It names the actual UI control, so the instruction is concrete. Don't use "date range" mid-sentence to refer to the period — use "timeframe".

### 3. Sentence structure

- **Whole-section bodies** follow a four-beat skeleton: *[what's zero] · [reassurance the feature is configured] · [plausible causes] · [closer]*. Three to four sentences.
- **Per-card secondary lines** are a single terse, count-led clause: `{N} active donors, but none new this timeframe`. No second-person framing, no trailing period.
- **Prompts `notCapable`** is two sentences (*[what's missing] · [what to add]*); **`notComputable`** is one. Both end with a period.

### 4. `{N}` interpolation

Lead with the count where it's a standing population (active donors / subscribers) or an in-window volume (impressions). Gates' mid-sentence "reached {N} readers" is the funnel-altitude exception. Always format the count through the shared `formatNumber`.

### 5. Good-zero handling

A "good zero" is a metric whose zero is the desired outcome. Rule, split by format:

- **Rate-format good-zeros** get explicit positive/neutral copy via the em-dash treatment — e.g. Subscribers' *"No refund requests in this timeframe."* (orders but no refunds) and *"No failed payments in this timeframe."* (no retries to recover).
- **Count-format good-zeros** rely on the native `lowerIsBetter` visual (the green down-delta) and render the real `0` with no editorial copy — e.g. Churned subscribers, Lapsed donors.

A computable zero that is *not* good (e.g. a 0% recovery rate when retries did happen) renders as the real value, not a reframe.

### 6. Action closers

- **Diagnostic closers** use the "Worth [verb-ing] …" form ("Worth expanding the date range…").
- **Navigational closers** ("See the per-gate breakdown below…") are used only when there's a real on-page destination, and may stand instead of a diagnostic closer.

### 7. Em-dash vs value rendering

- Render the **em-dash (`—`)** hero when the value carries no signal: no opportunity count (`zeroFallback` denominator 0), not capable, not computable, or a good-zero reframe.
- Render the **real value** when the zero itself is the observation — the per-card `no_conversions` states (New donors `0`, Total Revenue `$0.00`) keep their real hero plus a secondary line.
- Render a **text fallback** (`0 of 17`, `0 conversions`) when a zero needs companion context to read honestly.

This is the existing convention — codified, not changed.

### 8. Period-delta suppression

Suppress the period-over-period delta on every empty/fallback hero (per-card `no_conversions`, all `zeroFallback`, `notCapable`/`notComputable`) — a "↓ 100%" against a real prior reads as a regression rather than "nothing here yet." Preserve the delta on real computed values, including `lowerIsBetter` good-zero count cards (the green delta *is* the signal).

### 9. Punctuation & fallbacks

Sentence-form empty-state copy ends with a period. Generic safety-net fallbacks share the per-intent voice ("Not measurable for your active prompts.", "Data temporarily unavailable.").

### 10. Per-tab character is preserved, not flattened

This reference standardizes *structure*, not personality. Subscribers' good-zero copy is intentionally warmer ("No failed payments in this timeframe.") than Advertising's terse data notes ("No ad revenue in this timeframe.") — both are right for their context. When a tab's voice and its data shape pull toward a warmer or terser register, follow the register; conform to the patterns above, not to a single flattened tone.

## Subscriber churn metric (canonical reference)

Verified semantics of the Subscribers tab "Churned subscribers" count (NPPD-1724 audit + site-data verification). The query looks wrong — it inner-joins on `_schedule_cancelled` while counting expirations — but is correct; the notes below document why, so it isn't "fixed" into a regression.

### What it counts

Distinct customers whose last non-donation subscription reached `wc-cancelled` or `wc-expired`, attributed to the timeframe by `_schedule_cancelled`, with no remaining `wc-active` non-donation subscription. Query: `get_churned_subscribers_in_window()` in [`storage/class-hpos-storage.php`](storage/class-hpos-storage.php) (line 173), mirrored against legacy CPT storage in [`storage/class-legacy-storage.php`](storage/class-legacy-storage.php) (line 195). Non-donation only; the Donors-tab equivalent and its product-classification gap are NPPD-1736, out of scope here.

### Both statuses, one bucket

`wc-cancelled` and `wc-expired` count together. The dominant flow on Newspack publishers is `active → pending-cancel → expired`, not `active → cancelled`: readers cancel, ride out the paid period, then expire. Splitting the statuses separates "cancelled immediately" from "cancelled and lapsed," not voluntary from involuntary — not a distinction the metric draws. Together they mean subscribers lost in the timeframe. Transition-pattern follow-up: NPPD-1735.

### `_schedule_cancelled` as the date key

The schema reference implies `_schedule_cancelled` is set only on cancellation, so the inner join should drop natural expirations. It doesn't — because expirations arrive via the cancel-then-expire flow, the cancel timestamp is already set. Verified populated on 100% of expired subscriptions, none empty:

| Site | Expired subs | `_schedule_cancelled` populated |
| --- | --- | --- |
| Richland Source | 392 | 100% |
| Block Club Chicago | 2,542 | 100% |

The field anchors the loss to when cancel was clicked, regardless of terminal status. The `!= ''` guard covers the empty edge case (removed nothing on either site). The audit's hypothesized COALESCE-onto-`_schedule_end` fix (F1) would be a no-op, so the SQL is unchanged.

### Excluded

| Status | Why |
| --- | --- |
| `wc-pending-cancel` | Cancellation scheduled but still entitled through the paid period — not yet lost. Counted when it transitions to `cancelled`/`expired`. |
| `wc-on-hold` | Failed renewal inside the retry window — may recover. Counted only if it later reaches a terminal status. |

D1 decision, confirmed against the data; counting either early overstates churn.

## Testing

Jest, colocated `*.test.ts(x)` next to the source. Key spots:

- `state/insightsCache.test.ts` covers slot dedupe and refresh semantics.
- Per-tab component tests verify rendering against representative payloads (loading, error, partial data, overlays, comparison on/off).
- PHP unit tests live under `plugins/newspack-plugin/tests/unit-tests/` — both as loose `insights-*.php` files (e.g. `insights-audience-metric.php`, `insights-cache.php`) and inside the `insights/` subdirectory.

`NEWSPACK_INSIGHTS_FIXTURE_MODE` is the recommended path for manual UI smoke testing without a live GA4 or GAM connection.

## Adding a new tab

1. Add a `class-insights-section-<tab>.php` that loads the metric + controller and registers the route on `rest_api_init`.
2. Add `metrics/class-<tab>-metric.php` implementing `get_all` and `connection_error`. Add `get_fixture` if the tab needs `FIXTURE_MODE` support.
3. Add `api/class-<tab>-rest-controller.php` using `Cached_Controller_Trait`, declaring `cache_source()` and `tab_slug()`.
4. (If using fixtures.) Add `fixtures/<tab>-fixture.php` returning a representative payload.
5. Add the section to the bootstrap list in [`includes/class-wizards.php`](../../class-wizards.php), and add the tab key to the visibility map in `class-insights-wizard.php::get_boot_config()`.
6. Frontend: add `hooks/use<Tab>Data.ts`, `api/<tab>.ts`, and `tabs/<Tab>Tab.tsx` (lazy-loaded from `InsightsWizard.tsx`).
7. Add the tab to the wizard shell's tab nav.

Anything that materially changes the surface above — feature flags, REST shape, payload envelopes, cache TTLs, data clients, frontend state contracts — should land in the same PR as a README update.
