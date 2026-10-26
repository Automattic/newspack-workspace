/**
 * The preview stays hidden until it finds BlockPreview's iframe and its assets
 * load, with an 8 second fallback. Core translates that iframe's title, so
 * finding it by title left previews on non-English admins behind a spinner
 * (NPPM-3482). This test renders the iframe with a translated title and expects
 * the preview to reveal on load, not on the fallback.
 *
 * `BlockPreview` is stubbed with core's wrapper markup: the real one needs a
 * block editor store and renders nothing useful in jsdom.
 */

jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
	BlockPreview: () => (
		<div className="block-editor-block-preview__container">
			<div className="block-editor-block-preview__content">
				<iframe title="Editor-Arbeitsfläche" />
			</div>
		</div>
	),
} ) );

jest.mock( '@wordpress/components', () => ( {
	Spinner: () => <div data-testid="spinner" />,
} ) );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: () => ( { updateSettings: jest.fn() } ),
	// A populated string marks the live editor, so no assets are seeded.
	useSelect: () => ( { styles: '' } ),
} ) );

jest.mock( '../../editor/blocks/posts-inserter/sample-posts', () => ( {
	getSamplePosts: () => [],
} ) );

jest.mock( '../../editor/blocks/posts-inserter/utils', () => ( {
	getTemplateBlocks: () => [],
} ) );

jest.mock( './style.scss', () => ( {} ) );

import { render, waitFor } from '@testing-library/react';
import NewsletterPreview from './index';

const blocks = [ { name: 'core/paragraph', attributes: {}, innerBlocks: [] } ];

const getPreview = container => container.querySelector( '.newspack-newsletters__layout-preview' );

describe( 'NewsletterPreview', () => {
	it( 'reveals the preview when the iframe title is translated', async () => {
		const { container, queryByTestId } = render( <NewsletterPreview layoutId={ 1 } blocks={ blocks } /> );

		// Well under the 8 second fallback, so only the on-load reveal can pass.
		await waitFor( () => expect( getPreview( container ) ).toHaveClass( 'is-ready' ), { timeout: 1000 } );
		expect( queryByTestId( 'spinner' ) ).toBeNull();
	} );
} );
