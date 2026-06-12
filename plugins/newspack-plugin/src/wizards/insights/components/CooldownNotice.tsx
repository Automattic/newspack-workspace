/**
 * CooldownNotice — MM:SS countdown banner shown when a BigQuery manual
 * refresh hits the 10m cooldown. Auto-clears when the countdown finishes.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useSyncExternalStore } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Notice } from '../../../../packages/components/src';
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

	const remaining = useCountdown( slot.cooldownUntil );

	// Auto-clear the slot's cooldownUntil when the countdown finishes, so the
	// kebab re-enables and stale cooldownUntil doesn't linger. Gate on the
	// deadline having actually passed — otherwise this fires on the first
	// render after cooldownUntil arrives (useCountdown's `remaining` state is
	// still null from its initial null input) and wipes the cooldown before
	// the notice ever gets to render.
	useEffect( () => {
		if ( ! slot.cooldownUntil ) {
			return;
		}
		if ( remaining !== null ) {
			return;
		}
		if ( new Date( slot.cooldownUntil ).getTime() > Date.now() ) {
			return;
		}
		insightsCache.setCooldown( key, null );
	}, [ remaining, slot.cooldownUntil, key ] );

	if ( ! remaining ) {
		return null;
	}

	return (
		<Notice
			isWarning
			className="newspack-insights__cooldown-notice"
			noticeText={ sprintf(
				/* translators: %s is a live mm:ss countdown */
				__( 'Please wait %s before refreshing data again.', 'newspack-plugin' ),
				remaining
			) }
		/>
	);
};

export default CooldownNotice;
