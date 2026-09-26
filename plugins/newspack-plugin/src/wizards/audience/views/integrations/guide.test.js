/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { IntegrationGuide } from './guide';

// The Guide shows one page at a time. Laying every page out at once lets the
// test read the whole how-to without driving the pager.
jest.mock( '@wordpress/components', () => ( {
	ExternalLink: ( { href, children } ) => <a href={ href }>{ children }</a>,
	Guide: ( { pages } ) => (
		<div>
			{ pages.map( ( page, index ) => (
				<section key={ index }>{ page.content }</section>
			) ) }
		</div>
	),
} ) );

const INTEGRATION = {
	name: 'Gravity Forms',
	guide: [
		{ title: 'Add the form with the Gravity Forms block', description: 'Place the form on a page.' },
		{
			title: 'Submissions register readers',
			description: 'Each submission registers a reader account.',
			link: { label: 'Learn how to opt in forms placed without the block', url: 'https://help.example.test/gravity-forms/' },
		},
	],
};

describe( 'IntegrationGuide', () => {
	it( 'gives each step a page titled by a heading, with its description and any link', () => {
		render( <IntegrationGuide integration={ INTEGRATION } onClose={ () => {} } /> );
		expect( screen.getAllByRole( 'heading' ).map( heading => heading.textContent ) ).toEqual( [
			'Add the form with the Gravity Forms block',
			'Submissions register readers',
		] );
		expect( screen.getByText( 'Place the form on a page.' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'Learn how to opt in forms placed without the block' } ) ).toHaveAttribute(
			'href',
			'https://help.example.test/gravity-forms/'
		);
	} );
} );
