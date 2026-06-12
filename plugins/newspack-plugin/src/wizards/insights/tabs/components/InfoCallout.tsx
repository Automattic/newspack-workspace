/**
 * InfoCallout (NPPD-1618).
 *
 * Shared info callout: a bold heading over free-form body content in a muted
 * box (info icon left, optional dismiss X). The visual chrome — gray
 * background, info icon, padding, content layout — is provided by the Newspack
 * design-system `Notice` component; this wrapper owns the layout container
 * (so a variant `className` can re-skin the whole callout) and the optional
 * dismiss affordance (`Notice` itself has no dismiss button).
 *
 * Trade-off: the previous hand-rolled markup set `role="note"` on the root.
 * `Notice` renders a plain `<div>` with no role, so that semantic hint is
 * lost. Accepted in exchange for visual consistency with every other notice
 * in the Newspack admin.
 *
 * When `dismissible`, dismissal persists per-publisher in localStorage under
 * `storageKey`, so the callout stays hidden across page loads. Pass
 * `persist={ false }` for session-only dismissal — the callout reappears on the
 * next page load (e.g. the Conversion preview banner / cohort-freshness note,
 * which should re-announce each session). Non-dismissible callouts (e.g. the
 * Advertising data-lag note, whose content varies with the selected date range)
 * always render. `className` appends a variant class to the root (e.g. a
 * different background) while keeping the shared markup and dismiss affordance.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useCallback, useState } from '@wordpress/element';
import { Icon, closeSmall } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Notice } from '../../../../../packages/components/src';

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
		<div className={ className ? `newspack-insights__info-callout ${ className }` : 'newspack-insights__info-callout' }>
			<Notice
				noticeText={
					<>
						<p className="newspack-insights__info-callout-title">
							<strong>{ heading }</strong>
						</p>
						{ children }
					</>
				}
			/>
			{ dismissible && (
				<button
					type="button"
					className="newspack-insights__info-callout-dismiss"
					onClick={ dismiss }
					aria-label={ __( 'Dismiss', 'newspack-plugin' ) }
				>
					<Icon icon={ closeSmall } />
				</button>
			) }
		</div>
	);
};

export default InfoCallout;
