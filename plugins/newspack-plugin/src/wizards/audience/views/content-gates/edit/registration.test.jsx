/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Registration from './registration';

jest.mock( '../../../../../../packages/components/src', () => ( {
	ActionCard: () => null,
} ) );
jest.mock( './metering', () => () => null );
jest.mock( './access-rule', () => ( { config, slug, enabled, onToggle } ) => (
	<button data-testid={ `rule-${ slug }` } aria-pressed={ enabled } onClick={ () => onToggle( slug ) }>
		{ config.name }
	</button>
) );

describe( 'Registration gate settings', () => {
	beforeEach( () => {
		window.newspackAudienceContentGates = {
			available_access_rules: {
				institution: { name: 'Institutional access', supports_anonymous: true, default: [] },
				email_domain: { name: 'Whitelisted email domain', default: '' },
			},
		};
	} );

	it( 'offers only the rules that can recognize a signed-out visitor', () => {
		render(
			<Registration registration={ { active: true, metering: { enabled: false }, require_verification: false } } onChange={ jest.fn() } />
		);

		expect( screen.getByTestId( 'rule-institution' ) ).toBeInTheDocument();
		expect( screen.queryByTestId( 'rule-email_domain' ) ).not.toBeInTheDocument();
	} );

	it( 'stores each rule as its own group, so a match on any one lets the visitor in', () => {
		const onChange = jest.fn();
		const registration = { active: true, metering: { enabled: false }, require_verification: false, gate_layout_id: 123 };

		render( <Registration registration={ registration } onChange={ onChange } /> );
		fireEvent.click( screen.getByTestId( 'rule-institution' ) );

		expect( onChange ).toHaveBeenCalledWith(
			expect.objectContaining( { access_rules: [ [ { slug: 'institution', value: [] } ] ], gate_layout_id: 123 } )
		);
	} );

	it( 'turns a rule off by removing its group', () => {
		const onChange = jest.fn();
		const registration = {
			active: true,
			metering: { enabled: false },
			require_verification: false,
			access_rules: [ [ { slug: 'institution', value: [ 7 ] } ] ],
		};

		render( <Registration registration={ registration } onChange={ onChange } /> );
		fireEvent.click( screen.getByTestId( 'rule-institution' ) );

		expect( onChange ).toHaveBeenCalledWith( expect.objectContaining( { access_rules: [] } ) );
	} );

	it( 'preserves fields it does not manage (gate_layout_id) when a setting changes', () => {
		const onChange = jest.fn();
		const registration = {
			active: true,
			metering: { enabled: false },
			require_verification: false,
			gate_layout_id: 123,
		};

		render( <Registration registration={ registration } onChange={ onChange } isNewsletter /> );

		fireEvent.click( screen.getByRole( 'checkbox' ) );

		expect( onChange ).toHaveBeenCalledWith( expect.objectContaining( { require_verification: true, gate_layout_id: 123 } ) );
	} );
} );
