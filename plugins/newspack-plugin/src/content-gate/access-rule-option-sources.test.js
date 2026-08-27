/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { INSTITUTION_RULE_SLUG, getAccessRuleOptionSource, invalidateAccessRuleOptions } from './access-rule-option-sources';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const INSTITUTIONS = [
	{ id: 12, title: { raw: 'City Library' } },
	{ id: 34, title: { raw: 'State University' } },
];

const fetchInstitutions = () => getAccessRuleOptionSource( INSTITUTION_RULE_SLUG )();

describe( 'getAccessRuleOptionSource', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		invalidateAccessRuleOptions( INSTITUTION_RULE_SLUG );
		apiFetch.mockResolvedValue( INSTITUTIONS );
	} );

	it( 'has no source for a rule whose options are localised with the page', () => {
		expect( getAccessRuleOptionSource( 'subscription' ) ).toBeUndefined();
	} );

	it( 'asks for the whole collection, since a truncated list would name real IDs "not listed"', async () => {
		await expect( fetchInstitutions() ).resolves.toEqual( [
			{ value: 12, label: 'City Library' },
			{ value: 34, label: 'State University' },
		] );
		expect( apiFetch ).toHaveBeenCalledWith( { path: expect.stringContaining( 'per_page=-1' ) } );
	} );

	// api-fetch walks the collection a page at a time, and the block editor remounts its
	// picker on every block selection, so a fetch per reader would be a walk per reader.
	it( 'walks the collection once however many readers ask for it', async () => {
		await Promise.all( [ fetchInstitutions(), fetchInstitutions() ] );
		await fetchInstitutions();

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'fetches again once a write invalidates the list', async () => {
		await fetchInstitutions();
		invalidateAccessRuleOptions( INSTITUTION_RULE_SLUG );

		apiFetch.mockResolvedValue( [ ...INSTITUTIONS, { id: 56, title: { raw: 'Town Archive' } } ] );

		await expect( fetchInstitutions() ).resolves.toContainEqual( { value: 56, label: 'Town Archive' } );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	} );

	// A failure is what leaves the picker on the localised list, so it must not be the
	// answer every later reader gets for the rest of the session.
	it( 'retries after a failed request rather than caching the failure', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'Network error' ) );

		await expect( fetchInstitutions() ).rejects.toThrow( 'Network error' );
		await expect( fetchInstitutions() ).resolves.toHaveLength( 2 );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	} );
} );
