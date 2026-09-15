/**
 * The gate pickers and the summaries name institutions from a list fetched once per app
 * lifetime, so every write here has to drop it — otherwise an institution renamed or
 * deleted a moment ago is still named the old way, or named at all, wherever a gate is
 * inspected. The cache itself is covered in `access-rule-option-sources.test.js`; this is
 * the half that would regress silently if these handlers were refactored.
 */

/**
 * External dependencies
 */
import { act, render, waitFor } from '@testing-library/react';

/**
 * Internal dependencies
 */
import InstitutionEdit from './edit';

// mock-prefixed so Jest's hoisted jest.mock factories may close over them.
const mockApiFetch = jest.fn();
const mockInvalidate = jest.fn();
const mockHeaderActions = { current: null };
const mockDispatch = {
	setHeaderData: data => {
		mockHeaderActions.current = data.actions ?? mockHeaderActions.current;
	},
	startLoadingData: jest.fn(),
	finishLoadingData: jest.fn(),
	addNotice: jest.fn(),
};

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: ( ...args ) => mockApiFetch( ...args ) } ) );
jest.mock( '@wordpress/data', () => ( { useDispatch: () => mockDispatch } ) );
jest.mock( '@wordpress/icons', () => ( { envelope: null, globe: null, customPostType: null } ) );

jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	const Passthrough = ( { children } ) => React.createElement( 'div', null, children );
	return { __experimentalVStack: Passthrough, CardBody: Passthrough, Spinner: Passthrough, TextareaControl: () => null };
} );

// The form's own fields are irrelevant to the invalidation contract, and the real
// components package cannot load in this jsdom env.
jest.mock( '../../../../../../packages/components/src', () => {
	const React = require( 'react' );
	const Passthrough = ( { children } ) => React.createElement( 'div', null, children );
	return {
		CardSettingsGroup: Passthrough,
		Divider: () => null,
		Grid: Passthrough,
		ImageUpload: () => null,
		Router: { useHistory: () => ( { push: jest.fn() } ) },
		SectionHeader: () => null,
		TextControl: () => null,
		// Confirm immediately: the dialog is not what is under test.
		useConfirmDialog: () => ( { confirmDialog: null, requestConfirm: callback => callback() } ),
	};
} );

jest.mock( '../../../../../../packages/components/src/wizard/store', () => ( { WIZARD_STORE_NAMESPACE: 'newspack/wizards' } ) );

jest.mock( '../../../../../content-gate/access-rule-option-sources', () => ( {
	INSTITUTION_RULE_SLUG: 'institution',
	invalidateAccessRuleOptions: ( ...args ) => mockInvalidate( ...args ),
} ) );

const EXISTING_INSTITUTION = {
	id: 5,
	title: { raw: 'City Library', rendered: 'City Library' },
	excerpt: { raw: '', rendered: '' },
	featured_media: 0,
	status: 'publish',
	meta: {},
};

/**
 * Render the editor for an existing institution and hand back its header actions, which
 * is where Save and Delete live.
 */
const renderEditor = async () => {
	mockApiFetch.mockResolvedValue( EXISTING_INSTITUTION );
	render( <InstitutionEdit match={ { params: { id: '5' } } } /> );
	await waitFor( () => expect( mockHeaderActions.current ).not.toBeNull() );
	return mockHeaderActions.current;
};

describe( 'Institution editor — fetched option list invalidation', () => {
	beforeEach( () => {
		mockInvalidate.mockClear();
		mockApiFetch.mockReset();
		mockHeaderActions.current = null;
	} );

	it.each( [ 'Save', 'Delete' ] )( '%s drops the cached list, so the gate pickers name what the site now has', async label => {
		const actions = await renderEditor();

		await act( async () => actions.find( action => action.label === label ).action() );

		expect( mockInvalidate ).toHaveBeenCalledWith( 'institution' );
	} );

	it( 'keeps the cached list when the write failed, since nothing changed', async () => {
		const actions = await renderEditor();
		mockApiFetch.mockRejectedValue( new Error( 'Network error' ) );

		await act( async () => actions.find( action => action.label === 'Save' ).action() );

		expect( mockInvalidate ).not.toHaveBeenCalled();
	} );
} );
