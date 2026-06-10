/**
 * TabLoading (NPPD-1684).
 *
 * The in-tab initial-load frame: the shared TabSpinner plus, when `messages`
 * are supplied, a visible progressive message that names the backend operation.
 * Mounted only while a tab is loading, so leaving the loading state unmounts it
 * and clears the timers.
 *
 * The visible message is `aria-hidden`: TabSpinner's screen-reader label already
 * conveys "loading", and announcing each swap would be noisy. The progression is
 * a visual signal that work is ongoing, not a status to read aloud.
 */

/**
 * Internal dependencies
 */
import TabSpinner from './TabSpinner';
import { useProgressiveMessages, type LoadingMessage } from './useProgressiveMessages';

export interface TabLoadingProps {
	/** Optional progressive copy; omit for a spinner-only frame. */
	messages?: readonly LoadingMessage[];
}

const TabLoading = ( { messages }: TabLoadingProps ) => {
	const message = useProgressiveMessages( messages );

	return (
		<TabSpinner className="newspack-insights__tab-loading">
			{ message && (
				<p className="newspack-insights__tab-loading-message" aria-hidden="true">
					{ message }
				</p>
			) }
		</TabSpinner>
	);
};

export default TabLoading;
