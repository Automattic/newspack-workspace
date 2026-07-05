/**
 * External dependencies
 */
import { render, fireEvent, waitFor, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

import SingleSegment from './single-segment';

// Sample criteria for input testing.
const criteria = [
	{
		id: 'articles_read',
		category: 'reader_engagement',
		matching_function: 'range',
		name: 'Articles read',
		description: 'Number of articles read in the last 30 day period.',
	},
	{
		id: 'newsletter',
		category: 'reader_activity',
		matching_function: 'default',
		name: 'Newsletter',
		options: [
			{ label: 'Subscribers and non-subscribers', value: '' },
			{ label: 'Subscribers', value: 'subscribers' },
			{ label: 'Non-subscribers', value: 'non-subscribers' },
		],
		matching_attribute: 'newsletter',
	},
	{
		id: 'sources_to_match',
		category: 'referrer_sources',
		matching_function: 'list__in',
		name: 'Sources to match',
		description: 'Segment based on traffic source',
		help: 'A comma-separated list of domains.',
		placeholder: 'google.com, facebook.com',
		matching_attribute: 'referrer',
	},
];

const SEGMENTS = [
	{
		name: 'Subscribers',
		configuration: {
			is_disabled: false,
		},
		id: '5fa056b4b94bc',
		created_at: '2020-11-02',
		updated_at: '2020-11-02',
		priority: 0,
	},
];

describe( 'A new segment creation', () => {
	const mockProps = {
		segmentId: 'new',
		setSegments: jest.fn(),
		wizardApiFetch: ( { data, method } ) =>
			new Promise( resolve => {
				if ( method === 'POST' ) {
					resolve( [ ...SEGMENTS, data ] );
				} else {
					resolve( SEGMENTS );
				}
			} ),
	};

	beforeEach( () => {
		window.newspackAudienceCampaigns = { criteria };
		render(
			<MemoryRouter>
				<SingleSegment { ...mockProps } />
			</MemoryRouter>
		);
	} );

	it( 'renders title field, and a disabled save button', () => {
		expect( screen.getByPlaceholderText( 'Untitled Segment' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Save' ) ).toBeDisabled();
	} );

	it( 'renders inputs for each criteria', () => {
		expect( screen.getByTestId( 'newspack-criteria-articles_read' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'newspack-criteria-newsletter' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'newspack-criteria-sources_to_match' ) ).toBeInTheDocument();
	} );

	it( 'creates a new segment', async () => {
		fireEvent.change( screen.getByPlaceholderText( 'Untitled Segment' ), {
			target: { value: 'Big time readers that subscribed and came from Google or Twitter' },
		} );
		expect( screen.getByText( 'Save' ) ).toBeDisabled();

		// Save button is disabled until at least one option has been updated.
		fireEvent.change( screen.getByTestId( 'newspack-criteria-articles_read' ).querySelector( 'input[data-testid="min"]' ), {
			target: { value: '42' },
		} );
		expect( screen.getByText( 'Save' ) ).not.toBeDisabled();

		fireEvent.change( screen.getByTestId( 'newspack-criteria-newsletter' ), {
			target: { value: 'subscribers' },
		} );
		fireEvent.change( screen.getByTestId( 'newspack-criteria-sources_to_match' ), {
			target: { value: 'google.com,twitter.com' },
		} );

		fireEvent.click( screen.getByText( 'Save' ) );

		await waitFor( () => expect( mockProps.setSegments ).toHaveBeenCalledTimes( 1 ) );
		expect( mockProps.setSegments ).toHaveBeenCalledWith( [
			...SEGMENTS,
			{
				name: 'Big time readers that subscribed and came from Google or Twitter',
				configuration: {
					is_disabled: false,
				},
				criteria: [
					{
						criteria_id: 'articles_read',
						value: { min: '42' },
					},
					{
						criteria_id: 'newsletter',
						value: 'subscribers',
					},
					{
						criteria_id: 'sources_to_match',
						value: 'google.com,twitter.com',
					},
				],
			},
		] );
	} );
} );
