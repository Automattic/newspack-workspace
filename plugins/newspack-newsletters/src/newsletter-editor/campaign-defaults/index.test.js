/**
 * A layout's sender and list defaults have to reach the post meta before the
 * first save, or a test send from a new newsletter fails with "Please input
 * sender name and email address" (NPPM-3821). These tests render the component
 * with no sidebar panel around it, to show it needs none.
 */

import { createReduxStore, register, select } from '@wordpress/data';
import { render } from '@testing-library/react';
import ApplyCampaignDefaults, { getCampaignDefaultsMeta } from './index';
import { registerStore, STORE_NAMESPACE, updateNewsletterData } from '../store';

const mockEditPost = jest.fn();
const mockSavePost = jest.fn();
let mockMeta = {};

// A stand-in for the block editor's store, so the component runs against the
// real data registry and the real newsletters store. What matters is how that
// store's merging reducer treats the clear.
register(
	createReduxStore( 'core/editor', {
		reducer: ( state = {} ) => state,
		selectors: { getEditedPostAttribute: () => mockMeta },
		actions: {
			editPost: edits => {
				mockEditPost( edits );
				return { type: 'TEST_EDIT_POST' };
			},
			savePost: () => {
				mockSavePost();
				return { type: 'TEST_SAVE_POST' };
			},
		},
	} )
);
registerStore();
const getNewsletterData = () => select( STORE_NAMESPACE ).getData();

const DEFAULTS = { senderEmail: 'news@example.test', senderName: 'Example News', send_list_id: '1' };

beforeEach( () => {
	mockEditPost.mockClear();
	mockSavePost.mockClear();
	updateNewsletterData( { senderName: 'Older Sender', senderEmail: 'older@example.test', send_list_id: '9' } );
} );

describe( 'ApplyCampaignDefaults', () => {
	it( 'applies the layout defaults and saves without a panel around it', () => {
		mockMeta = { senderEmail: '', senderName: '', send_list_id: '', stringifiedCampaignDefaults: JSON.stringify( DEFAULTS ) };
		render( <ApplyCampaignDefaults /> );

		expect( mockEditPost ).toHaveBeenCalledWith( { meta: { ...DEFAULTS, stringifiedCampaignDefaults: '' } } );
		expect( mockSavePost ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'clears the provider values it replaced in the newsletters store', () => {
		mockMeta = { stringifiedCampaignDefaults: JSON.stringify( { senderName: 'Example News', send_list_id: '1' } ) };
		render( <ApplyCampaignDefaults /> );

		expect( getNewsletterData() ).toMatchObject( { senderName: undefined, send_list_id: undefined, senderEmail: 'older@example.test' } );
	} );

	it( 'does nothing once the defaults have been applied', () => {
		mockMeta = { ...DEFAULTS, stringifiedCampaignDefaults: '' };
		render( <ApplyCampaignDefaults /> );

		expect( mockEditPost ).not.toHaveBeenCalled();
		expect( mockSavePost ).not.toHaveBeenCalled();
		expect( getNewsletterData() ).toMatchObject( { senderName: 'Older Sender', senderEmail: 'older@example.test', send_list_id: '9' } );
	} );
} );

describe( 'getCampaignDefaultsMeta', () => {
	it( 'accepts the defaults as an object as well as a string', () => {
		expect( getCampaignDefaultsMeta( DEFAULTS ) ).toEqual( { ...DEFAULTS, stringifiedCampaignDefaults: '' } );
	} );

	it( 'skips empty values so they do not overwrite what the post already has', () => {
		expect( getCampaignDefaultsMeta( '{"senderEmail":"","senderName":"Example News"}' ) ).toEqual( {
			senderName: 'Example News',
			stringifiedCampaignDefaults: '',
		} );
	} );

	it.each( [ [ '' ], [ undefined ], [ '{}' ], [ 'not json' ] ] )( 'returns null for %p', value => {
		expect( getCampaignDefaultsMeta( value ) ).toBeNull();
	} );
} );
