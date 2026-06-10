/**
 * LastUpdated
 *
 * Renders the active tab's cache freshness ("Last updated: …") plus a
 * kebab DropdownMenu with a "Refresh now" item. Reads the active tab's
 * cache slot via insightsCache; fires the registered refresh callback
 * via RefreshRegistry.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { dateI18n } from '@wordpress/date';
import { useSyncExternalStore } from '@wordpress/element';

/**
 * Internal dependencies
 */
import RefreshMenu from './RefreshMenu';
import type { DateRange } from '../state/useDateRange';
import { insightsCache, makeSlotKey, type CacheSlot } from '../state/insightsCache';
import { useInvokeRefresh } from '../state/refreshRegistry';

export interface LastUpdatedProps {
	tab: string | null;
	range: DateRange;
	previousRange: DateRange | null;
}

const idleSlot: CacheSlot = {
	status: 'idle',
	data: null,
	error: null,
	computedAt: null,
	source: null,
	cooldownUntil: null,
	inFlight: null,
};

const LastUpdated = ( { tab, range, previousRange }: LastUpdatedProps ) => {
	const key = tab ? makeSlotKey( tab, range, previousRange ) : null;

	const slot = useSyncExternalStore(
		listener => ( key ? insightsCache.subscribe( key, listener ) : () => {} ),
		() => ( key ? insightsCache.getSlot( key ) : idleSlot )
	);

	const invoke = useInvokeRefresh();

	if ( ! tab || ! slot.computedAt ) {
		return null;
	}

	const cooldownActive = !! slot.cooldownUntil && new Date( slot.cooldownUntil ).getTime() > Date.now();

	return (
		<div className="newspack-insights__last-updated-wrap">
			<span className="newspack-insights__last-updated">
				{ sprintf(
					/* translators: %s is a formatted timestamp */
					__( 'Last updated: %s', 'newspack-plugin' ),
					dateI18n( 'M j, Y H:i:s', slot.computedAt )
				) }
			</span>
			<RefreshMenu onRefresh={ () => invoke( tab ) } disabled={ slot.status === 'loading' || cooldownActive } />
		</div>
	);
};

export default LastUpdated;
