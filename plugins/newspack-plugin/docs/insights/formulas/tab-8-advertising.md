# Tab 8: Advertising — Formulas

Reference: see `formulas/README.md` for conventions. See `../event-reference.md` for GA4 event params. **This tab does NOT use GA4 or BigQuery.** It reads Google Ad Manager (GAM) data live via the GAM API, authenticated through Newspack's existing Google OAuth connection — the same connection that powers GA4 (Tabs 1–2).

## Scope

Tab 8 answers the publisher's ad-revenue questions: how much revenue is the ad stack generating, where's it coming from, and how is it performing? v2 cut tab (last priority for v1; ships once the GAM ReportService integration is well-understood).

GAM is GOOGLE AD MANAGER — distinct from GA4. But — unlike GA4, which we read from BigQuery — GAM data is read **live from the GAM API** (`ReportService`), reusing the GAM connection that `newspack-ads` already maintains. No BigQuery, no Data Transfer Reports, no per-publisher dataset provisioning.

## Connection model — READ THIS FIRST

This tab was originally scoped against GAM Data Transfer Reports exported to BigQuery. **That approach is abandoned.** It required per-publisher opt-in BQ export that most Newspack publishers don't have, plus its own dataset/IAM provisioning. Instead:

1. **One OAuth connection covers both GA4 and GAM.** Newspack's Google OAuth (`Newspack\Google_OAuth`, brokered through the Newspack OAuth proxy) requests the Ad Manager scope as a *required* scope alongside the analytics scopes:

   ```
   https://www.googleapis.com/auth/admanager   // GAM
   https://www.googleapis.com/auth/analytics    // GA4
   ```

   See `newspack-plugin/includes/oauth/class-google-oauth.php` → `REQUIRED_SCOPES`. Any publisher who has completed the Newspack Google connection (for GA4) has *already* granted GAM access. There is nothing extra for the publisher to connect.

2. **OAuth only — NOT service account.** `newspack-ads` supports two GAM auth modes: a service-account JSON upload (open-source / self-hosted users) and OAuth2 (managed Newspack customers). **Insights uses OAuth exclusively.** Service-account credentials must NOT be used to power this tab — they don't apply to managed customers. Concretely, do not call `GAM_Model::get_api()` blindly: that helper *prefers* the service account when one is present. Insights must take the OAuth credentials path explicitly:

   ```php
   // Gate: confirm the saved Google token actually carries the GAM scope.
   \Newspack\Google_OAuth::token_has_scope( 'https://www.googleapis.com/auth/admanager' );

   // Credentials for the GAM API session (same connection as GA4):
   \Newspack\Google_Services_Connection::get_oauth2_credentials();
   ```

3. **Network code comes from `newspack-ads`.** The active GAM network code is already resolved and stored there: `Newspack_Ads\Providers\GAM_Model::get_active_network_code()` (option `_newspack_ads_gam_network_code`). Insights does not ask the publisher for it again.

4. **Reporting code lives in the Insights module — NOT in `newspack-ads`.** All GAM reporting code is grouped with the rest of the Insights-specific code in `newspack-plugin/includes/wizards/insights/` (alongside `class-insights-section-advertising.php` and the `api/` helpers). Insights barely touches `newspack-ads`:
   - **OAuth credentials** come from newspack-plugin's own `Newspack\Google_OAuth` / `Google_Services_Connection` — same plugin, no `newspack-ads` needed for auth.
   - **Network code** is the *only* value read from `newspack-ads` (option `_newspack_ads_gam_network_code`, via `GAM_Model::get_active_network_code()` when active, or a direct option read).

   No reporting code exists yet *anywhere* — `newspack-ads` only does trafficking/metadata (ad units, line items, creatives, targeting); it has no `ReportService` wrapper. Tab 8 implements its own, in the Insights module.

   **API-surface sub-decision — RESOLVED: SOAP via `newspack-ads`' vendored library (NPPD-1662 pre-flight).** The reporting code lives in the Insights module but calls GAM's SOAP `ReportService` through the `googleads/googleads-php-lib` library that `newspack-ads` already vendors (**API version `v202602`**; lib bumped from `^71.0` to `^72.0` so `v202602` is available while `v202511` — used by newspack-ads trafficking — is retained).

   This reverses the earlier "thin REST wrapper" recommendation. Pre-flight 1 (verify the REST reporting surface) found that REST reporting exists only in the **Ad Manager API (Beta)** (`admanager.googleapis.com/v1`), which is a structurally different API: `networks.reports.create` → `:run` → Operation polling → `fetchRows` returning paginated JSON (no gzip-CSV job model), with a different `metrics`/`dimensions` enum vocabulary. Choosing it would mean a Beta-stability bet and rewriting every metric definition in this doc. The SOAP `ReportService` (`runReportJob` → `getReportJobStatus` → `getReportDownloadUrlWithOptions` → gzip CSV) matches the metric definitions below exactly and is production-stable.

   Trade-offs accepted:
   - **+** Matches this doc's existing SOAP semantics and enum names; production-stable; no new composer dep in newspack-plugin (reuses newspack-ads' vendored lib).
   - **−** A bounded cross-plugin **library** dependency on `newspack-ads` (its vendored SOAP lib + autoloader). This is acceptable because Tab 8 already requires `newspack-ads` active for the network code, so the coupling exists regardless. It is a *library* dependency only — the reporting *code* still lives in the Insights module.

   REST migration is deferred to a v1.1+ concern, tracked in **NPPD-1664** (revisit when the Ad Manager REST API goes GA).

## CRITICAL caveats before any of this works

1. **Tab visibility = GAM active on the site.** Tab 8 shows iff Google Ad Manager is active as an ad provider (newspack-ads' GAM provider `is_active()` — the "Ad Providers" settings toggle). Reporting *readiness* (OAuth `admanager` scope + a configured network code) is a separate, stricter check (`can_run_reports()`) evaluated inside the tab: when GAM is active but reporting isn't ready, the tab shows a "finish connecting" diagnostic rather than hiding. (This replaces the old "BQ export opt-in" detection — far more publishers qualify now.)

2. **GAM report column/dimension enum verification is pending.** The report queries below are written against the documented `ReportService` `Column` and `Dimension` enums for API `v202602`. Exact enum names drift across API versions and account configurations. Verify against a real publisher's network before implementation. Fields most likely to need verification are noted inline.

3. **GAM reporting is asynchronous — never run it inline on a page load.** `ReportService` is a job API: `runReportJob()` → poll `getReportJobStatus()` until `COMPLETED` → `getReportDownloadUrlWithOptions()` → fetch and parse the CSV/gzip. A report can take seconds to minutes. Tab 8 must run reports on the pre-warm cadence (see `../architecture.md` caching) and serve cached results to the admin UI. Treat each scorecard/section as a cached report result, not a synchronous query.

4. **GAM data lag.** GAM figures for the most recent ~7 days are *estimated* and shift as AdX clears. UI should show a "Data as of [date]" timestamp and footnote that recent revenue may revise.

## Tab visibility

**Visibility is based on whether Google Ad Manager is active on the site** — i.e. GAM is enabled as an ad provider (the Newspack "Ad Providers" settings toggle). This mirrors newspack-ads' own GAM provider `is_active()` signal. It deliberately does NOT require the OAuth scope or a network code, so the tab still appears when GAM is enabled but reporting isn't fully wired up yet — in that case the tab renders an in-tab "finish connecting" diagnostic rather than hiding.

```php
// Tab visibility — GAM active on the site.
\Newspack\Insights\GAM\Client::is_gam_active();

// Reporting readiness — checked inside the tab to decide data vs. diagnostic.
\Newspack\Insights\GAM\Client::can_run_reports(); // is_gam_active() + admanager scope + network code
```

`is_gam_active()` reads `_newspack_advertising_service_google_ad_manager` (guarded by newspack-ads being active). `can_run_reports()` is the stricter precondition the client enforces before submitting a report job (and that the orchestrator, NPPD-1663, uses to choose between showing data and showing a connect prompt).

## Conventions specific to this tab

- **Report shape:** every metric below is a GAM `ReportQuery` = a set of **Dimensions** (the GROUP BY), a set of **Columns** (the measures), an optional **PQL filter statement** (the WHERE), and a date range. Think of Dimensions/Columns as GAM's equivalent of SQL `GROUP BY` / `SUM()` columns. We run the job, download the CSV, and the wrapper layer normalizes it.
- **Date range:** use `dateRangeType = CUSTOM_DATE` with explicit `startDate` / `endDate` matching the publisher's selected window. Dates are interpreted in the **GAM network's** time zone (see open item on timezone).
- **Currency:** GAM revenue columns are returned in **micro-currency units** (e.g., $1.50 = 1,500,000). Divide by 1,000,000 for display. Normalize once in the wrapper layer; the formulas below describe pre-normalized values.
- **Impression columns:** GAM distinguishes total impressions (reach) from ad-server-coded impressions (used for revenue/eCPM math). Use the total column for reach metrics, the coded/ad-server column for revenue calculations. Exact column names verified inline.
- **Line item type:** the `LINE_ITEM_TYPE` dimension categorizes inventory by sales channel — maps to the direct vs programmatic split. Specific enum values verified inline per metric.

## Section: Headline scorecards

Each scorecard is a single-row report (no dimensions, window-wide columns) unless noted.

### Total Impressions (in window)

- **Dimensions:** none
- **Columns:** `TOTAL_IMPRESSIONS` (gross impressions across all inventory; programmatic + direct)
- **Filter:** none
- **Window:** `CUSTOM_DATE` start/end

Notes:
- If `TOTAL_IMPRESSIONS` is unavailable in the account's report schema, sum `AD_SERVER_IMPRESSIONS` + `AD_EXCHANGE_IMPRESSIONS`. Verify against the network.

### Total Revenue (in window)

- **Dimensions:** none
- **Columns:** `TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE` (micros — divide by 1M)
- **Filter:** none

Notes:
- This is GAM's estimated all-revenue figure. "Estimated" because final reconciled revenue (after AdX clearing) may differ slightly — typically < 5% for most publishers.
- If line-item-level revenue isn't reported, fall back to `AD_SERVER_CPM_AND_CPC_REVENUE` + `AD_EXCHANGE_TOTAL_EARNINGS`. Verify.

### Fill Rate

- **Dimensions:** none
- **Columns:** `TOTAL_CODE_SERVED_COUNT` (or coded impressions) and `TOTAL_AD_REQUESTS`; derive `fill_rate = coded / requests` in the wrapper
- **Filter:** none

Notes:
- Fill rate = filled requests / total requests. Industry typical: 60–85% depending on inventory quality.
- GAM may expose a direct `AD_SERVER_UNFILLED_IMPRESSIONS` / `TOTAL_UNMATCHED_AD_REQUESTS` pair instead — verify which the account reports and derive accordingly.

### Average eCPM

- **Dimensions:** none
- **Columns:** `TOTAL_LINE_ITEM_LEVEL_AVERAGE_ECPM` (micros — divide by 1M)
- **Filter:** none

Notes:
- eCPM = effective cost per mille (per 1,000 impressions). If GAM doesn't return an average-eCPM column for the chosen revenue basis, derive: `(revenue / coded_impressions) * 1000`.
- Industry baseline varies wildly: $1–3 for low-quality programmatic, $10–30 for premium direct.

### Click-Through Rate

- **Dimensions:** none
- **Columns:** `TOTAL_LINE_ITEM_LEVEL_CLICKS` and the coded-impressions column; derive `ctr = clicks / coded_impressions` (or use `AD_SERVER_CTR` directly)
- **Filter:** none

Notes:
- Industry typical news publisher CTR: 0.1–0.5%. Higher (1%+) often indicates bot traffic or accidental click placement.

### Viewability Rate

- **Dimensions:** none
- **Columns:** `TOTAL_ACTIVE_VIEW_VIEWABLE_IMPRESSIONS` and `TOTAL_ACTIVE_VIEW_MEASURABLE_IMPRESSIONS`; derive `viewability = viewable / measurable` (or use `TOTAL_ACTIVE_VIEW_VIEWABLE_IMPRESSIONS_RATE` directly)
- **Filter:** none

Notes:
- Active View is GAM's viewability measurement: ≥50% of pixels in view for ≥1 continuous second.
- Active View columns may be empty if the network doesn't have Active View enabled. Hide this scorecard gracefully when measurable impressions are zero/absent.

## Section: Revenue trends

### Revenue Over Time (LineChart, daily)

- **Dimensions:** `DATE`
- **Columns:** `TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE` (micros — divide by 1M)
- **Filter:** none
- **Order:** by `DATE` ascending (sort at display time if the API doesn't order)

Notes:
- The `DATE` dimension yields one row per day in the window — the report's native daily granularity.
- For windows > 90 days, aggregate to weekly at display time.

### Impressions Over Time (LineChart, daily)

Same as Revenue Over Time but with column `TOTAL_IMPRESSIONS`.

### eCPM Over Time (LineChart, daily)

- **Dimensions:** `DATE`
- **Columns:** revenue + coded impressions; derive daily eCPM = `(revenue / coded_impressions) * 1000` per row (or `TOTAL_LINE_ITEM_LEVEL_AVERAGE_ECPM` if the account reports it per-date)

Notes:
- Daily eCPM exposes weekday vs weekend pricing patterns, holiday revenue dips, etc. Useful for spotting anomalies.

## Section: Revenue mix

### Direct vs Programmatic Split (PieChart)

The `LINE_ITEM_TYPE` dimension categorizes inventory by sales channel.

- **Dimensions:** `LINE_ITEM_TYPE`
- **Columns:** `TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE` (micros), `TOTAL_IMPRESSIONS`
- **Filter:** none
- **Post-processing (wrapper):** bucket the `LINE_ITEM_TYPE` value:
  - `SPONSORSHIP`, `STANDARD`, `BULK`, `PRICE_PRIORITY` → **direct** (publisher's ad-ops team sold these)
  - `HOUSE` → **house** (publisher's own promotions)
  - `NETWORK`, `AD_EXCHANGE` → **programmatic** (auction-based)
  - anything else → **other**

Notes:
- The bucketing follows GAM's standard taxonomy. Verify exact enum values against the network — GAM has shipped renames historically (e.g., `AD_EXCHANGE` → `NETWORK` in some accounts).
- "House" ads typically have zero revenue but real impressions. Surface separately rather than rolling into "direct," so publishers don't conflate newsletter promo with paid sponsorship.
- Programmatic-only publishers will effectively see one slice — that's fine, it's the answer.

### Revenue by Ad Format (PieChart)

The `CREATIVE_SIZE` dimension encodes ad format dimensions.

- **Dimensions:** `CREATIVE_SIZE`
- **Columns:** `TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE` (micros), `TOTAL_IMPRESSIONS`, coded impressions (for eCPM)
- **Filter:** none
- **Post-processing (wrapper):** group `CREATIVE_SIZE` into format families and compute eCPM per family:
  - `728x90` → leaderboard
  - `300x250`, `336x280` → medium_rectangle
  - `300x600`, `160x600`, `120x600` → skyscraper
  - `970x250`, `970x90` → billboard
  - sizes containing `v` (video) or null → video_or_other
  - else → other

Notes:
- Format grouping is editorial — adjust to the publisher's actual inventory. Some want individual sizes, some want families.
- Video sizing in GAM is inconsistent across versions; the catch-all is a v1 approximation.

## Section: Performance by inventory

### Top Ad Units by Revenue (Table)

- **Dimensions:** `AD_UNIT_NAME`
- **Columns:** `TOTAL_IMPRESSIONS`, `TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE` (micros), coded impressions (for eCPM), `TOTAL_LINE_ITEM_LEVEL_CLICKS` (for CTR)
- **Filter:** none
- **Order/limit:** sort by revenue desc, top 25 (at display time if needed)

Notes:
- `AD_UNIT_NAME` is GAM's human-readable identifier (e.g., "ATF_Leaderboard", "BTF_Rectangle"). Some networks prefer `AD_UNIT_ID` (numeric) — verify and map to name as needed.
- Useful for surfacing underperforming inventory ("our BTF Rectangle has 50K impressions but only $12 revenue — that placement is broken").

### Top Advertisers by Revenue (Table)

- **Dimensions:** `ADVERTISER_NAME`
- **Columns:** `TOTAL_IMPRESSIONS`, `TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE` (micros)
- **Filter (PQL):** restrict to direct-sold line item types, e.g.
  `WHERE LINE_ITEM_TYPE IN ('SPONSORSHIP','STANDARD','BULK','PRICE_PRIORITY')`
- **Order/limit:** sort by revenue desc, top 25

Notes:
- Filter to direct-sold types. Programmatic doesn't have meaningful advertiser data at the publisher level — auction winners are obscured.
- Programmatic-only publishers will see an empty table; degrade gracefully ("No direct-sold inventory in this window").
- If `LINE_ITEM_TYPE` can't be used in a PQL filter for this report type, filter the downloaded rows in the wrapper instead.

## Section: Performance breakdowns

### Performance by Device (Table)

The `DEVICE_CATEGORY_NAME` dimension. Values typically: `Desktop`, `Smartphone`, `Tablet`, `Other`.

- **Dimensions:** `DEVICE_CATEGORY_NAME`
- **Columns:** `TOTAL_IMPRESSIONS`, `TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE` (micros), coded impressions (eCPM), `TOTAL_LINE_ITEM_LEVEL_CLICKS` (CTR)
- **Filter:** none
- **Order:** by revenue desc

Notes:
- Mobile typically dominates impressions but has lower eCPM than desktop. The two-axis view (impressions vs eCPM) tells the publisher where revenue concentrates vs where audience is.

### Top Countries by Revenue (Table)

The `COUNTRY_NAME` dimension.

- **Dimensions:** `COUNTRY_NAME`
- **Columns:** `TOTAL_IMPRESSIONS`, `TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE` (micros), coded impressions (eCPM)
- **Filter:** none
- **Order/limit:** sort by revenue desc, top 25

Notes:
- Most US-focused publishers see US revenue dominating; international impressions often have very low eCPM. Surface for publishers monitoring international growth.

## Section: Performance by content category — DEFERRED TO v1.1

Requires cross-referencing GAM impressions with GA4 article reads (to get content category for each impression). This is a non-trivial cross-source join: GAM reports have URL but not category; GA4 page_views have category but not GAM impression data. Note the asymmetry now that GAM is API-based and GA4 is BigQuery-based — this is a join *across two different systems*, not two BQ datasets.

v1.1 sketch:
- Pull a GAM report dimensioned by URL (e.g., `URL` / `CONTENT_NAME`) with revenue + impressions, for the window.
- For each URL, look up the GA4 `categories` event param from the BQ GA4 export.
- Join in PHP (or stage GAM rows into a temp structure) and aggregate GAM revenue/impressions by category.

The cross-system join is complex and the per-URL GAM report can be large. Defer until v1 ships and validate publisher demand.

## Open items specific to Tab 8

1. **GAM report enum verification is required before implementation.** All Dimensions/Columns above are written against the documented `ReportService` schema for `v202602`, but exact enum names vary across API versions and account configurations. Action: at the first publisher with GAM data, run a small introspection report and adjust. Worth a documentation update post-verification.

2. **Direct vs programmatic `LINE_ITEM_TYPE` enum values may vary.** GAM has shipped renames historically (`AD_EXCHANGE` → `NETWORK` in some accounts). The bucketing list may need adjustment per network.

3. **No GAM reporting code exists yet — and it lives in the Insights module, not `newspack-ads`.** A reporting runner (run report → poll → download → parse) must be written in `newspack-plugin/includes/wizards/insights/`, grouped with the other Insights code. See the API-surface sub-decision in the Connection model section (our own thin REST wrapper — recommended — vs. reusing `newspack-ads`' SOAP client). Scope this as part of Tab 8 implementation.

4. **OAuth-only enforcement.** Insights must never authenticate GAM via service-account credentials (those are open-source/self-hosted only). Use `Google_Services_Connection::get_oauth2_credentials()` and gate on `Google_OAuth::token_has_scope('.../admanager')`. Do not call `GAM_Model::get_api()` if it would prefer a present service account.

5. **No header bidding support in v1.** Per scope decision. Future v1.x may add a Prebid section for publishers using header bidding partners (note: `newspack-ads` gates bidding behind `NEWSPACK_ADS_EXPERIMENTAL_BIDDERS`).

6. **Content category breakdown deferred to v1.1.** Per scope decision. Cross-system (GAM API × GA4 BQ) join complexity warrants validating publisher demand first.

7. **Currency handling.** GAM revenue columns are in micros (1,000,000 = $1.00). The wrapper normalizes once; UI displays standard currency. Multi-currency publishers (rare) need their primary currency setting respected.

8. **Active View dependency for viewability.** Some networks don't have Active View enabled. Detect (measurable impressions absent/zero) and hide the viewability scorecard gracefully.

9. **Network code is publisher-specific** and already resolved by `newspack-ads` (`GAM_Model::get_active_network_code()`). Insights reuses it — no separate provisioning. Accounts with multiple network codes store them comma-delimited; resolve the active one.

10. **Time zone in GAM data.** GAM report dates are in the GAM network's configured time zone, NOT UTC, and the publisher configures it separately from their WP timezone. Insights may need a `gam_timezone` setting (or read it from `NetworkService`) to align report date windows with the publisher's expected window.

11. **Estimated vs final revenue reconciliation.** GAM data is "estimated" for the most recent ~7 days; numbers shift as AdX clears. UI should show a "data lag" indicator for the recent window and footnote that revenue may revise.

12. **Programmatic-only vs direct-sold publishers behave differently.** Some sections (Top Advertisers, Direct vs Programmatic split) are meaningless for programmatic-only publishers. Detect the publisher's mix at boot and hide irrelevant sections.

13. **Async reporting + caching.** Report jobs are slow and rate-limited; they cannot run per page load. Run on the pre-warm cadence and serve cached results. Consider GAM API quota limits when sizing the number of distinct reports per refresh.

## Cross-references

- Connection / OAuth: `newspack-plugin/includes/oauth/class-google-oauth.php` (`REQUIRED_SCOPES`, `token_has_scope`), `class-google-services-connection.php` (`get_oauth2_credentials`)
- GAM network code: `newspack-ads/includes/providers/gam/class-gam-model.php` → `get_active_network_code()`
- GAM SOAP client + vendored `googleads/googleads-php-lib` (v202602, lib `^72.0`): `newspack-ads/includes/providers/gam/api/class-api.php` (session-build pattern to mirror), `newspack-ads/vendor/googleads/googleads-php-lib/`
- API-surface decision rationale: NPPD-1662 pre-flight findings; REST migration tracked in NPPD-1664
- Insights module (where Tab 8 + the reporting runner live): `newspack-plugin/includes/wizards/insights/`
- Event reference (GA4): `../event-reference.md` — note: GAM is a separate, API-based data source not documented there
- Architecture: `../architecture.md`
- Open questions: `../open-questions.md` (question #8 — resolved: OAuth + GAM API, no BQ)
- Credentials/OAuth provisioning: NPPD-1600 (GA4-focused; GAM now rides the same OAuth connection)
- Tab 1 (Audience Overview) for the audience side of the equation: `./tab-1-audience.md`
