/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';
import { HashRouter } from 'react-router-dom';

/**
 * WordPress dependencies.
 */
import { Popover } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import withWizard from './';
import withWizardScreen from '../with-wizard-screen';

// The footer withWizard renders links to these.
window.newspack_urls = { support: 'https://help.newspack.com/' };

describe( 'withWizard', () => {
	// Legacy screens render a Page without a Wizard. The Page's content popover
	// slot only registers under a SlotFillProvider, so without one here their
	// popovers would go back over the sticky header.
	it( 'gives a screen that renders a Page without a Wizard its content popover slot', () => {
		const Screen = withWizardScreen( () => <Popover>Content popover</Popover> );
		const WrappedScreen = withWizard( () => <Screen headerText="Brands" /> );

		render(
			<HashRouter>
				<WrappedScreen />
			</HashRouter>
		);

		expect( screen.getByText( 'Content popover' ).closest( '.newspack-page__content-popovers' ) ).not.toBeNull();
	} );
} );
