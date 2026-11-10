import { fireEvent, render, screen } from '@testing-library/react';

import Story from './story';

const mockFetchStory = jest.fn();
const mockSaveStory = jest.fn();
let mockStory;
let mockError;
let mockLoading;

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn( callback =>
		callback( () => ( {
			isLoadingStory: () => mockLoading,
			canEditStory: () => true,
			getStoryError: () => mockError,
		} ) )
	),
	useDispatch: () => ( {
		saveStory: mockSaveStory,
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
		Spinner: () => <span data-testid="spinner" />,
		Notice: Box,
		Button: ( { children, href, onClick } ) =>
			href ? (
				<a href={ href }>{ children }</a>
			) : (
				<button type="button" onClick={ onClick }>
					{ children }
				</button>
			),
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

jest.mock( './story-field-panel', () => ( { story, onChange } ) => (
	<>
		<button type="button" onClick={ () => onChange( { ...story, title: 'Edited' } ) }>
			Change title
		</button>
		<button type="button" onClick={ () => onChange( { ...story, slug: 'edited-slug' } ) }>
			Change slug
		</button>
	</>
) );

describe( 'Story', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		mockError = null;
		mockLoading = false;
		mockStory = { id: 12, title: 'A story' };
	} );

	it( 'renders a story loaded without metadata and fetches the full story once', () => {
		const { rerender } = render( <Story storyId={ 12 } /> );
		rerender( <Story storyId={ 12 } /> );

		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeTruthy();
		expect( mockFetchStory ).toHaveBeenCalledTimes( 1 );
		expect( mockFetchStory ).toHaveBeenCalledWith( 12 );
	} );

	it( 'does not fetch a story that is absent from the store', () => {
		mockStory = undefined;
		render( <Story storyId={ 12 } /> );

		expect( mockFetchStory ).not.toHaveBeenCalled();
	} );

	it( 'does not fetch again once the story has metadata', () => {
		mockStory = { id: 12, title: 'A story', metadata: { edit_url: 'https://example.test/edit' } };
		render( <Story storyId={ 12 } /> );

		expect( mockFetchStory ).not.toHaveBeenCalled();
		expect( screen.getByText( 'Edit post' ) ).toBeTruthy();
	} );

	it( 'saves the story that arrives after the first render', () => {
		mockStory = undefined;
		const { rerender } = render( <Story storyId={ 12 } /> );
		mockStory = { id: 12, title: 'A story', metadata: {} };
		rerender( <Story storyId={ 12 } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect( mockSaveStory ).toHaveBeenCalledWith( 12, mockStory );
	} );

	it( 'keeps an edit when the store story updates afterwards', () => {
		mockStory = { id: 12, title: 'A story', metadata: {} };
		const { rerender } = render( <Story storyId={ 12 } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Change title' } ) );
		mockStory = { id: 12, title: 'Refreshed', metadata: {} };
		rerender( <Story storyId={ 12 } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect( mockSaveStory ).toHaveBeenCalledWith( 12, { id: 12, title: 'Edited', metadata: {} } );
	} );

	it( 'shows a spinner, not the preview-unavailable notice, while metadata loads', () => {
		render( <Story storyId={ 12 } /> );

		expect( screen.getAllByTestId( 'spinner' ).length ).toBeGreaterThan( 0 );
		expect( screen.queryByText( 'Preview is unavailable' ) ).toBeNull();
	} );

	it( 'shows the fetch error when no preview will load', () => {
		mockLoading = true;
		const { rerender } = render( <Story storyId={ 12 } /> );
		mockLoading = false;
		mockError = 'Could not load the story';
		rerender( <Story storyId={ 12 } /> );

		expect( screen.getByText( 'Could not load the story' ) ).toBeTruthy();
		expect( screen.getByText( 'Preview is unavailable' ) ).toBeTruthy();
	} );

	it( 'does not show the spinner again after a failed fetch and a field edit', () => {
		mockLoading = true;
		const { rerender } = render( <Story storyId={ 12 } /> );
		mockLoading = false;
		mockError = 'Could not load the story';
		rerender( <Story storyId={ 12 } /> );
		mockError = null;
		fireEvent.click( screen.getByRole( 'button', { name: 'Change title' } ) );
		rerender( <Story storyId={ 12 } /> );

		expect( screen.queryByTestId( 'spinner' ) ).toBeNull();
		expect( screen.getByText( 'Preview is unavailable' ) ).toBeTruthy();
	} );

	it( 'keeps an earlier edit when a later edit follows a store update', () => {
		mockStory = { id: 12, title: 'A story', metadata: {} };
		const { rerender } = render( <Story storyId={ 12 } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Change title' } ) );
		mockStory = { id: 12, title: 'Refreshed', metadata: {} };
		rerender( <Story storyId={ 12 } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Change slug' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect( mockSaveStory ).toHaveBeenCalledWith( 12, { id: 12, title: 'Edited', slug: 'edited-slug', metadata: {} } );
	} );
} );
