/**
 * Tests for the Donors CampaignSection (NEWS-2580): renders the by-campaign
 * table, pins/mutes the untagged row, and shows the campaign-tagged empty state
 * when there is nothing tagged (no rows, or only the "(no campaign)" row).
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import CampaignSection from './CampaignSection';
import type { DonorsCampaignRow } from '../../api/donors';

const untagged = ( over: Partial< DonorsCampaignRow > = {} ): DonorsCampaignRow => ( {
	value: '(no campaign)',
	count: 4,
	amount: 120,
	is_untagged: true,
	...over,
} );

describe( 'Donors CampaignSection', () => {
	it( 'renders tagged campaign rows with count and revenue', () => {
		const rows: DonorsCampaignRow[] = [
			{ value: 'spring-drive', count: 8, amount: 640, is_untagged: false },
			{ value: 'buffer', count: 3, amount: 150, is_untagged: false },
			untagged(),
		];

		render( <CampaignSection rows={ rows } /> );

		expect( screen.getByText( 'Donations by campaign' ) ).toBeInTheDocument();
		expect( screen.getByText( 'spring-drive' ) ).toBeInTheDocument();
		expect( screen.getByText( 'buffer' ) ).toBeInTheDocument();
		// The untagged row renders its real count (a denominator, not a blank).
		expect( screen.getByText( '(no campaign)' ) ).toBeInTheDocument();
	} );

	it( 'mutes the untagged row and renders it last', () => {
		const rows: DonorsCampaignRow[] = [ { value: 'spring-drive', count: 8, amount: 640, is_untagged: false }, untagged() ];

		const { container } = render( <CampaignSection rows={ rows } /> );

		const bodyRows = container.querySelectorAll( 'tbody tr' );
		expect( bodyRows ).toHaveLength( 2 );
		// Untagged row is last; its "(no campaign)" label is muted via the shared
		// N/A treatment (DataViews rows carry no per-row modifier class).
		const last = bodyRows[ bodyRows.length - 1 ];
		expect( last ).toHaveTextContent( '(no campaign)' );
		expect( last.querySelector( '.newspack-insights__table-na' ) ).toBeInTheDocument();
		// Tagged rows are not muted.
		expect( bodyRows[ 0 ].querySelector( '.newspack-insights__table-na' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the campaign-tagged empty state when there are no rows', () => {
		render( <CampaignSection rows={ [] } /> );

		expect( screen.getByText( 'No campaign-tagged donations in this window.' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the empty state when only the untagged row is present', () => {
		render( <CampaignSection rows={ [ untagged() ] } /> );

		expect( screen.getByText( 'No campaign-tagged donations in this window.' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
	} );
} );
