/**
 * WordPress dependencies.
 */
import { createSlotFill } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Page } from 'newspack-components';

const { Slot, Fill } = createSlotFill( 'NewspackAppHeaderActions' );

export const AppHeaderActions = ( { children } ) => {
	return <Fill>{ children }</Fill>;
};

export default ( { breadcrumbItems, subHeaderText } ) => {
	return <Page breadcrumbItems={ breadcrumbItems } subTitle={ subHeaderText } actions={ <Slot /> } />;
};
