/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Edit from './edit';
import { useUserAvatar, usePostAuthors } from './hooks';
import { useCustomByline } from '../../shared/hooks/use-custom-byline';

jest.mock( './hooks', () => ( {
	useUserAvatar: jest.fn(),
	usePostAuthors: jest.fn(),
	useDefaultAvatar: jest.fn( () => '' ),
} ) );

jest.mock( '../../shared/hooks/use-custom-byline', () => ( {
	useCustomByline: jest.fn(),
	extractAuthorIdsFromByline: jest.requireActual( '../../shared/hooks/use-custom-byline' ).extractAuthorIdsFromByline,
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: ( { children } ) => <div data-testid="inspector">{ children }</div>,
	useBlockProps: () => ( {} ),
	__experimentalUseBorderProps: () => ( { className: '', style: {} } ),
} ) );

jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { children } ) => <div>{ children }</div>,
	RangeControl: () => null,
	ToggleControl: () => null,
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: str => str,
} ) );

jest.mock( '@wordpress/url', () => ( {
	addQueryArgs: ( url, args ) => `${ url }?s=${ args.s }`,
	removeQueryArgs: url => url,
} ) );

const defaultProps = {
	attributes: { size: 48, linkToAuthorArchive: false },
	context: { postId: 1, postType: 'post' },
	setAttributes: jest.fn(),
};

const mockSingleAuthorAvatar = {
	src: 'https://example.com/author-avatar.jpg',
	alt: 'Author Avatar',
	minSize: 16,
	maxSize: 128,
};

describe( 'Avatar Edit', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'should show placeholder when custom byline is text-only (no author shortcodes)', () => {
		usePostAuthors.mockReturnValue( [] );
		useUserAvatar.mockReturnValue( mockSingleAuthorAvatar );
		useCustomByline.mockReturnValue( {
			bylineActive: true,
			bylineContent: 'By Staff Reporter',
		} );

		render( <Edit { ...defaultProps } /> );

		expect( screen.queryByRole( 'img', { name: 'No avatar available' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'img', { name: 'Author Avatar' } ) ).not.toBeInTheDocument();
	} );

	it( 'should render the single author avatar when no custom byline and no coauthors', () => {
		usePostAuthors.mockReturnValue( [] );
		useUserAvatar.mockReturnValue( mockSingleAuthorAvatar );
		useCustomByline.mockReturnValue( {
			bylineActive: false,
			bylineContent: '',
		} );

		render( <Edit { ...defaultProps } /> );

		expect( screen.getByRole( 'img' ) ).toBeInTheDocument();
	} );

	it( 'should render avatars when custom byline has author shortcodes', () => {
		const mockAuthors = [ { id: 1, name: 'Jane Doe', avatarSrc: 'https://example.com/jane.jpg' } ];
		usePostAuthors.mockReturnValue( mockAuthors );
		useUserAvatar.mockReturnValue( mockSingleAuthorAvatar );
		useCustomByline.mockReturnValue( {
			bylineActive: true,
			bylineContent: 'By [Author id=1]Jane Doe[/Author]',
		} );

		render( <Edit { ...defaultProps } /> );

		expect( screen.getByRole( 'img' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'img', { name: 'No avatar available' } ) ).not.toBeInTheDocument();
	} );
} );
