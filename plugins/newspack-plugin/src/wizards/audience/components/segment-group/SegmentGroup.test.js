/**
 * External dependencies
 */
import React from 'react';
import { render } from '@testing-library/react';

/**
 * Internal dependencies
 */
import SegmentGroup from './index';

// Mock component props.
const CAMPAIGN = {
	campaignData: null,
	campaignId: undefined,
};
const SEGMENT = {
	label: 'Donors',
	configuration: {
		min_posts: 0,
		max_posts: 0,
		min_session_posts: 0,
		max_session_posts: 0,
		is_subscribed: false,
		is_not_subscribed: false,
		is_donor: true,
		is_not_donor: false,
		is_former_donor: false,
		favorite_categories: [],
		referrers: '',
	},
	id: 1001,
	created_at: '2020-11-02',
	updated_at: '2020-11-02',
	priority: 0,
};
const PROMPT_DEFAULTS = {
	tags: [],
	categories: [],
	campaign_groups: [],
	status: 'publish',
};
const PROMPTS = {
	overlaysUncategorized: [
		{
			...PROMPT_DEFAULTS,
			content: 'Overlay Prompt 1',
			id: 1,
			segments: [
				{
					id: SEGMENT.id,
					name: SEGMENT.label,
				},
			],
			options: {
				placement: 'center',
				frequency: 'daily',
			},
			title: 'Overlay Prompt 1',
		},
		{
			...PROMPT_DEFAULTS,
			content: 'Overlay Prompt 2',
			id: 2,
			segments: [
				{
					id: SEGMENT.id,
					name: SEGMENT.label,
				},
			],
			options: {
				placement: 'center',
				frequency: 'daily',
			},
			title: 'Overlay Prompt 2',
		},
	],
	overlaysCategorized: [
		{
			...PROMPT_DEFAULTS,
			categories: [
				{
					name: 'Tech',
					term_id: 1,
				},
			],
			content: 'Overlay Prompt 3 with category',
			id: 3,
			segments: [
				{
					id: SEGMENT.id,
					name: SEGMENT.label,
				},
			],
			options: {
				placement: 'center',
				frequency: 'daily',
			},
			title: 'Overlay Prompt 3 with category',
		},
		{
			...PROMPT_DEFAULTS,
			categories: [
				{
					name: 'Tech',
					term_id: 1,
				},
				{
					name: 'News',
					term_id: 2,
				},
			],
			content: 'Overlay Prompt 4 with category',
			id: 4,
			segments: [
				{
					id: SEGMENT.id,
					name: SEGMENT.label,
				},
			],
			options: {
				placement: 'center',
				frequency: 'daily',
			},
			title: 'Overlay Prompt 4 with category',
		},
	],
	aboveHeadersUncategorized: [
		{
			...PROMPT_DEFAULTS,
			content: 'Above Header Prompt 1',
			id: 5,
			segments: [
				{
					id: SEGMENT.id,
					name: SEGMENT.label,
				},
			],
			options: {
				placement: 'above_header',
				frequency: 'always',
			},
			title: 'Above Header Prompt 1',
		},
		{
			...PROMPT_DEFAULTS,
			content: 'Above Header Prompt 2',
			id: 6,
			segments: [
				{
					id: SEGMENT.id,
					name: SEGMENT.label,
				},
			],
			options: {
				placement: 'above_header',
				frequency: 'always',
			},
			title: 'Above Header Prompt 2',
		},
	],
	aboveHeadersCategorized: [
		{
			...PROMPT_DEFAULTS,
			categories: [
				{
					name: 'Tech',
					term_id: 1,
				},
			],
			content: 'Above Header Prompt 3 with category',
			id: 7,
			segments: [
				{
					id: SEGMENT.id,
					name: SEGMENT.label,
				},
			],
			options: {
				placement: 'above_header',
				frequency: 'always',
			},
			title: 'Above Header Prompt 3 with category',
		},
		{
			...PROMPT_DEFAULTS,
			categories: [
				{
					name: 'Tech',
					term_id: 1,
				},
				{
					name: 'News',
					term_id: 2,
				},
			],
			content: 'Above Header Prompt 4 with category',
			id: 8,
			segments: [
				{
					id: SEGMENT.id,
					name: SEGMENT.label,
				},
			],
			options: {
				placement: 'above_header',
				frequency: 'always',
			},
			title: 'Above Header Prompt 4 with category',
		},
	],
	customPlacementsUncategorized: [
		{
			...PROMPT_DEFAULTS,
			content: 'Custom Placement Prompt 1',
			id: 9,
			segments: [
				{
					id: SEGMENT.id,
					name: SEGMENT.label,
				},
			],
			options: {
				placement: 'custom1',
				frequency: 'always',
			},
			status: 'publish',
			title: 'Custom Placement Prompt 1',
		},
		{
			...PROMPT_DEFAULTS,
			content: 'Custom Placement Prompt 2',
			id: 10,
			segments: [
				{
					id: SEGMENT.id,
					name: SEGMENT.label,
				},
			],
			options: {
				placement: 'custom1',
				frequency: 'always',
			},
			title: 'Custom Placement Prompt 2',
		},
	],
	customPlacementsCategorized: [
		{
			...PROMPT_DEFAULTS,
			categories: [
				{
					name: 'Tech',
					term_id: 1,
				},
			],
			content: 'Custom Placement Prompt 3 with category',
			id: 11,
			segments: [
				{
					id: SEGMENT.id,
					name: SEGMENT.label,
				},
			],
			options: {
				placement: 'custom1',
				frequency: 'always',
			},
			title: 'Custom Placement Prompt 3 with category',
		},
		{
			...PROMPT_DEFAULTS,
			categories: [
				{
					name: 'Tech',
					term_id: 1,
				},
				{
					name: 'News',
					term_id: 2,
				},
			],
			content: 'Custom Placement Prompt 4 with category',
			id: 12,
			segments: [
				{
					id: SEGMENT.id,
					name: SEGMENT.label,
				},
			],
			options: {
				placement: 'custom1',
				frequency: 'always',
			},
			title: 'Custom Placement Prompt 4 with category',
		},
	],
};

describe( 'A segment with conflicting prompts', () => {
	beforeEach( () => {
		// Mock global vars for custom placements.
		window.newspackAudienceCampaigns = {
			custom_placements: {
				custom1: 'Custom Placement 1',
			},
			overlay_placements: [ 'center' ],
		};
	} );

	it( 'renders a conflict notice for mutliple overlays in the same segment', async () => {
		SEGMENT.prompts = PROMPTS.overlaysUncategorized;

		// Expected warning text.
		const noticeText = 'If multiple overlays are rendered on the same pageview, only the most recent one will be displayed.';
		const props = {
			campaignData: CAMPAIGN.campaignData,
			campaign: CAMPAIGN.campaignId,
			segment: SEGMENT,
		};
		const { getByTestId } = render( <SegmentGroup { ...props } /> );
		const notice1 = getByTestId( 'conflict-warning-1' );
		const notice2 = getByTestId( 'conflict-warning-2' );

		// Notice text has expected shape.
		expect( notice1.textContent ).toEqual( `${ PROMPTS.overlaysUncategorized[ 1 ].title }: ${ noticeText }` );
		expect( notice2.textContent ).toEqual( `${ PROMPTS.overlaysUncategorized[ 0 ].title }: ${ noticeText }` );
	} );

	it( 'decodes HTML entities in conflicting prompt titles', async () => {
		SEGMENT.prompts = [
			{ ...PROMPTS.overlaysUncategorized[ 0 ], id: 13, title: 'Donate &amp; Subscribe' },
			{ ...PROMPTS.overlaysUncategorized[ 1 ], id: 14, title: 'Tom &amp; Jerry' },
		];

		// Expected warning text.
		const noticeText = 'If multiple overlays are rendered on the same pageview, only the most recent one will be displayed.';
		const props = {
			campaignData: CAMPAIGN.campaignData,
			campaign: CAMPAIGN.campaignId,
			segment: SEGMENT,
		};
		const { getByTestId } = render( <SegmentGroup { ...props } /> );

		// Each notice lists the *other* prompt's title, decoded.
		expect( getByTestId( 'conflict-warning-13' ).textContent ).toEqual( `Tom & Jerry: ${ noticeText }` );
		expect( getByTestId( 'conflict-warning-14' ).textContent ).toEqual( `Donate & Subscribe: ${ noticeText }` );
	} );

	it( 'renders a conflict notice for multiple above-header prompts in the same segment', async () => {
		SEGMENT.prompts = PROMPTS.aboveHeadersUncategorized;

		// Expected warning text.
		const noticeText = 'If multiple above-header prompts are rendered on the same pageview, only the most recent one will be displayed.';
		const props = {
			campaignData: CAMPAIGN.campaignData,
			campaign: CAMPAIGN.campaignId,
			segment: SEGMENT,
		};
		const { getByTestId } = render( <SegmentGroup { ...props } /> );
		const notice1 = getByTestId( 'conflict-warning-5' );
		const notice2 = getByTestId( 'conflict-warning-6' );

		// Notice text has expected shape.
		expect( notice1.textContent ).toEqual( `${ PROMPTS.aboveHeadersUncategorized[ 1 ].title }: ${ noticeText }` );
		expect( notice2.textContent ).toEqual( `${ PROMPTS.aboveHeadersUncategorized[ 0 ].title }: ${ noticeText }` );
	} );

	it( 'renders a conflict notice for multiple prompts in the same custom placement and segment', async () => {
		SEGMENT.prompts = PROMPTS.customPlacementsUncategorized;

		// Expected warning text.
		const noticeText = 'If multiple prompts are rendered in the same custom placement, only the most recent one will be displayed.';
		const props = {
			campaignData: CAMPAIGN.campaignData,
			campaign: CAMPAIGN.campaignId,
			segment: SEGMENT,
		};
		const { getByTestId } = render( <SegmentGroup { ...props } /> );
		const notice1 = getByTestId( 'conflict-warning-9' );
		const notice2 = getByTestId( 'conflict-warning-10' );

		// Notice text has expected shape.
		expect( notice1.textContent ).toEqual( `${ PROMPTS.customPlacementsUncategorized[ 1 ].title }: ${ noticeText }` );
		expect( notice2.textContent ).toEqual( `${ PROMPTS.customPlacementsUncategorized[ 0 ].title }: ${ noticeText }` );
	} );

	it( 'renders a conflict notice for conflicting prompts that have no segment', async () => {
		const everyone = {
			configuration: {},
			id: '',
			label: 'Everyone',
			prompts: [ ...PROMPTS.overlaysUncategorized ],
		};

		// Unset selected segment ids.
		everyone.prompts.forEach( prompt => ( prompt.segments = [] ) );

		// Expected warning text.
		const noticeText = 'If multiple overlays are rendered on the same pageview, only the most recent one will be displayed.';
		const props = {
			campaignData: CAMPAIGN.campaignData,
			campaign: CAMPAIGN.campaignId,
			segment: everyone,
		};
		const { getByTestId } = render( <SegmentGroup { ...props } /> );
		const notice1 = getByTestId( 'conflict-warning-1' );
		const notice2 = getByTestId( 'conflict-warning-2' );

		// Notice text has expected shape.
		expect( notice1.textContent ).toEqual( `${ PROMPTS.overlaysUncategorized[ 1 ].title }: ${ noticeText }` );
		expect( notice2.textContent ).toEqual( `${ PROMPTS.overlaysUncategorized[ 0 ].title }: ${ noticeText }` );
	} );
} );
