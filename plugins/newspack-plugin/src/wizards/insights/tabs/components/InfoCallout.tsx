/**
 * InfoCallout (NPPD-1618).
 *
 * Shared info callout: a bold heading over free-form body content in a muted
 * box. The visual chrome — background, padding, dismiss button — comes from
 * the `@wordpress/components` `Notice` component, which natively supports the
 * `isDismissible` + `onRemove` props. This wrapper just wires the persisted
 * dismissal state into those props and renders the heading + children inside.
 *
 * When `dismissible`, dismissal persists per-publisher in localStorage under
 * `storageKey`, so the callout stays hidden across page loads. Pass
 * `persist={ false }` for session-only dismissal — the callout reappears on the
 * next page load (e.g. the Conversion preview banner / cohort-freshness note,
 * which should re-announce each session). Non-dismissible callouts (e.g. the
 * Advertising data-lag note, whose content varies with the selected date range)
 * pass `dismissible={ false }` (the default), which suppresses the X button
 * entirely. `className` appends a variant class to the Notice root, so consumers
 * can re-skin the whole callout (e.g. `--preview`).
 */

/**
 * WordPress dependencies
 */
import { useCallback, useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';

const STORAGE_PREFIX = 'newspack-insights-callout:';

export interface InfoCalloutProps {
	/** Bold heading shown at the top of the callout. */
	heading: string;
	/** Body content (paragraphs, lists, etc.). */
	children: React.ReactNode;
	/** Show a dismiss (X) button. */
	dismissible?: boolean;
	/**
	 * Persist dismissal in localStorage (default `true`). When `false`,
	 * dismissal is session-only and the callout reappears on the next load.
	 */
	persist?: boolean;
	/** Namespaces the persisted dismissal flag in localStorage. Required when dismissible && persist. */
	storageKey?: string;
	/** Extra class appended to the callout root, e.g. a color variant. */
	className?: string;
}

const readDismissed = ( storageKey?: string ): boolean => {
	if ( ! storageKey || typeof window === 'undefined' ) {
		return false;
	}
	try {
		return window.localStorage.getItem( STORAGE_PREFIX + storageKey ) === '1';
	} catch ( e ) {
		return false;
	}
};

const InfoCallout = ( { heading, children, dismissible = false, persist = true, storageKey, className }: InfoCalloutProps ) => {
	const [ dismissed, setDismissed ] = useState( () => dismissible && persist && readDismissed( storageKey ) );

	const dismiss = useCallback( () => {
		setDismissed( true );
		if ( persist && storageKey && typeof window !== 'undefined' ) {
			try {
				window.localStorage.setItem( STORAGE_PREFIX + storageKey, '1' );
			} catch ( e ) {
				// Storage unavailable (private mode / quota) — dismissal stays session-only.
			}
		}
	}, [ persist, storageKey ] );

	if ( dismissed ) {
		return null;
	}

	return (
		<Notice
			status="info"
			isDismissible={ dismissible }
			onRemove={ dismissible ? dismiss : undefined }
			className={ className ? `newspack-insights__info-callout ${ className }` : 'newspack-insights__info-callout' }
		>
			<p className="newspack-insights__info-callout-title">
				<strong>{ heading }</strong>
			</p>
			{ children }
		</Notice>
	);
};

export default InfoCallout;
