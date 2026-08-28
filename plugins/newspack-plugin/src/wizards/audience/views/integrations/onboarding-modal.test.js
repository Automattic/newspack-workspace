/**
 * External dependencies
 */
import { fireEvent, render, screen } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { OnboardingModal } from './onboarding-modal';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/components', () => ( {
	Modal: ( { title, children, onRequestClose } ) => (
		<div role="dialog" aria-label={ title }>
			<button onClick={ onRequestClose }>close</button>
			{ children }
		</div>
	),
	Button: ( { children, onClick } ) => <button onClick={ onClick }>{ children }</button>,
} ) );

describe( 'OnboardingModal', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {} );
		window.newspackAudienceIntegrations = { show_onboarding: true, onboarding_notices: [] };
	} );

	it( 'renders nothing once onboarding was dismissed', () => {
		window.newspackAudienceIntegrations.show_onboarding = false;
		const { container } = render( <OnboardingModal /> );
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'shows on first visit and stores the dismissal when acknowledged', () => {
		render( <OnboardingModal /> );
		expect( screen.getByRole( 'dialog', { name: 'Welcome to Integrations' } ) ).toBeInTheDocument();
		fireEvent.click( screen.getByText( 'Got it' ) );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/newspack/v1/wizard/newspack-audience-integrations/onboarding/dismiss',
			method: 'POST',
		} );
	} );

	it( 'renders extra notices supplied by the site', () => {
		window.newspackAudienceIntegrations.onboarding_notices = [ 'Reader sync moved to the ActiveCampaign integration.' ];
		render( <OnboardingModal /> );
		expect( screen.getByText( 'Reader sync moved to the ActiveCampaign integration.' ) ).toBeInTheDocument();
	} );
} );
