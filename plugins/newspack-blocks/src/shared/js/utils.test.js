import { formatSponsorLogos, formatSponsorByline } from './utils';

/**
 * Collect every anchor element in a React element tree.
 *
 * The formatters return element trees rendered inside the editor canvas,
 * where a live href navigates the canvas iframe away from the post being
 * edited (NPPM-3165). Walking the tree keeps these assertions valid across
 * structural changes to the markup.
 *
 * @param {*} node React element, array, or primitive.
 * @return {Array} All elements whose type is 'a'.
 */
const collectAnchors = node => {
	if ( Array.isArray( node ) ) {
		return node.flatMap( collectAnchors );
	}
	if ( ! node || typeof node !== 'object' ) {
		return [];
	}
	const anchors = node.type === 'a' ? [ node ] : [];
	return anchors.concat( collectAnchors( node.props?.children ?? [] ) );
};

const sponsors = [
	{
		id: 1,
		src: 'https://sponsor.example.test/logo.png',
		img_width: 100,
		img_height: 50,
		sponsor_name: 'Acme Example Supporters',
		sponsor_url: 'https://sponsor.example.test/supporters/',
		byline_prefix: 'Sponsored by',
	},
];

describe( 'sponsor markup in the editor preview', () => {
	it( 'renders the sponsor logo anchor with its real URL', () => {
		const anchors = collectAnchors( formatSponsorLogos( sponsors ) );
		expect( anchors.length ).toBeGreaterThan( 0 );
		anchors.forEach( anchor => expect( anchor.props.href ).toBe( 'https://sponsor.example.test/supporters/' ) );
	} );

	it( 'renders the sponsor byline anchor inert', () => {
		const anchors = collectAnchors( formatSponsorByline( sponsors ) );
		expect( anchors.length ).toBeGreaterThan( 0 );
		anchors.forEach( anchor => expect( anchor.props.href ).toBe( '#' ) );
	} );
} );
