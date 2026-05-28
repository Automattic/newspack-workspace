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
