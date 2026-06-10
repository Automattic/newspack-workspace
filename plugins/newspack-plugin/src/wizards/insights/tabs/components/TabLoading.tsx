/**
 * TabLoading (NPPD-1684).
 *
 * The shared initial-load frame: a centered spinner with a stable screen-reader
 * "Loading…" label and, when `messages` are supplied, a visible progressive
 * message beneath it that names the backend operation. Mounted only while a tab
 * is loading, so leaving the loading state unmounts it and clears the timers.
 *
 * The visible message is `aria-hidden`: the screen-reader label already conveys
 * "loading", and announcing each swap would be noisy. The progression is a
 * visual signal that work is ongoing, not a status to read aloud.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Waiting } from '../../../../../packages/components/src';
import { useProgressiveMessages, type LoadingMessage } from './useProgressiveMessages';

export interface TabLoadingProps {
	/** Optional progressive copy; omit for a spinner-only frame. */
	messages?: readonly LoadingMessage[];
}

const TabLoading = ( { messages }: TabLoadingProps ) => {
	const message = useProgressiveMessages( messages );

	return (
		<div className="newspack-insights__tab-loading" role="status" aria-live="polite">
			<Waiting />
			<span className="screen-reader-text">{ __( 'Loading…', 'newspack-plugin' ) }</span>
			{ message && (
				<p className="newspack-insights__tab-loading-message" aria-hidden="true">
					{ message }
				</p>
			) }
		</div>
	);
};

export default TabLoading;
