/**
 * Reusable loading state for admin-shell screens.
 *
 * For the first paint only, where there is no list behind it and so no
 * `aria-busy` either — hence the label and the live region. Once a list
 * has rendered, DataViews' own loading treatment takes over.
 */

import { Spinner, __experimentalText as Text, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * @param {Object} props
 * @param {string} props.label What is being fetched, e.g. "Fetching newsletters…".
 * @return {JSX.Element} The rendered loading state.
 */
export default function LoadingState( { label } ) {
	return (
		<VStack className="newspack-newsletters-admin__loading" alignment="center" spacing={ 3 } role="status">
			<Spinner />
			<Text as="p">{ label }</Text>
		</VStack>
	);
}
