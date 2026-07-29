/**
 * External dependencies
 */
import { render, fireEvent, waitFor, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

import SingleSegment, { isMultiSelectCriteria } from './single-segment';

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
	{
		id: 'LAST_GIFT_DATE',
		category: 'integrations',
		matching_function: 'date_range',
		name: 'ActiveCampaign: Last Gift Date',
		matching_attribute: 'LAST_GIFT_DATE',
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

describe( 'segment criteria input selection', () => {
	it( 'treats list matching functions with options as multi-select', () => {
		expect( isMultiSelectCriteria( { matching_function: 'list__in', options: [ {} ] } ) ).toBe( true );
		expect( isMultiSelectCriteria( { matching_function: 'list__not_in', options: [ {} ] } ) ).toBe( true );
		expect( isMultiSelectCriteria( { matching_function: 'default', options: [ {} ] } ) ).toBe( false );
		expect( isMultiSelectCriteria( { matching_function: 'list__in', options: [] } ) ).toBe( false );
	} );
} );

describe( 'Date range criteria input', () => {
	const mockProps = {
		segmentId: 'new',
		setSegments: jest.fn(),
		wizardApiFetch: () => Promise.resolve( [] ),
	};

	beforeEach( () => {
		window.newspackAudienceCampaigns = { criteria };
		render(
			<MemoryRouter>
				<SingleSegment { ...mockProps } />
			</MemoryRouter>
		);
	} );

	it( 'renders a bound selector for each end of the range', () => {
		expect( screen.getByLabelText( 'From' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'To' ) ).toBeInTheDocument();
	} );

	it( 'shows no value input until a bound type is chosen', () => {
		expect( screen.queryByTestId( 'date-range-start-value' ) ).not.toBeInTheDocument();
	} );

	it( 'seeds a value when a bound type is chosen, so no half-filled bound exists', () => {
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'past' } } );
		const input = screen.getByTestId( 'date-range-start-value' );
		expect( input ).toHaveValue( 0 );
	} );

	it( 'stores a relative bound as signed days', () => {
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'past' } } );
		fireEvent.change( screen.getByTestId( 'date-range-start-value' ), { target: { value: '30' } } );
		// Re-read: a past bound of 30 days renders back as "Days ago" with 30.
		expect( screen.getByLabelText( 'From' ) ).toHaveValue( 'past' );
		expect( screen.getByTestId( 'date-range-start-value' ) ).toHaveValue( 30 );
	} );

	it( 'clears a bound back to Any', () => {
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'past' } } );
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: '' } } );
		expect( screen.queryByTestId( 'date-range-start-value' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the selector on "Days from now" after choosing it', () => {
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'future' } } );
		// A zero-magnitude relative bound is ambiguous; the selector must not snap back to "Days ago".
		expect( screen.getByLabelText( 'From' ) ).toHaveValue( 'future' );
	} );

	it( 'stores a positive offset when a magnitude is typed under "Days from now"', () => {
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'future' } } );
		fireEvent.change( screen.getByTestId( 'date-range-start-value' ), { target: { value: '7' } } );
		// A negated offset would re-derive as "Days ago" once the magnitude is non-zero.
		expect( screen.getByLabelText( 'From' ) ).toHaveValue( 'future' );
		expect( screen.getByTestId( 'date-range-start-value' ) ).toHaveValue( 7 );
	} );

	it( 'switches from "Days from now" back to "Days ago" and stays there', () => {
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'future' } } );
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'past' } } );
		expect( screen.getByLabelText( 'From' ) ).toHaveValue( 'past' );
	} );
} );

describe( 'Date range criteria input for an existing segment with a loaded "Days from now" end bound', () => {
	// Regression coverage for editing (not just loading) a bound that arrived from
	// storage: backspacing the value to empty makes the bound ambiguous (days: 0),
	// and chosenType must already hold the loaded direction so the selector doesn't
	// flip to the opposite direction.
	const existingSegment = {
		...SEGMENTS[ 0 ],
		criteria: [
			{
				criteria_id: 'LAST_GIFT_DATE',
				value: { end: { type: 'relative', days: 7 } },
			},
		],
	};

	const wizardApiFetch = jest.fn( ( { method } = {} ) => Promise.resolve( 'POST' === method ? existingSegment : [ existingSegment ] ) );

	const mockProps = {
		segmentId: existingSegment.id,
		setSegments: jest.fn(),
		wizardApiFetch,
	};

	beforeEach( () => {
		window.newspackAudienceCampaigns = { criteria };
		wizardApiFetch.mockClear();
		render(
			<MemoryRouter>
				<SingleSegment { ...mockProps } />
			</MemoryRouter>
		);
	} );

	it( 'keeps "Days from now" once the loaded value is cleared, and stores a positive offset when retyped', async () => {
		await waitFor( () => expect( screen.getByLabelText( 'To' ) ).toHaveValue( 'future' ) );

		fireEvent.change( screen.getByTestId( 'date-range-end-value' ), { target: { value: '' } } );
		// The regression: without the fix this selector flips to "Days ago".
		expect( screen.getByLabelText( 'To' ) ).toHaveValue( 'future' );

		fireEvent.change( screen.getByTestId( 'date-range-end-value' ), { target: { value: '14' } } );
		expect( screen.getByLabelText( 'To' ) ).toHaveValue( 'future' );

		fireEvent.click( screen.getByText( 'Save' ) );

		expect( wizardApiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				method: 'POST',
				data: expect.objectContaining( {
					criteria: [ { criteria_id: 'LAST_GIFT_DATE', value: { end: { type: 'relative', days: 14 } } } ],
				} ),
			} )
		);
	} );
} );

describe( 'Date range criteria input for an existing segment with an ambiguous bound', () => {
	// The stored bound is ambiguous (days: 0) and only arrives after the async
	// fetch resolves, which is exactly the scenario the regression hit: the
	// control first mounts with no bound at all, then re-renders once the real,
	// ambiguous bound loads.
	const existingSegment = {
		...SEGMENTS[ 0 ],
		criteria: [
			{
				criteria_id: 'LAST_GIFT_DATE',
				value: { start: { type: 'relative', days: 0 } },
			},
		],
	};

	const mockProps = {
		segmentId: existingSegment.id,
		setSegments: jest.fn(),
		wizardApiFetch: () => Promise.resolve( [ existingSegment ] ),
	};

	beforeEach( () => {
		window.newspackAudienceCampaigns = { criteria };
		render(
			<MemoryRouter>
				<SingleSegment { ...mockProps } />
			</MemoryRouter>
		);
	} );

	it( 'renders the derived direction and value once the stored bound loads, instead of Any', async () => {
		await waitFor( () => expect( screen.getByLabelText( 'From' ) ).toHaveValue( 'past' ) );
		expect( screen.getByTestId( 'date-range-start-value' ) ).toHaveValue( 0 );
	} );
} );
