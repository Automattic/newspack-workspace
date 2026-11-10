import { render, screen } from '@testing-library/react';

import Story from './story';

const mockFetchStory = jest.fn();
let mockStory;

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn( callback =>
		callback( () => ( {
			isLoadingStory: () => false,
			canEditStory: () => true,
			getStoryError: () => null,
		} ) )
	),
	useDispatch: () => ( {
		saveStory: jest.fn(),
		clearErrors: jest.fn(),
		fetchStory: mockFetchStory,
	} ),
} ) );

jest.mock( '@wordpress/components', () => {
	const Box = ( { children } ) => <div>{ children }</div>;
	return {
		__experimentalHStack: Box,
		__experimentalVStack: Box,
		__experimentalSpacer: () => null,
		Icon: () => null,
		Spinner: () => null,
		Notice: Box,
		Button: ( { children, href } ) => ( href ? <a href={ href }>{ children }</a> : <button type="button">{ children }</button> ),
	};
} );

jest.mock( '../hooks', () => ( {
	useFields: () => [],
	useStory: () => mockStory,
} ) );

jest.mock( '../utils', () => ( {
	__esModule: true,
	default: { sites: { isRemoteSite: () => false } },
} ) );

jest.mock( './story-field-panel', () => () => null );

describe( 'Story', () => {
	beforeEach( () => {
		mockFetchStory.mockClear();
		mockStory = { id: 12, title: 'A story' };
	} );

	it( 'renders a story loaded without metadata and fetches the full story', () => {
		render( <Story storyId={ 12 } /> );

		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeTruthy();
		expect( mockFetchStory ).toHaveBeenCalledWith( 12 );
	} );

	it( 'does not fetch again once the story has metadata', () => {
		mockStory = { id: 12, title: 'A story', metadata: { edit_url: 'https://example.test/edit' } };
		render( <Story storyId={ 12 } /> );

		expect( mockFetchStory ).not.toHaveBeenCalled();
		expect( screen.getByText( 'Edit post' ) ).toBeTruthy();
	} );
} );
