/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Edit from './edit';
import metadata from './block.json';
import { getBlockDefaultClassName } from '@wordpress/blocks';

jest.mock( '@wordpress/block-editor', () => ( {
	useBlockProps: () => ( { 'data-testid': 'block-wrapper' } ),
	RichText: ( { value, className, placeholder, tagName: Tag = 'span', allowedFormats } ) => (
		<Tag className={ className } data-placeholder={ placeholder } data-allowed-formats={ JSON.stringify( allowedFormats ) }>
			{ value }
		</Tag>
	),
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	getBlockDefaultClassName: name => 'wp-block-' + name.replace( '/', '-' ),
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: str => str,
} ) );

jest.mock( '@wordpress/date', () => ( {
	dateI18n: jest.fn( () => '2099' ),
} ) );

const blockClass = getBlockDefaultClassName( metadata.name );

const defaultProps = {
	attributes: { prefix: '\u00a9', suffix: '' },
	setAttributes: jest.fn(),
};

describe( 'Copyright Date Edit', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'should use dateI18n for the year to respect site timezone', () => {
		const { dateI18n } = require( '@wordpress/date' );
		render( <Edit { ...defaultProps } /> );

		expect( dateI18n ).toHaveBeenCalledWith( 'Y' );
		expect( screen.getByText( '2099' ) ).toBeInTheDocument();
	} );

	it( 'should render the default copyright symbol prefix', () => {
		render( <Edit { ...defaultProps } /> );

		const prefix = screen.getByText( '\u00a9' );
		expect( prefix ).toBeInTheDocument();
		expect( prefix ).toHaveClass( `${ blockClass }__prefix` );
	} );

	it( 'should render a custom prefix', () => {
		render( <Edit { ...{ ...defaultProps, attributes: { prefix: 'Copyright', suffix: '' } } } /> );

		expect( screen.getByText( 'Copyright' ) ).toBeInTheDocument();
	} );

	it( 'should render suffix when provided', () => {
		render( <Edit { ...{ ...defaultProps, attributes: { prefix: '\u00a9', suffix: 'Acme Inc' } } } /> );

		const suffix = screen.getByText( 'Acme Inc' );
		expect( suffix ).toBeInTheDocument();
		expect( suffix ).toHaveClass( `${ blockClass }__suffix` );
	} );

	it( 'should have correct BEM class on year element', () => {
		render( <Edit { ...defaultProps } /> );

		expect( screen.getByText( '2099' ) ).toHaveClass( `${ blockClass }__year` );
	} );

	it( 'should only allow link formatting on prefix and suffix RichText fields', () => {
		render( <Edit { ...{ ...defaultProps, attributes: { prefix: '\u00a9', suffix: 'Acme' } } } /> );

		const prefix = screen.getByText( '\u00a9' );
		const suffix = screen.getByText( 'Acme' );

		expect( prefix ).toHaveAttribute( 'data-allowed-formats', '["core/link"]' );
		expect( suffix ).toHaveAttribute( 'data-allowed-formats', '["core/link"]' );
	} );
} );
