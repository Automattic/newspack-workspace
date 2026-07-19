/**
 * External dependencies
 */
import { render } from '@testing-library/react';

/**
 * Internal dependencies
 */
import IntegrationIcon from '../../../../../packages/components/src/integration-icon';

describe( 'IntegrationIcon', () => {
	it( 'renders the provider brand mark inside a provider-classed badge', () => {
		const { container } = render( <IntegrationIcon provider="mailchimp" /> );
		const badge = container.querySelector( '.newspack-integration-icon' );
		expect( badge ).not.toBeNull();
		expect( badge.classList.contains( 'newspack-integration-icon--mailchimp' ) ).toBe( true );
		expect( badge.querySelector( 'svg' ) ).not.toBeNull();
	} );

	it( 'hyphenates multi-word provider slugs into the modifier class', () => {
		const { container } = render( <IntegrationIcon provider="active_campaign" /> );
		expect( container.querySelector( '.newspack-integration-icon--active-campaign' ) ).not.toBeNull();
	} );

	it( 'renders nothing for an unknown provider', () => {
		const { container } = render( <IntegrationIcon provider="unknown_esp" /> );
		expect( container.querySelector( '.newspack-integration-icon' ) ).toBeNull();
	} );

	it.each( [ '__proto__', 'constructor', 'toString', 'hasOwnProperty' ] )(
		'renders nothing for the prototype-key slug %s without throwing',
		slug => {
			const { container } = render( <IntegrationIcon provider={ slug } /> );
			expect( container.querySelector( '.newspack-integration-icon' ) ).toBeNull();
			expect( container ).toBeEmptyDOMElement();
		}
	);
} );
