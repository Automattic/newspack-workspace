/**
 * External dependencies
 */
import { render } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( [] ) ) );

// The module reads the rule configs through window as it renders.
window.newspackAudienceContentGates = {
	available_content_rules: {},
	extra_content_rules: { np_noresttax: { name: 'Private sections', default: [] } },
};
const ContentRuleControl = require( './content-rule-control' ).default;

describe( 'ContentRuleControl static summary', () => {
	it( 'prints term IDs without a request for a stored rule with no REST route', () => {
		const { container } = render(
			<ContentRuleControl slug="np_noresttax" value={ [ '3', '4' ] } exclusion={ false } onChange={ () => {} } isStatic />
		);
		expect( container.textContent ).toContain( 'Private sections include:' );
		expect( container.textContent ).toContain( '3, 4' );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'says exclude for an exclusion rule', () => {
		const { container } = render( <ContentRuleControl slug="np_noresttax" value={ [ '3' ] } exclusion onChange={ () => {} } isStatic /> );
		expect( container.textContent ).toContain( 'Private sections exclude:' );
	} );
} );
