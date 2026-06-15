# Newspack Insights

Native data hub for Newspack publishers. Surfaces audience, engagement, conversion, gates, prompts, subscribers, donors, and advertising data inside a single `wp-admin` wizard, replacing publisher reliance on Looker Studio dashboards.

This README documents what is actually implemented today. Keep it in sync as the code changes — there is no separate spec doc.

## Feature flags

All gated by PHP constants. The wizard registers nothing when `NEWSPACK_INSIGHTS_ENABLED` is off.

| Constant | Effect |
| --- | --- |
| `NEWSPACK_INSIGHTS_ENABLED` | Master switch. When off, no admin page, no REST routes, no asset enqueue. |
| `NEWSPACK_INSIGHTS_GATES_PREVIEW` | Shows the Gates (Tab 4) nav entry and activates its REST route. Independent of the main flag so the Phase 1 preview can ship to a subset of environments. |
| `NEWSPACK_INSIGHTS_ADVERTISING_ENABLED` | Shows the Advertising (Tab 8) nav entry and activates its GAM-backed orchestrator. |
| `NEWSPACK_INSIGHTS_FIXTURE_MODE` | REST controllers short-circuit to deterministic fixtures instead of calling live data clients. Used for UI smoke testing. |
| `NEWSPACK_INSIGHTS_CACHE_DISABLED` | Bypass the server-side transient cache entirely. Dev/debug only. |

See [`class-insights-wizard.php`](class-insights-wizard.php) for the canonical definitions and tab-visibility rules.

## Layout

```
includes/wizards/insights/
├── class-insights-wizard.php       Admin page registration, boot config, tab visibility
├── class-insights-section-*.php    Per-tab init: loads metric + REST controller, registers route
├── class-cache.php                 Shared transient wrapper with per-source TTLs + BQ cooldown
├── class-bigquery-proxy-client.php Hub-proxied BigQuery queries (Newspack Manager auth)
├── class-woo-order-resolver.php    Joins BQ paywall attempts to local Woo orders (conversion paths)
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

One per tab. Each section's `init()` (called by [`class-insights-wizard.php`](class-insights-wizard.php) when the feature flag is on) does three things:

1. Loads the tab's `metrics/class-*-metric.php` and `api/class-*-rest-controller.php` files.
2. Registers the REST route on the `rest_api_init` hook.
3. (Subscribers only) Wires up `Donation_Product_Classifier` cache-invalidation hooks for donation-product meta and option changes.

Sections hold no state — they're thin route-registration shims.

### Metric orchestrators (`metrics/class-*-metric.php`)

The unit of work per tab. Each metric class exposes:

- `get_all( $start, $end, $compare = null )` — full tab payload (current window plus optional comparison window).
- `connection_error()` — early gate check returning `{ tab_error, banner_text }` when preconditions (OAuth, GAM activation, etc.) fail. Returns `null` when ready.
- `get_fixture( ... )` — deterministic mock payload used by `FIXTURE_MODE`.

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
| Engagement (2) | GA4 Data API | Same dispatch pattern as Audience. |
| Conversion (3) | BigQuery | Fixtures only until NPPD-1630 lands. |
| Gates (4) | BigQuery | Preview-flag gated. |
| Prompts (5) | BigQuery | Conversion funnels. |
| Subscribers (6) | Local Woo | Reads via [`storage/`](storage/). |
| Donors (7) | Local Woo | Donation-scoped queries via the donors storage interface. |
| Advertising (8) | GAM async SOAP | Polls async report jobs; gated by `NEWSPACK_INSIGHTS_ADVERTISING_ENABLED` and a runtime "GAM active" check. |

### Data clients

- **[`class-bigquery-proxy-client.php`](class-bigquery-proxy-client.php)** — `wp_remote_post` to the hub's `/wp-json/newspack-manager-admin/v1/bigquery-query` endpoint. Auth via Newspack Manager admin signing. Date inputs normalized to UTC `Ymd` (GA4 daily-shard format). Returns `WP_Error` on every failure path; logs to Logstash with `NEWSPACK-INSIGHTS-BIGQUERY` header.
- **[`ga4/class-client.php`](ga4/class-client.php)** — `runReport` primitives. Reuses Newspack's existing Google OAuth (`analytics` scope already granted; no re-auth). Pre-flight check inspects `customEvent:<param>` references against `GA4_Custom_Dimensions::get_registered_parameter_names()` and returns a `custom_dimension_missing` `WP_Error` when the property doesn't have the dimension registered. Per-request memo cache on registered-dimension lookups.
- **[`gam/class-client.php`](gam/class-client.php)** — Async SOAP via the vendored `googleads-php-lib` (`NEWSPACK_ADS_COMPOSER_ABSPATH`). OAuth-only (no service-account fallback — service accounts are an OSS path and don't apply to managed customers). `is_gam_active()` reads the Ad Providers option; `can_run_reports()` does a one-shot tokeninfo + network-code check (don't call on every poll).
- **[`class-woo-order-resolver.php`](class-woo-order-resolver.php)** — Joins BQ paywall-attempt rows (`uid`/`user_pseudo_id`, `session_id`, `attempt_ts`) against `wp_wc_orders` to identify completed orders inside a configurable window (default 30 min). Anonymous attempts (non-numeric pseudo-IDs) are silently dropped. Instance-level cache to avoid re-running customer queries when stacking count/sum/unique-users on the same row set.

### REST API (`api/`)

Namespace: `newspack-insights/v1`. Each tab has:

- `GET  /newspack-insights/v1/<tab>` — initial fetch.
- `POST /newspack-insights/v1/<tab>/refresh` — manual cache invalidation. Always returns 200; `cooldown_until` in the envelope signals throttle to the client.

Shared shape:

```json
{ "cache": { "source", "computed_at", "cooldown_until" }, "data": { ... } }
```

`Cached_Controller_Trait` ([`trait-cached-controller.php`](api/trait-cached-controller.php)) wraps GET/POST in cache orchestration. Concrete controllers declare `cache_source()` (one of `SOURCE_EXTERNAL`, `SOURCE_BIGQUERY`, `SOURCE_LOCAL`) and `tab_slug()`. All responses set `Cache-Control: no-store, private` so the browser never caches over the server-side transient.

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

## Testing

Jest, colocated `*.test.ts(x)` next to the source. Key spots:

- `state/insightsCache.test.ts` covers slot dedupe and refresh semantics.
- Per-tab component tests verify rendering against representative payloads (loading, error, partial data, overlays, comparison on/off).
- PHP unit tests live in `plugins/newspack-plugin/tests/unit-tests/insights*` and `tests/unit-tests/insights/`.

`NEWSPACK_INSIGHTS_FIXTURE_MODE` is the recommended path for manual UI smoke testing without a live GA4 or GAM connection.

## Adding a new tab

1. Add a `class-insights-section-<tab>.php` that loads the metric + controller and registers the route on `rest_api_init`.
2. Add `metrics/class-<tab>-metric.php` implementing `get_all`, `connection_error`, `get_fixture`.
3. Add `api/class-<tab>-rest-controller.php` using `Cached_Controller_Trait`, declaring `cache_source()` and `tab_slug()`.
4. Add `fixtures/<tab>-fixture.php` returning a representative payload.
5. Wire the section into `class-insights-wizard.php` (tab visibility map + section init).
6. Frontend: add `hooks/use<Tab>Data.ts`, `api/<tab>.ts`, and `tabs/<Tab>Tab.tsx` (lazy-loaded from `InsightsWizard.tsx`).
7. Add the tab key to the visibility map and tab nav in the wizard shell.

Anything that materially changes the surface above — feature flags, REST shape, payload envelopes, cache TTLs, data clients, frontend state contracts — should land in the same PR as a README update.
