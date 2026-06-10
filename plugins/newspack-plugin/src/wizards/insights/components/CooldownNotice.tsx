/**
 * CooldownNotice — dismissible MM:SS countdown banner shown when a
 * BigQuery manual refresh hits the 10m cooldown.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import { useEffect, useState, useSyncExternalStore } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import { insightsCache, makeSlotKey } from '../state/insightsCache';
import useCountdown from '../hooks/useCountdown';

export interface CooldownNoticeProps {
	tab: string;
	range: DateRange;
	previousRange: DateRange | null;
}

const CooldownNotice = ( { tab, range, previousRange }: CooldownNoticeProps ) => {
	const key = makeSlotKey( tab, range, previousRange );

	const slot = useSyncExternalStore(
		listener => insightsCache.subscribe( key, listener ),
		() => insightsCache.getSlot( key )
	);

	const [ dismissedFor, setDismissedFor ] = useState< string | null >( null );
	const remaining = useCountdown( slot.cooldownUntil );

	useEffect( () => {
		if ( slot.cooldownUntil && dismissedFor !== slot.cooldownUntil ) {
			// New cooldown landed — reset the dismiss state so the notice reopens.
			setDismissedFor( null );
		}
	}, [ slot.cooldownUntil, dismissedFor ] );

	// Auto-clear the slot's cooldownUntil when the countdown finishes,
	// so the kebab re-enables and stale cooldownUntil doesn't linger.
	useEffect( () => {
		if ( slot.cooldownUntil && remaining === null ) {
			insightsCache.setCooldown( key, null );
		}
	}, [ remaining, slot.cooldownUntil, key ] );

	if ( ! remaining ) {
		return null;
	}
	if ( dismissedFor === slot.cooldownUntil ) {
		return null;
	}

	return (
		<Notice
			status="warning"
			isDismissible
			onRemove={ () => setDismissedFor( slot.cooldownUntil ) }
			className="newspack-insights__cooldown-notice"
		>
			{ sprintf(
				/* translators: %s is a live mm:ss countdown */
				__( 'Please wait %s before refreshing data again.', 'newspack-plugin' ),
				remaining
			) }
		</Notice>
	);
};

export default CooldownNotice;
