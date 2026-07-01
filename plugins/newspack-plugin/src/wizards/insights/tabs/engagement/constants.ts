/**
 * Engagement tab feature flags.
 */

/**
 * Completion-rate metrics — the "Completion rate" quality card and the
 * "Articles by Completion Rate" table — are derived from GA4 scroll depth
 * (the `scroll` event + percent_scrolled >= 90). The Newspack-owned GA4
 * property feeding the BigQuery source does not collect scroll, so both
 * metrics are structurally 0 until that data flows.
 *
 * TEMPORARY: hide them (card + table) until scroll data lands. Flip this one
 * flag to `true` to restore both. Nothing else is removed — the components,
 * hook fields, orchestrator wiring, and hub builders all stay in place.
 */
export const SHOW_COMPLETION_METRICS: boolean = false;
