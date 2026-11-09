/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( './content-rule-control', () => ( { slug } ) => <span>{ slug }</span> );
jest.mock( './content-rule', () => ( { slug } ) => <div>{ `offered:${ slug }` }</div> );

// The module reads the offered rules as it loads; the extra rules are read per render.
window.newspackAudienceContentGates = {
	available_content_rules: { category: { name: 'Categories', default: [] } },
	extra_content_rules: {},
};
const ContentRules = require( './content-rules' ).default;

const setup = ( { extra = {}, rules = [] } = {} ) => {
	window.newspackAudienceContentGates.extra_content_rules = extra;
	const onChange = jest.fn();
	render( <ContentRules rules={ rules } onChange={ onChange } /> );
	return onChange;
};

describe( 'ContentRules', () => {
	it( 'renders no Other rules section when every rule is offered', () => {
		setup( { rules: [ { slug: 'category', value: [ '1' ] } ] } );
		expect( screen.queryByText( 'Other rules' ) ).toBeNull();
		expect( screen.queryByText( 'Remove' ) ).toBeNull();
	} );

	it( 'lists a stored-only rule and removes it', () => {
		const onChange = setup( {
			extra: { np_section: { name: 'Sections', endpoint: '/wp/v2/np_section', default: [] } },
			rules: [
				{ slug: 'category', value: [ '1' ] },
				{ slug: 'np_section', value: [ '5' ] },
			],
		} );
		expect( screen.getByText( 'Other rules' ) ).toBeTruthy();
		expect( screen.getByText( 'np_section' ) ).toBeTruthy();
		fireEvent.click( screen.getByText( 'Remove' ) );
		expect( onChange ).toHaveBeenCalledWith( [ { slug: 'category', value: [ '1' ] } ] );
	} );

	it( 'shows an unavailable rule with its term IDs', () => {
		const onChange = setup( {
			extra: { gone: { name: 'gone', default: [], unavailable: true } },
			rules: [ { slug: 'gone', value: [ '7', '9' ] } ],
		} );
		expect( screen.getByText( 'gone (no longer on this site): 7, 9' ) ).toBeTruthy();
		expect( screen.getByText( "A gate can't be published while it has a rule that's no longer on this site." ) ).toBeTruthy();
		fireEvent.click( screen.getByText( 'Remove' ) );
		expect( onChange ).toHaveBeenCalledWith( [] );
	} );
} );
