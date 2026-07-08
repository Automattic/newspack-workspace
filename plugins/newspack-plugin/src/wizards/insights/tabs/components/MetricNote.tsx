/**
 * MetricNote (NPPD-1649, extended for NEWS-2603).
 *
 * Single graceful-failure treatment shared by scorecards, tables, and charts.
 * One look across every variant: a small info glyph + muted sans-serif text
 * (no code-block styling). The custom-dimension parameter name keeps a
 * `<code>` inline, but the surrounding copy and the docs link are plain.
 *
 * Precedence when multiple flags are set: overlay(data_unavailable) →
 * overlay(dimensions) → error → warming → notConfigured → dataMissing →
 * generic. `error` is checked explicitly (rather than falling through to the
 * generic branch) so a hard failure always wins over `warming`.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SETUP_DOCS_URL } from './metrics';

export interface MetricNoteProps {
	/**
	 * Overlay state. GA4's `custom_dimension_missing` carries the missing param
	 * name(s) in `dimensions`; GAM's `data_unavailable` (e.g. viewability without
	 * Active View) carries only a `type` and renders a generic note.
	 */
	overlay?: { type?: string; dimensions?: string[] };
	/** Metric needs configuration (e.g. coverage area not set). */
	notConfigured?: boolean;
	/** Generic data failure. */
	error?: boolean;
	/** A present hub row was missing required column(s) — schema drift / bad deploy. */
	dataMissing?: boolean;
	/** Hub snapshot cache miss being backfilled (NEWS-2603) — transient, not an error. */
	warming?: boolean;
}

const MetricNote = ( { overlay, notConfigured, error, dataMissing, warming }: MetricNoteProps ) => {
	let body: React.ReactNode;

	if ( overlay && overlay.type === 'data_unavailable' ) {
		// Dimension-less overlay (e.g. GAM viewability without Active View enabled).
		body = __( 'Not available for this site.', 'newspack-plugin' );
	} else if ( overlay && overlay.dimensions && overlay.dimensions.length > 0 ) {
		// Missing GA4 custom dimension — name the first missing param.
		const param = overlay.dimensions[ 0 ];
		body = (
			<>
				{ __( 'Custom dimension', 'newspack-plugin' ) } <code>{ param }</code> { __( 'not detected.', 'newspack-plugin' ) }{ ' ' }
				<a href={ SETUP_DOCS_URL } target="_blank" rel="noreferrer">
					{ __( 'See setup docs', 'newspack-plugin' ) }
				</a>
			</>
		);
	} else if ( error ) {
		// Explicit so a hard error always wins over `warming` below, even though
		// both can theoretically be passed together.
		body = __( 'Data temporarily unavailable.', 'newspack-plugin' );
	} else if ( warming ) {
		// Hub snapshot cache miss being backfilled (NEWS-2603): an honest "still
		// calculating" note rather than a bare em-dash or the generic failure copy.
		body = __( 'Still calculating — check back shortly.', 'newspack-plugin' );
	} else if ( notConfigured ) {
		body = __( 'Not configured for this site.', 'newspack-plugin' );
	} else if ( dataMissing ) {
		body = __( 'Some data could not be loaded.', 'newspack-plugin' );
	} else {
		body = __( 'Data temporarily unavailable.', 'newspack-plugin' );
	}

	return (
		<p className="newspack-insights__metric-note">
			<span className="newspack-insights__metric-note-icon" aria-hidden="true">
				&#9432;
			</span>
			<span>{ body }</span>
		</p>
	);
};

export default MetricNote;
