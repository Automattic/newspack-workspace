/**
 * The picker branches on the rule's `has_options` declaration, never on how many
 * options happen to be loaded. Branching on the loaded list is what let a gate on
 * a site with no institutions published render a free-text box, so a publisher
 * could type a name into a rule whose value must be an array of institution IDs —
 * and the resulting value granted access to everyone.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import AccessRuleControl from './access-rule-control';
import registerWizardStore from '../../../../../../packages/components/src/wizard/store';

jest.mock( '../../../../../../packages/components/src', () => ( {
	FormTokenField: () => <div data-testid="token-picker" />,
} ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( [] ) ) );

// The control dispatches a notice when its options request fails, so the wizard
// store has to exist for useDispatch() to resolve.
registerWizardStore();

const renderControl = ( slug, config, value ) => {
	window.newspackAudienceContentGates = { available_access_rules: { [ slug ]: config } };
	render( <AccessRuleControl slug={ slug } value={ value } onChange={ () => {} } /> );
};

describe( 'Access rule control', () => {
	it( 'renders the picker for an options-backed rule whose option list is empty', () => {
		renderControl( 'institution', { name: 'Institutional access', has_options: true, options: [] }, [] );

		expect( screen.getByTestId( 'token-picker' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'textbox' ) ).not.toBeInTheDocument();
	} );

	it( 'renders the free-text box only for a rule that declares no options source', () => {
		renderControl( 'email_domain', { name: 'Whitelisted email domain', has_options: false, options: [] }, '' );

		expect( screen.getByRole( 'textbox' ) ).toBeInTheDocument();
		expect( screen.queryByTestId( 'token-picker' ) ).not.toBeInTheDocument();
	} );
} );
