/**
 * TabSpinner (NPPD-1684).
 *
 * The shared insights loading frame: a centered Waiting spinner with a stable
 * screen-reader "Loading…" label. Used by both the inter-tab Suspense fallback
 * (components/TabContent) and the in-tab load state (TabLoading) so the spinner
 * + a11y contract live in one place. `className` selects the wrapper so each
 * caller keeps its own layout; optional children (e.g. a progressive message)
 * render beneath the spinner.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Waiting } from '../../../../../packages/components/src';

export interface TabSpinnerProps {
	/** Wrapper class, e.g. 'newspack-insights__tab-loading' or '…__tab-fallback'. */
	className: string;
	children?: React.ReactNode;
}

const TabSpinner = ( { className, children }: TabSpinnerProps ) => (
	<div className={ className } role="status" aria-live="polite">
		<Waiting />
		<span className="screen-reader-text">{ __( 'Loading…', 'newspack-plugin' ) }</span>
		{ children }
	</div>
);

export default TabSpinner;
