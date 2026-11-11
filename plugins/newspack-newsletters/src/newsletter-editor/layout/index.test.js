/**
 * The sidebar re-renders on every editor change. The layout preview restarts
 * its load-and-reveal cycle whenever its `blocks` prop changes identity, so a
 * new array on each render keeps it behind its spinner (NPPM-3482). This test
 * re-renders the panel and expects the preview to keep the same `blocks`.
 */

jest.mock( '@wordpress/data', () => ( {
	withSelect: () => Component => Component,
	withDispatch: () => Component => Component,
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	parse: () => [ { name: 'core/paragraph', attributes: {}, innerBlocks: [] } ],
	serialize: () => '',
} ) );

jest.mock( '@wordpress/components', () => ( {
	BaseControl: ( { children } ) => <div>{ children }</div>,
	Button: () => null,
	Modal: () => null,
	TextControl: () => null,
	Spinner: () => null,
	__experimentalVStack: ( { children } ) => <div>{ children }</div>,
} ) );

const mockLayouts = [ { ID: 1, post_title: 'Layout', post_content: '<!-- wp:paragraph /-->', meta: {} } ];

jest.mock( '../../utils/hooks', () => ( {
	useLayoutsState: () => ( { layouts: mockLayouts, isFetchingLayouts: false } ),
} ) );

jest.mock( '../../utils', () => ( {
	isUserDefinedLayout: () => false,
} ) );

jest.mock( '../../components/newsletter-preview', () => jest.fn( () => null ) );

jest.mock( './style.scss', () => ( {} ) );

import { render } from '@testing-library/react';
import NewsletterPreview from '../../components/newsletter-preview';
import Layout from './index';

const props = {
	layoutId: 1,
	postBlocks: [],
	postStatus: 'draft',
	isEditedPostEmpty: false,
	editPost: jest.fn(),
	savePost: jest.fn(),
	saveLayout: jest.fn(),
	createErrorNotice: jest.fn(),
};

describe( 'Layout panel', () => {
	it( 'keeps the preview blocks stable across re-renders', () => {
		const { rerender } = render( <Layout { ...props } postTitle="First" layoutMeta={ {} } /> );
		rerender( <Layout { ...props } postTitle="Second" layoutMeta={ {} } /> );

		const blocksPerRender = NewsletterPreview.mock.calls.map( ( [ previewProps ] ) => previewProps.blocks );
		expect( blocksPerRender.length ).toBeGreaterThan( 1 );
		blocksPerRender.forEach( blocks => expect( blocks ).toBe( blocksPerRender[ 0 ] ) );
	} );
} );
