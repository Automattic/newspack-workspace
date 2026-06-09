# Newspack Insights — Project Documentation

Newspack Insights is a native data hub being built into newspack-plugin that replaces publisher reliance on Looker Studio dashboards. It pulls event-level GA4 data from each publisher's own BigQuery (GA4 export), Google Ad Manager data live via the GAM API (Tab 8, over the existing Newspack Google OAuth connection — not BigQuery), and Woo/ESP data from the local WordPress database; it surfaces all three through a wp-admin wizard (Newspack > Insights), and uses a shared data viz component vocabulary (Scorecard, Table, Funnel, LineChart, BoxPlot, PieChart) in `packages/components/src/`. Site isolation is enforced at the GCP project boundary plus a belt-and-suspenders validator stack at the application layer.

## Status

These docs live in the monorepo at `plugins/newspack-plugin/docs/insights/` (migrated from a local scratch repo in NPPD-1615). They are planning/spec documentation for the Insights feature — not end-user documentation.

## Contents

- [architecture.md](architecture.md) — data sources, site isolation, caching strategy, component approach, BQ wrapper question, migration approach
- [information-architecture.md](information-architecture.md) — 8-tab structure, IA principles, v1 cut decision
- [event-reference.md](event-reference.md) — canonical GA4 event spec (events, parameters, global params) with corrections to the public help doc
- [open-questions.md](open-questions.md) — running log of pending decisions
- [formulas/](formulas/) — BigQuery formulas per tab + shared SQL conventions
  - [formulas/README.md](formulas/README.md) — conventions used across all formula files
  - [formulas/tab-4-gates.md](formulas/tab-4-gates.md) — Tab 4 (Gates) formulas
