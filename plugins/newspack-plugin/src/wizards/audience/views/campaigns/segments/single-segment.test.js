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

	// The From/To rows only exist under the Custom preset; everything below that
	// exercises them opens with this.
	const selectCustom = () => fireEvent.change( screen.getByTestId( 'date-range-preset' ), { target: { value: 'custom' } } );

	it( 'opens on a single preset selector, with no bound rows', () => {
		expect( screen.getByTestId( 'date-range-preset' ) ).toHaveValue( '' );
		expect( screen.queryByLabelText( 'From' ) ).not.toBeInTheDocument();
		expect( screen.queryByLabelText( 'To' ) ).not.toBeInTheDocument();
	} );

	it( 'offers Any, the rolling windows, and Custom', () => {
		const options = [ ...screen.getByTestId( 'date-range-preset' ).options ].map( o => [ o.value, o.textContent ] );
		expect( options ).toEqual( [
			[ '', 'Any' ],
			[ '7', 'Last 7 days' ],
			[ '30', 'Last 30 days' ],
			[ '365', 'Last 365 days' ],
			[ 'custom', 'Custom' ],
		] );
	} );

	it( 'keeps the bound rows hidden when a rolling window is chosen', () => {
		fireEvent.change( screen.getByTestId( 'date-range-preset' ), { target: { value: '30' } } );
		expect( screen.getByTestId( 'date-range-preset' ) ).toHaveValue( '30' );
		expect( screen.queryByLabelText( 'From' ) ).not.toBeInTheDocument();
	} );

	it( 'stores a rolling window as a trailing relative range', () => {
		fireEvent.change( screen.getByTestId( 'date-range-preset' ), { target: { value: '7' } } );
		// Switching to Custom must reveal the same range the preset stood for,
		// rather than resetting it.
		selectCustom();
		expect( screen.getByLabelText( 'From' ) ).toHaveValue( 'past' );
		expect( screen.getByTestId( 'date-range-start-value' ) ).toHaveValue( 7 );
		expect( screen.getByLabelText( 'To' ) ).toHaveValue( 'past' );
		expect( screen.getByTestId( 'date-range-end-value' ) ).toHaveValue( 0 );
	} );

	it( 'stays on Custom once chosen, even while the range is still empty', () => {
		selectCustom();
		// An empty range looks exactly like "Any"; the deliberate choice has to win.
		expect( screen.getByTestId( 'date-range-preset' ) ).toHaveValue( 'custom' );
		expect( screen.getByLabelText( 'From' ) ).toBeInTheDocument();
	} );

	it( 'stays on Custom when a hand-built range happens to match a preset', () => {
		selectCustom();
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'past' } } );
		fireEvent.change( screen.getByTestId( 'date-range-start-value' ), { target: { value: '30' } } );
		fireEvent.change( screen.getByLabelText( 'To' ), { target: { value: 'past' } } );
		expect( screen.getByTestId( 'date-range-preset' ) ).toHaveValue( 'custom' );
	} );

	it( 'renders a bound selector for each end of the range', () => {
		selectCustom();
		expect( screen.getByLabelText( 'From' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'To' ) ).toBeInTheDocument();
	} );

	it( 'shows no value input until a bound type is chosen', () => {
		selectCustom();
		expect( screen.queryByTestId( 'date-range-start-value' ) ).not.toBeInTheDocument();
	} );

	it( 'seeds a value when a bound type is chosen, so no half-filled bound exists', () => {
		selectCustom();
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'past' } } );
		const input = screen.getByTestId( 'date-range-start-value' );
		expect( input ).toHaveValue( 0 );
	} );

	it( 'stores a relative bound as signed days', () => {
		selectCustom();
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'past' } } );
		fireEvent.change( screen.getByTestId( 'date-range-start-value' ), { target: { value: '30' } } );
		// Re-read: a past bound of 30 days renders back as "Days ago" with 30.
		expect( screen.getByLabelText( 'From' ) ).toHaveValue( 'past' );
		expect( screen.getByTestId( 'date-range-start-value' ) ).toHaveValue( 30 );
	} );

	it( 'clears a bound back to Any', () => {
		selectCustom();
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'past' } } );
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: '' } } );
		expect( screen.queryByTestId( 'date-range-start-value' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the selector on "Days from now" after choosing it', () => {
		selectCustom();
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'future' } } );
		// A zero-magnitude relative bound is ambiguous; the selector must not snap back to "Days ago".
		expect( screen.getByLabelText( 'From' ) ).toHaveValue( 'future' );
	} );

	it( 'stores a positive offset when a magnitude is typed under "Days from now"', () => {
		selectCustom();
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'future' } } );
		fireEvent.change( screen.getByTestId( 'date-range-start-value' ), { target: { value: '7' } } );
		// A negated offset would re-derive as "Days ago" once the magnitude is non-zero.
		expect( screen.getByLabelText( 'From' ) ).toHaveValue( 'future' );
		expect( screen.getByTestId( 'date-range-start-value' ) ).toHaveValue( 7 );
	} );

	it( 'switches from "Days from now" back to "Days ago" and stays there', () => {
		selectCustom();
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'future' } } );
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'past' } } );
		expect( screen.getByLabelText( 'From' ) ).toHaveValue( 'past' );
	} );

	it( 'seeds today when "Date" is chosen, so no half-filled bound exists', () => {
		selectCustom();
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'absolute' } } );
		expect( screen.getByTestId( 'date-range-start-value' ) ).not.toHaveValue( '' );
	} );

	it( 'leaves a cleared date empty instead of re-seeding today', () => {
		selectCustom();
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'absolute' } } );
		const input = screen.getByTestId( 'date-range-start-value' );
		fireEvent.change( input, { target: { value: '2020-01-01' } } );
		expect( input ).toHaveValue( '2020-01-01' );
		// A date input reports '' both when cleared and transiently while it is being
		// retyped. Substituting today() there would silently move a saved window.
		fireEvent.change( input, { target: { value: '' } } );
		expect( screen.getByTestId( 'date-range-start-value' ) ).toHaveValue( '' );
	} );

	it( 'gives the value input an accessible name of its own', () => {
		selectCustom();
		// The only visible text sits on the sibling select, so without a label here a
		// screen reader announces an unnamed date field or spin button.
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'past' } } );
		expect( screen.getByLabelText( 'Days' ) ).toBeInTheDocument();
		fireEvent.change( screen.getByLabelText( 'From' ), { target: { value: 'absolute' } } );
		expect( screen.getByLabelText( 'Date' ) ).toBeInTheDocument();
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

describe( 'Date range criteria input for an existing segment stored as a rolling window', () => {
	// A range saved as a preset has to come back as that preset rather than as
	// Custom — the two produce identical values, so only the shape distinguishes them.
	// A second criterion so the segment stays saveable once the date range is
	// dropped — a segment with no criteria at all can't be saved.
	const existingSegment = {
		...SEGMENTS[ 0 ],
		criteria: [
			{
				criteria_id: 'LAST_GIFT_DATE',
				value: { start: { type: 'relative', days: -30 }, end: { type: 'relative', days: 0 } },
			},
			{ criteria_id: 'newsletter', value: 'subscribers' },
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

	it( 'shows the matching preset rather than Custom, with no bound rows', async () => {
		await waitFor( () => expect( screen.getByTestId( 'date-range-preset' ) ).toHaveValue( '30' ) );
		expect( screen.queryByLabelText( 'From' ) ).not.toBeInTheDocument();
	} );

	it( 'drops the criterion entirely when switched back to Any', async () => {
		await waitFor( () => expect( screen.getByTestId( 'date-range-preset' ) ).toHaveValue( '30' ) );

		fireEvent.change( screen.getByTestId( 'date-range-preset' ), { target: { value: '' } } );
		fireEvent.click( screen.getByText( 'Save' ) );

		// An empty `{}` would survive as a criterion and reach the matcher as "no
		// bounds to check", which matches every reader that has a date at all.
		expect( wizardApiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				method: 'POST',
				data: expect.objectContaining( {
					criteria: [ { criteria_id: 'newsletter', value: 'subscribers' } ],
				} ),
			} )
		);
	} );
} );
