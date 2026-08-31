// @jest-environment jsdom

/**
 * A failed option fetch must announce itself once per request, not once per reader.
 *
 * The gates landing page renders a `ContentGateSettings` per gate and each one calls
 * this hook, so a site with six gates had six readers attached to the one shared
 * promise. The wizard store appends notices unconditionally, so all six landed — and
 * `WizardSnackbar` announces `error` assertively, so a screen reader heard it six times.
 */

/**
 * External dependencies
 */
import { render, waitFor } from '@testing-library/react';

// mock-prefixed so Jest's hoisted jest.mock factories may close over them.
const mockAddNotice = jest.fn();
let mockRequest;

// The hook imports the wizard store's namespace, and that module registers the store at
// import time – so the mock has to satisfy registration as well as the hook's own use.
jest.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( { addNotice: ( ...args ) => mockAddNotice( ...args ) } ),
	createReduxStore: ( name, config ) => ( { name, ...config } ),
	register: () => {},
} ) );

// The source hands every reader the same promise – that sharing is what makes one
// rejection reach N readers, so the mock has to reproduce it rather than fetch per call.
jest.mock( '../../../../content-gate/access-rule-option-sources', () => ( {
	getAccessRuleOptionSource: slug => ( 'institution' === slug ? () => mockRequest : undefined ),
} ) );

jest.mock( '../../../../content-gate/access-rule-options', () => ( {
	getAccessRuleOptionsFetchFailedNotice: () => 'Could not load the options.',
} ) );

const { useAccessRuleOptions } = require( './use-access-rule-options' );

function Reader() {
	useAccessRuleOptions();
	return null;
}

describe( 'useAccessRuleOptions', () => {
	beforeEach( () => {
		mockAddNotice.mockClear();
		window.newspackAudienceContentGates = { available_access_rules: { institution: { options: [] } } };
	} );

	it( 'announces a failed fetch once however many readers share the request', async () => {
		mockRequest = Promise.reject( new Error( 'network' ) );
		mockRequest.catch( () => {} ); // Keep the shared rejection from tripping the unhandled-rejection guard.

		render(
			<>
				<Reader />
				<Reader />
				<Reader />
			</>
		);

		await waitFor( () => expect( mockAddNotice ).toHaveBeenCalled() );
		expect( mockAddNotice ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'announces a later failure again, so a retry that fails is not silent', async () => {
		mockRequest = Promise.reject( new Error( 'network' ) );
		mockRequest.catch( () => {} );
		const { unmount } = render( <Reader /> );
		await waitFor( () => expect( mockAddNotice ).toHaveBeenCalledTimes( 1 ) );
		unmount();

		// The source drops a rejected entry, so the next reader gets a fresh promise.
		mockRequest = Promise.reject( new Error( 'network' ) );
		mockRequest.catch( () => {} );
		render( <Reader /> );

		await waitFor( () => expect( mockAddNotice ).toHaveBeenCalledTimes( 2 ) );
	} );
} );
