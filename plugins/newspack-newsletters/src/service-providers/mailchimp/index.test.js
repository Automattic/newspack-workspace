import { render, screen } from '@testing-library/react';

import mailchimp from './';

const { renderPreSendInfo } = mailchimp;

const meta = { send_list_id: 'list1', send_sublist_id: '1' };
const newsletterData = sublist => ( {
	campaign: { recipients: { list_id: 'list1' } },
	lists: [ { id: 'list1', name: 'Audience', count: 5000 } ],
	sublists: sublist ? [ { id: '1', name: 'Segment one', entity_type: 'segment', ...sublist } ] : [],
} );

describe( 'Mailchimp renderPreSendInfo', () => {
	it( "shows the segment's own count", () => {
		render( renderPreSendInfo( newsletterData( { count: 20 } ), meta ) );

		expect( screen.getByText( '20 subscribers' ) ).toBeInTheDocument();
		expect( screen.queryByText( '5000 subscribers' ) ).toBeNull();
	} );

	// Saved-segment counts are fetched daily, so a new segment has none yet.
	it( 'does not show the audience total for a segment without a count', () => {
		render( renderPreSendInfo( newsletterData( {} ), meta ) );

		expect( screen.getByText( 'Subscriber count not available yet' ) ).toBeInTheDocument();
		expect( screen.queryByText( '5000 subscribers' ) ).toBeNull();
	} );

	it( 'shows the audience total when no segment is selected', () => {
		render( renderPreSendInfo( newsletterData( null ), meta ) );

		expect( screen.getByText( '5000 subscribers' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Subscriber count not available yet' ) ).toBeNull();
	} );
} );
