/**
 * InfoCallout (NPPD-1618).
 *
 * Shared info callout: a bold heading over free-form body content in a muted
 * box (info icon left, optional dismiss X). Extracted verbatim from the Gates
 * "Direct vs Influenced" callout so the Advertising data-lag note and any future
 * explainer share one treatment.
 *
 * When `dismissible`, dismissal persists per-publisher in localStorage under
 * `storageKey`, so the callout stays hidden across page loads. Non-dismissible
 * callouts (e.g. the Advertising data-lag note, whose content varies with the
 * selected date range) always render.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useCallback, useState } from '@wordpress/element';
import { Icon, closeSmall, info } from '@wordpress/icons';

const STORAGE_PREFIX = 'newspack-insights-callout:';

export interface InfoCalloutProps {
	/** Bold heading shown at the top of the callout. */
	heading: string;
	/** Body content (paragraphs, lists, etc.). */
	children: React.ReactNode;
	/** Show a dismiss (X) button. Requires `storageKey`. */
	dismissible?: boolean;
	/** Namespaces the persisted dismissal flag in localStorage. Required when dismissible. */
	storageKey?: string;
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

const InfoCallout = ( { heading, children, dismissible = false, storageKey }: InfoCalloutProps ) => {
	const [ dismissed, setDismissed ] = useState( () => dismissible && readDismissed( storageKey ) );

	const dismiss = useCallback( () => {
		setDismissed( true );
		if ( storageKey && typeof window !== 'undefined' ) {
			try {
				window.localStorage.setItem( STORAGE_PREFIX + storageKey, '1' );
			} catch ( e ) {
				// Storage unavailable (private mode / quota) — dismissal stays session-only.
			}
		}
	}, [ storageKey ] );

	if ( dismissed ) {
		return null;
	}

	return (
		<div className="newspack-insights__info-callout" role="note">
			<Icon icon={ info } className="newspack-insights__info-callout-icon" />
			<div className="newspack-insights__info-callout-body">
				<p className="newspack-insights__info-callout-title">
					<strong>{ heading }</strong>
				</p>
				{ children }
			</div>
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
