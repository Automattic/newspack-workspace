/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import CampaignActions from './campaign-actions';

const CAMPAIGNS = [ { term_id: 33, name: 'Spring drive', status: 'publish' } ];
const PROMPTS = [
	{ id: 1, status: 'publish', campaign_groups: [ { term_id: 33 } ] },
	{ id: 2, status: 'draft', campaign_groups: [ { term_id: 33 } ] },
	{ id: 3, status: 'trash', campaign_groups: [ { term_id: 33 } ] },
	{ id: 4, status: 'publish', campaign_groups: [ { term_id: 34 } ] },
	// PHP's `get_the_terms()` returns `false` for a prompt with no campaign.
	{ id: 5, status: 'publish', campaign_groups: false },
];

describe( 'CampaignActions', () => {
	it( 'renders nothing when no campaign is selected', () => {
		const { container } = render( <CampaignActions campaignId="trash" campaigns={ CAMPAIGNS } prompts={ PROMPTS } /> );
		expect( container.innerHTML ).toBe( '' );
	} );

	it( 'activates only the prompts in the campaign that are not in the trash', () => {
		const manageCampaignGroup = jest.fn();
		render( <CampaignActions campaignId="33" campaigns={ CAMPAIGNS } prompts={ PROMPTS } manageCampaignGroup={ manageCampaignGroup } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Campaign actions: Spring drive' } ) );
		fireEvent.click( screen.getByRole( 'menuitem', { name: 'Activate All Prompts' } ) );
		expect( manageCampaignGroup.mock.calls[ 0 ][ 0 ].map( ( { id } ) => id ) ).toEqual( [ 1, 2 ] );
	} );
} );
