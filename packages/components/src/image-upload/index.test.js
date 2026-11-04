/**
 * External dependencies
 */
import { render } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ImageUpload from './';

describe( 'ImageUpload', () => {
	it( 'should render an add image button', () => {
		const { getByText } = render( <ImageUpload /> );
		expect( getByText( 'Upload' ) ).toBeInTheDocument();
	} );

	it( 'names its buttons with actionLabels while keeping the visible text', () => {
		const labels = { upload: 'Upload Site Icon', replace: 'Replace Site Icon', remove: 'Remove Site Icon' };
		const { getByRole, rerender } = render( <ImageUpload actionLabels={ labels } /> );
		expect( getByRole( 'button', { name: 'Upload Site Icon' } ) ).toHaveTextContent( 'Upload' );

		rerender( <ImageUpload actionLabels={ labels } image={ { id: 1, url: 'https://example.test/icon.png' } } /> );
		expect( getByRole( 'button', { name: 'Replace Site Icon' } ) ).toHaveTextContent( 'Replace' );
		expect( getByRole( 'button', { name: 'Remove Site Icon' } ) ).toHaveTextContent( 'Remove' );
	} );

	it( 'should render replace and remove buttons if there is an image provided', () => {
		const image = {
			id: 1234,
			url: 'https://upload.wikimedia.org/wikipedia/en/a/a9/Example.jpg',
		};
		const { getByText, getByTestId } = render( <ImageUpload image={ image } /> );
		expect( getByText( 'Remove' ) ).toBeInTheDocument();
		expect( getByText( 'Replace' ) ).toBeInTheDocument();
		expect( getByTestId( 'image-upload' ) ).toBeInTheDocument();
		expect( getByTestId( 'image-upload' ).getAttribute( 'src' ) ).toEqual( image.url );
	} );
} );
