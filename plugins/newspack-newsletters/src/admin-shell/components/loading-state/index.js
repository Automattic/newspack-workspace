/**
 * Reusable loading state for admin-shell screens.
 *
 * For the first paint only, where there is no list behind it and so no
 * `aria-busy` either — hence the label and the live region. Once a list
 * has rendered, DataViews' own loading treatment takes over.
 */

import { speak } from '@wordpress/a11y';
import { Spinner, __experimentalText as Text, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { useEffect } from '@wordpress/element';

/**
 * @param {Object} props
 * @param {string} props.label What is being fetched, e.g. "Fetching newsletters…".
 * @return {JSX.Element} The rendered loading state.
 */
export default function LoadingState( { label } ) {
	// `speak()` owns this rather than a `role="status"`: a live region that
	// mounts with its text already in place is unreliable, and carrying
	// both makes some screen readers say it twice.
	useEffect( () => {
		speak( label, 'polite' );
	}, [ label ] );

	return (
		<VStack className="newspack-newsletters-admin__loading" alignment="center" spacing={ 3 }>
			<Spinner />
			<Text as="p">{ label }</Text>
		</VStack>
	);
}
