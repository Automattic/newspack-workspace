/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import Campaigns from './index';
import { CampaignsContext } from '../../../contexts';

jest.mock( '../../../components/segment-group', () => () => null );

const renderCampaigns = ( campaignId, prompts = [] ) =>
	render(
		<MemoryRouter>
			<CampaignsContext.Provider value={ prompts }>
				<Campaigns campaignId={ campaignId } campaigns={ [] } segments={ [] } />
			</CampaignsContext.Provider>
		</MemoryRouter>
	);

describe( 'Campaigns view dropdown', () => {
	it( 'keeps Unassigned selected after the last unassigned prompt leaves it', () => {
		renderCampaigns( 'unassigned' );
		expect( screen.getByRole( 'combobox' ) ).toHaveTextContent( 'Unassigned Prompts' );
	} );
} );
