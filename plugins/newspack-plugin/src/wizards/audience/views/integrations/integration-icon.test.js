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

	it.each( [
		[ 'salesforce', 'white' ],
		[ 'beehiiv', 'white' ],
		[ 'fundraiseup', 'white' ],
	] )( 'renders the %s mark in its brand colour inside its provider badge', ( provider, fill ) => {
		const { container } = render( <IntegrationIcon provider={ provider } /> );
		const badge = container.querySelector( `.newspack-integration-icon--${ provider }` );
		expect( badge ).not.toBeNull();
		expect( badge.querySelector( 'svg path' ) ).toHaveAttribute( 'fill', fill );
	} );

	it( 'renders the Gravity Forms mark in white inside its orange badge', () => {
		const { container } = render( <IntegrationIcon provider="gravity_forms" /> );
		const badge = container.querySelector( '.newspack-integration-icon--gravity-forms' );
		expect( badge ).not.toBeNull();
		expect( badge.querySelector( 'svg path' ) ).toHaveAttribute( 'fill', 'white' );
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
