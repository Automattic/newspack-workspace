/**
 * Tests the shared Subscribers field.
 */

/**
 * WordPress dependencies.
 */
import { speak } from '@wordpress/a11y';

/**
 * External dependencies.
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import SubscriberFields from './subscriber-fields';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

// The picker resolves ids over the REST API, which is beside the point here.
jest.mock( './search-token-field', () => () => <div data-testid="subscription-picker" /> );

const SPECIFIC_HELP = 'Only subscribers of the subscriptions below get the discount.';
const ALL_HELP = 'Anyone with an active subscription gets the discount.';

const renderFields = targeting =>
	render(
		<SubscriberFields
			value={ { subscription_targeting: targeting, subscription_product_ids: [] } }
			onChange={ jest.fn() }
			label="Subscribers"
			specificHelp={ SPECIFIC_HELP }
			allHelp={ ALL_HELP }
		/>
	);

describe( 'SubscriberFields', () => {
	beforeEach( () => speak.mockClear() );

	// RadioControl renders `help` as the fieldset's description, which a screen
	// reader announces once, on entry. Selecting the other option rewrites that
	// string with nothing to re-read it, so without this the reader never learns
	// what the mode they just chose means — and here the help text is the whole
	// meaning of the mode.
	it( 'announces what the newly selected mode means, in both directions', () => {
		const { unmount } = renderFields( 'subscriptions' );
		fireEvent.click( screen.getByLabelText( 'All subscriptions' ) );
		expect( speak ).toHaveBeenCalledWith( ALL_HELP, 'polite' );
		unmount();

		renderFields( 'all' );
		fireEvent.click( screen.getByLabelText( 'Specific subscriptions' ) );
		expect( speak ).toHaveBeenCalledWith( SPECIFIC_HELP, 'polite' );
	} );
} );
