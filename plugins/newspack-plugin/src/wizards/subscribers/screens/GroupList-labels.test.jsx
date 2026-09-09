/**
 * The group list on a site that renames groups: the heading noun and the spoken
 * count phrase both have to reach the wizard header, or a renamed "Teams" heading
 * is announced with the breadcrumb's own fallback wording.
 *
 * Its own file because labels.js reads the payload once, at import time.
 */

/**
 * External dependencies
 */
import { render, act } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '../../../../packages/components/src/wizard/store', () => ( { WIZARD_STORE_NAMESPACE: 'test/group-list-labels' } ) );

jest.mock( '../../../../packages/components/src', () => ( {
	DataViews: () => null,
	Badge: () => null,
	Button: () => null,
	Notice: () => null,
	Waiting: () => null,
} ) );

jest.mock( '../data/use-avatars', () => ( { SHOW_AVATARS: false, useAvatars: () => ( { avatars: {}, loading: false } ) } ) );

let headerCalls = [];

register(
	createReduxStore( 'test/group-list-labels', {
		reducer: ( state = {} ) => state,
		actions: {
			setHeaderData: data => {
				headerCalls.push( data );
				return { type: 'NOOP' };
			},
		},
	} )
);

window.newspackSubscribers = { groupLabelPlural: 'Teams' };
const GroupList = require( './GroupList' ).default;

const group = id => ( {
	id,
	owner: { name: `Owner ${ id }`, email: `owner${ id }@example.com`, editUrl: '' },
	plan: 'Team plan',
	members: 3,
	seatLimit: 5,
	status: 'active',
	createdAt: '2026-01-01T00:00:00Z',
	editUrl: '',
} );

/** The leaf of the last header payload that named the section. */
const publishedSection = () => {
	const named = headerCalls.filter( data => data.sectionName );
	return named[ named.length - 1 ].sectionName[ 0 ];
};

describe( 'the group list header under a publisher’s own noun', () => {
	beforeEach( () => {
		headerCalls = [];
		apiFetch.mockReset();
	} );

	it( 'speaks the publisher’s noun alongside the heading', async () => {
		apiFetch.mockResolvedValue( { items: [ group( 1 ), group( 2 ) ] } );
		await act( async () => {
			render( <GroupList /> );
		} );

		expect( publishedSection().label ).toBe( 'Teams' );
		expect( publishedSection().count ).toBe( 2 );
		expect( publishedSection().countLabel ).toBe( '2 Teams' );
	} );
} );
