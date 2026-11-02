/**
 * A layout's sender and list defaults have to reach the post meta before the
 * first save, or a test send from a new newsletter fails with "Please input
 * sender name and email address" (NPPM-3821). The component is rendered here
 * on its own, with no sidebar panel around it, because a collapsed panel is
 * what kept the defaults from being applied.
 */

const mockEditPost = jest.fn();
const mockSavePost = jest.fn();
let mockMeta = {};

jest.mock( '@wordpress/data', () => ( {
	useSelect: selector => selector( () => ( { getEditedPostAttribute: () => mockMeta } ) ),
	useDispatch: () => ( { editPost: mockEditPost, savePost: mockSavePost } ),
} ) );

import { render } from '@testing-library/react';
import ApplyCampaignDefaults, { getCampaignDefaultsMeta } from './index';

const DEFAULTS = { senderEmail: 'news@example.test', senderName: 'Example News', send_list_id: '1' };

beforeEach( () => {
	mockEditPost.mockClear();
	mockSavePost.mockClear();
} );

describe( 'ApplyCampaignDefaults', () => {
	it( 'applies the layout defaults and saves, with no panel mounted', () => {
		mockMeta = { senderEmail: '', senderName: '', send_list_id: '', stringifiedCampaignDefaults: JSON.stringify( DEFAULTS ) };
		render( <ApplyCampaignDefaults /> );

		expect( mockEditPost ).toHaveBeenCalledWith( { meta: { ...DEFAULTS, stringifiedCampaignDefaults: '' } } );
		expect( mockSavePost ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does nothing once the defaults have been applied', () => {
		mockMeta = { ...DEFAULTS, stringifiedCampaignDefaults: '' };
		render( <ApplyCampaignDefaults /> );

		expect( mockEditPost ).not.toHaveBeenCalled();
		expect( mockSavePost ).not.toHaveBeenCalled();
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
