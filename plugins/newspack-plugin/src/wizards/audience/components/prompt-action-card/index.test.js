/**
 * The A/B test item in a prompt's actions menu in Campaigns: Start A/B Test,
 * View A/B Test for a prompt already in a test, or Start A/B Test disabled with
 * the reason for a placement a test can't use.
 */

/**
 * External dependencies
 */
import { fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * Internal dependencies
 */
import PromptActionCard from './index';
import ABTestsContext from '../../views/campaigns/ab-tests/context';

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( 'Copy' ) ) );
jest.mock( '../settings-drawer', () => () => null );

const prompt = { id: 7, title: 'Spring drive', status: 'publish', edit_link: '/wp-admin/post.php?post=7&action=edit', options: {} };

const abData = state => ( {
	goals: { donation: 'Donation', link_click: 'Link or button click' },
	prompts: [ { id: 7, title: 'Spring drive', status: 'publish', test_id: '', ineligible_reason: '', suggested_goal: 'donation', ...state } ],
} );

const openMenu = ( abTests, props = {} ) => {
	render(
		<ABTestsContext.Provider value={ abTests }>
			<PromptActionCard prompt={ prompt } resetDuplicated={ jest.fn() } { ...props } />
		</ABTestsContext.Provider>
	);
	fireEvent.click( screen.getByRole( 'button', { name: 'More options: Spring drive' } ) );
};

describe( 'PromptActionCard A/B test item', () => {
	it( 'offers nothing about tests while A/B testing is off', () => {
		openMenu( null );
		expect( screen.queryByRole( 'menuitem', { name: /A\/B Test/ } ) ).not.toBeInTheDocument();
	} );

	it( 'lists Start A/B Test after Duplicate and opens the modal with this prompt as version A', () => {
		openMenu( abData() );
		const items = screen.getAllByRole( 'menuitem' ).map( item => item.textContent );
		expect( items.indexOf( 'Start A/B Test' ) ).toBe( items.indexOf( 'Duplicate' ) + 1 );

		fireEvent.click( screen.getByRole( 'menuitem', { name: 'Start A/B Test' } ) );
		const dialog = screen.getByRole( 'dialog', { name: 'Start A/B Test' } );
		expect( dialog ).toHaveTextContent( 'Version B starts as a draft copy of “Spring drive” with the same placement.' );
		expect( screen.getByLabelText( 'Test name' ) ).toHaveValue( 'Spring drive' );
		expect( screen.getByLabelText( 'Goal' ) ).toHaveValue( 'donation' );
	} );

	it( 'links a prompt already in a test to that test’s card', () => {
		openMenu( abData( { test_id: 'spring-drive-1a2b' } ) );
		expect( screen.queryByRole( 'menuitem', { name: 'Start A/B Test' } ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'menuitem', { name: 'View A/B Test' } ) );
		expect( window.location.hash ).toBe( '#/ab-tests?test=spring-drive-1a2b' );
	} );

	it( 'disables Start A/B Test on a placement a test can’t use and describes why', () => {
		const reason = 'Prompts on manual or custom placements can’t be A/B tested.';
		openMenu( abData( { ineligible_reason: reason } ) );
		const item = screen.getByRole( 'menuitem', { name: 'Start A/B Test' } );
		expect( item ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( item ).toHaveAccessibleDescription( reason );

		fireEvent.click( item );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );
} );
