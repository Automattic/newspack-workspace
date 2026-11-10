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
		expect( screen.queryByRole( 'button', { name: /^Remove/ } ) ).toBeNull();
	} );

	it( 'never files the newsletters rule under Other rules', () => {
		setup( { rules: [ { slug: 'newsletters', value: [ 'list-1' ] } ] } );
		expect( screen.queryByText( 'Other rules' ) ).toBeNull();
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
		fireEvent.click( screen.getByRole( 'button', { name: 'Remove Sections rule' } ) );
		expect( onChange ).toHaveBeenCalledWith( [ { slug: 'category', value: [ '1' ] } ] );
	} );

	it( 'shows an unavailable rule with its term IDs', () => {
		const onChange = setup( {
			extra: { gone: { name: 'gone', default: [], unavailable: true } },
			rules: [ { slug: 'gone', value: [ '7', '9' ] } ],
		} );
		expect( screen.getByText( 'gone (no longer on this site): 7, 9' ) ).toBeTruthy();
		expect( screen.getByText( "Remove this rule to save the gate while it's active, or to change its rules." ) ).toBeTruthy();
		fireEvent.click( screen.getByRole( 'button', { name: 'Remove gone rule' } ) );
		expect( onChange ).toHaveBeenCalledWith( [] );
	} );
} );
