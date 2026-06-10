# Insights local dev notes

## Fixture mode — UI smoke testing without a Google connection

The Audience (Tab 1) and Engagement (Tab 2) tabs render real GA4 data via the
metric orchestrators (NPPD-1648). In a dev environment that can't complete the
Google OAuth flow, use **fixture mode** to render the tabs with realistic
canned data instead of calling GA4.

### Enable

Add to `wp-config.php`:

```php
define( 'NEWSPACK_INSIGHTS_FIXTURE_MODE', true );
```

(Also make sure `NEWSPACK_INSIGHTS_ENABLED` is defined and true so the wizard
is available.)

Then load `/wp-admin/admin.php?page=newspack-insights` and open the **Audience**
and **Engagement** tabs (hard-reload — Cmd+Shift+R — to bust cached JS). They
render with fixture data instead of calling GA4.

### What the fixture exercises

The fixtures deliberately hit every render path so the full visual design can be
verified at once:

- **Realistic values** across scorecards, tables, pie charts, the time-series
  line chart, and the day-of-week / hour-of-day bar charts.
- **`custom_dimension_missing` overlay** — Audience only:
  - Audience: Newsletter Subscriber Rate (and its composition pie)
  - (Engagement's traffic-source card uses the standard `sessionMedium` dimension — no custom-dimension overlay.)
- **`hidden_in_v1` skips** — BQ-only metrics render nothing (no card, no empty
  space): Audience's Returning Reader Rate (strict); Engagement's Top Categories,
  Mobile vs Desktop, Repeat Reader Rate, Article Freshness.
- **Generic `error` state** — one card per tab:
  - Audience: Local Reader Rate (coverage area not configured)
  - Engagement: Top Authors by Avg Engagement Time (transient API error)
- **Comparison deltas in both directions** — the `previous` window differs from
  `current`, so toggling "Compare to previous period" shows green and red deltas.

### Tab-level connect banner

To see the **OAuth-not-connected** state (the full-tab connect banner that
replaces all sections), set `NEWSPACK_INSIGHTS_FIXTURE_MODE` to `false` (or
remove the line) on a site with no Google connection configured. The
orchestrator returns `{ tab_error: 'oauth_not_connected', … }` and the tab
renders the single connect CTA.

### Live data

Set `NEWSPACK_INSIGHTS_FIXTURE_MODE` to `false` (or remove it) and connect
Newspack to a Google account with a GA4 property (Newspack → Connections). The
GA4 path (default) then serves real metrics with no UI change.

### Editing the fixtures

The fixtures live at:

- `plugins/newspack-plugin/includes/wizards/insights/fixtures/audience-fixture.php`
- `plugins/newspack-plugin/includes/wizards/insights/fixtures/engagement-fixture.php`

They return the same `{ current, previous }` shape the live REST controllers
assemble, and compute window/series dates relative to "today" so they never go
stale. Edit them to change what the UI sees during smoke tests.

## Advertising (Tab 8) fixture & render-path toggles

Tab 8 reads Google Ad Manager via the async SOAP ReportService, so its fixture
lives at
`plugins/newspack-plugin/includes/wizards/insights/fixtures/advertising-fixture.php`
and is served by the Advertising REST controller when
`NEWSPACK_INSIGHTS_FIXTURE_MODE` is on. Note the tab only registers when
`NEWSPACK_INSIGHTS_ADVERTISING_ENABLED` is also defined and true.

The fixture returns the live `get_all()` envelope shape (`is_tab_visible`,
`is_report_ready`, `readiness_issues`, `metrics`, `data_as_of`,
`has_estimated_data`, `estimated_window_start_date`, plus `compare` when
comparison mode is on). All dates are computed at runtime.

Exercise each render path with the `_fixture_state` query param on
`/wp-json/newspack-insights/v1/advertising?start=…&end=…&_fixture_state=…`:

- **`populated`** (default) — full scorecards (~2.4M impressions, $4,200
  revenue, ~$1.75 eCPM, 87% fill, 64% viewability), 10 ad units, 10 advertisers,
  and a 60/40 direct/programmatic split. Add `compare_start`/`compare_end` to
  get the comparison payload (mixed +/- deltas).
- **`not_ready`** — `is_report_ready: false` with both `oauth_scope_missing`
  and `network_code_missing` in `readiness_issues`.
- **`zero`** — a zero-impression window: scorecards read 0, tables are empty.
- **`no_viewability`** — the viewability scorecard renders as an
  `overlay: { type: 'data_unavailable' }` (publisher without Active View).
