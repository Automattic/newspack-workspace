/**
 * External dependencies.
 */
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { HashRouter } from 'react-router-dom';

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';

/**
 * Internal dependencies.
 */
import InstitutionEdit from './edit';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// InstitutionEdit reaches useHistory() through useConfirmDialog; in production it
// runs inside the wizard's HashRouter, so the tests supply the same context.
const renderEditor = ( { id } = {} ) =>
	render(
		<HashRouter>
			<InstitutionEdit match={ { params: id ? { id } : {} } } />
		</HashRouter>
	);

const institutionFixture = ipRange => ( {
	id: 7,
	title: { raw: 'Example University', rendered: 'Example University' },
	excerpt: { raw: '', rendered: '' },
	featured_media: 0,
	slug: 'example-university',
	status: 'publish',
	meta: { np_institution_email_domain: '', np_institution_ip_range: ipRange, np_institution_reader_data: '' },
} );

const getIpRangeField = () => screen.getByLabelText( /IPs, CIDR blocks, or IP ranges/ );
const getMessagesRegion = () => document.getElementById( 'newspack-institution-ip-range-messages' );
const getSaveAction = () =>
	select( WIZARD_STORE_NAMESPACE )
		.getHeaderData()
		?.actions?.find( action => action.label === 'Save' );

/**
 * Render the editor for a stored institution and wait for its meta to load.
 *
 * @param {string} ipRange The stored `np_institution_ip_range` value.
 * @return {Promise<HTMLElement>} The IP range field.
 */
async function renderStoredInstitution( ipRange ) {
	apiFetch.mockResolvedValue( institutionFixture( ipRange ) );
	renderEditor( { id: '7' } );
	await waitFor( () => expect( getIpRangeField() ).toBeInTheDocument() );
	return getIpRangeField();
}

beforeEach( () => {
	apiFetch.mockReset();
	dispatch( WIZARD_STORE_NAMESPACE ).resetNotices();
	dispatch( WIZARD_STORE_NAMESPACE ).resetHeaderData();
} );

describe( 'InstitutionEdit — IP range warnings', () => {
	it( 'analyzes stored entries on load, without the admin touching the field', async () => {
		await renderStoredInstitution( '10.0.0.1-banana' );
		expect( screen.getByText( /never grant access: 10\.0\.0\.1-banana/ ) ).toBeInTheDocument();
	} );

	it( 'establishes the live region before there is anything to announce, and describes the field with it', async () => {
		const field = await renderStoredInstitution( '203.0.113.5' );
		const region = getMessagesRegion();
		// Present but empty: a region added at the same moment as its first message
		// is not reliably announced by screen readers.
		expect( region ).toBeEmptyDOMElement();
		expect( field ).toHaveAttribute( 'aria-describedby', region.id );
		expect( field ).toHaveAttribute( 'aria-invalid', 'false' );
	} );

	it( 'recomputes from the committed value on blur, not on every keystroke', () => {
		renderEditor();
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'IP range' } ) );
		const field = getIpRangeField();

		// A half-typed range is not yet wrong, so nothing is flagged while typing.
		fireEvent.change( field, { target: { value: '10.0.0.1-banana' } } );
		expect( getMessagesRegion() ).toBeEmptyDOMElement();

		fireEvent.blur( field, { target: { value: '10.0.0.1-banana' } } );
		expect( screen.getByText( /never grant access: 10\.0\.0\.1-banana/ ) ).toBeInTheDocument();
		expect( field ).toHaveAttribute( 'aria-invalid', 'true' );
	} );

	it( 'drops a stale warning as soon as the admin edits the value again', async () => {
		const field = await renderStoredInstitution( '10.0.0.1-banana' );
		expect( getMessagesRegion() ).not.toBeEmptyDOMElement();
		fireEvent.change( field, { target: { value: '10.0.0.1-10.0.0.9' } } );
		expect( getMessagesRegion() ).toBeEmptyDOMElement();
		expect( field ).toHaveAttribute( 'aria-invalid', 'false' );
	} );

	it( 'drops the warning when the rule is toggled off', async () => {
		await renderStoredInstitution( '10.0.0.1-banana' );
		expect( getMessagesRegion() ).not.toBeEmptyDOMElement();
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'IP range' } ) );
		expect( getMessagesRegion() ).toBeNull();
	} );

	it( 'keeps aria-invalid false when only the over-broad warning applies: the entry is valid, just wide', async () => {
		const field = await renderStoredInstitution( '0.0.0.0/0' );
		expect( screen.getByText( /covers more than 65,536 addresses/ ) ).toBeInTheDocument();
		expect( field ).toHaveAttribute( 'aria-invalid', 'false' );
	} );

	it( 'names the confusable character rather than only calling the entry invalid', async () => {
		await renderStoredInstitution( '192.168.1.1–192.168.1.9' );
		expect( screen.getByText( /look standard but are not: en dash/ ) ).toBeInTheDocument();
	} );

	it( 'raises a snackbar on save so a paste-then-Save admin still sees the warning', async () => {
		await renderStoredInstitution( '10.0.0.1-banana' );
		await waitFor( () => expect( getSaveAction() ).toBeDefined() );
		apiFetch.mockResolvedValueOnce( institutionFixture( '10.0.0.1-banana' ) );
		await act( async () => {
			await getSaveAction().action();
		} );
		expect( select( WIZARD_STORE_NAMESPACE ).getNotices() ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( { type: 'warning', message: expect.stringMatching( /never grant access: 10\.0\.0\.1-banana/ ) } ),
			] )
		);
	} );

	it( 'stays quiet on save when every entry is valid and plausibly sized', async () => {
		await renderStoredInstitution( '198.51.100.0/24' );
		await waitFor( () => expect( getSaveAction() ).toBeDefined() );
		apiFetch.mockResolvedValueOnce( institutionFixture( '198.51.100.0/24' ) );
		await act( async () => {
			await getSaveAction().action();
		} );
		expect( select( WIZARD_STORE_NAMESPACE ).getNotices() ).toEqual( [] );
	} );
} );
