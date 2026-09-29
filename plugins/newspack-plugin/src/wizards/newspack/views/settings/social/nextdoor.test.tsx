/**
 * The Settings pane branches on the connection status that arrives with the rest of
 * the section's data. Mirroring that status into component state put the branch a
 * commit behind the switch that mounts the pane, so a connected site rendered, and
 * spoke, "not connected" on every visit.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import Nextdoor from './nextdoor';
import useWizardApiFetchToggle from '../../../../hooks/use-wizard-api-fetch-toggle';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( '../../../../hooks/use-wizard-api-fetch-toggle', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const CONNECTED = {
	module_enabled_nextdoor: true,
	is_connected: true,
	connection_status: {
		is_connected: true,
		has_credentials: true,
		has_centralized_credentials: false,
		has_tokens: true,
		has_page: true,
		token_valid: true,
	},
	settings: {
		client_id: 'client-id',
		client_secret: 'client-secret',
		publication_url: 'https://example.test',
		allowed_roles: [ 'administrator' ],
	},
};

// Matched on the notice, not its wording: a copy change would otherwise turn this green.
const errorNotice = () => document.querySelector( '.components-notice.is-error' );

beforeEach( () => {
	jest.clearAllMocks();
	( useWizardApiFetchToggle as jest.Mock ).mockReturnValue( {
		description: 'Nextdoor',
		apiData: CONNECTED,
		isFetching: false,
		actionText: 'Enabled',
		apiFetchToggle: jest.fn(),
		errorMessage: null,
	} );
} );

describe( 'the Nextdoor section on a connected site', () => {
	it( 'never says the integration is not connected', () => {
		render( <Nextdoor /> );

		expect( errorNotice() ).toBeNull();
		expect( speak ).not.toHaveBeenCalledWith( expect.stringContaining( 'not connected' ), expect.anything() );
	} );

	// Without this the case above would also pass on a pane that rendered nothing at all.
	it( 'shows the connection as established on the first render', () => {
		render( <Nextdoor /> );

		expect( screen.getByText( 'Connected' ) ).toBeInTheDocument();
	} );
} );
