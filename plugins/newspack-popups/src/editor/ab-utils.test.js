/**
 * Pure helpers behind the A/B Test panel. The panel's own tests stub the editor
 * stores; these run the logic the panel delegates to with no stubs at all.
 */
import { suggestGoal, isEligiblePlacement, splitSummary, promptLacksGoalBlock, missingGoalBlockMessage, testPromptWarnings } from './ab-utils';

describe( 'suggestGoal', () => {
	it.each( [
		// The Checkout Button also counts as a form, so the more specific goal has to win.
		[ [ 'newspack-blocks/checkout-button', 'newspack/reader-registration' ], '', 'subscription', 'Checkout Button' ],
		[ [ 'core/paragraph', 'newspack-blocks/donate' ], '', 'donation', 'Donate block' ],
		[ [ 'newspack-newsletters/subscribe' ], '', 'form_submission', 'sign-up form' ],
		[ [ 'core/buttons', 'core/button' ], '', 'link_click', 'link' ],
		[ [ 'core/paragraph' ], '<p>Read <a href="https://example.test/">more</a></p>', 'link_click', 'link' ],
	] )( 'suggests from %p', ( blockNames, content, goal, source ) => {
		const suggestion = suggestGoal( blockNames, content );
		expect( suggestion.goal ).toBe( goal );
		expect( suggestion.help ).toContain( `Suggested from the ${ source } in this prompt.` );
	} );

	it( 'suggests nothing for a prompt with no way to act', () => {
		expect( suggestGoal( [ 'core/paragraph' ], '<p>Hello</p>' ) ).toBeNull();
	} );
} );

it( 'treats manual and custom placements as ineligible', () => {
	expect( isEligiblePlacement( 'manual', [ 'manual', 'custom1' ] ) ).toBe( false );
	expect( isEligiblePlacement( 'custom1', [ 'manual', 'custom1' ] ) ).toBe( false );
	expect( isEligiblePlacement( 'inline', [ 'manual', 'custom1' ] ) ).toBe( true );
	expect( isEligiblePlacement( 'center', [ 'manual', 'custom1' ], true ) ).toBe( false );
} );

it( 'summarizes the split from the control share', () => {
	expect( splitSummary( 60 ) ).toBe( '60% see A, 40% see B' );
} );

it( 'flags a missing goal block only for goals with a known block', () => {
	expect( promptLacksGoalBlock( 'donation', [ 'core/paragraph' ] ) ).toBe( true );
	expect( promptLacksGoalBlock( 'donation', [ 'core/paragraph', 'newspack-blocks/donate' ] ) ).toBe( false );
	expect( promptLacksGoalBlock( 'link_click', [ 'core/paragraph' ] ) ).toBe( false );
	expect( promptLacksGoalBlock( '', [] ) ).toBe( false );
} );

it( 'names the block a goal needs', () => {
	expect( missingGoalBlockMessage( 'donation' ) ).toContain( 'Donate block' );
	expect( missingGoalBlockMessage( 'subscription' ) ).toContain( 'Checkout Button block' );
	expect( missingGoalBlockMessage( 'form_submission' ) ).toContain( "can't fill in a form" );
	expect( missingGoalBlockMessage( 'donation' ) ).toContain( 'choose another goal' );
	expect( missingGoalBlockMessage( 'donation', true ) ).toContain( 'change the goal on version A' );
} );

it( 'warns when another prompt holds the same variant', () => {
	const prompts = [
		{ id: 1, variant: 'a' },
		{ id: 2, variant: 'b' },
	];
	expect( testPromptWarnings( { postId: 2, variant: 'a', prompts } ) ).toHaveLength( 1 );
	expect( testPromptWarnings( { postId: 2, variant: 'b', prompts } ) ).toHaveLength( 0 );
	// A variant this editor doesn't offer can still be stored elsewhere; the message names it.
	expect( testPromptWarnings( { postId: 2, variant: 'c', prompts: [ { id: 3, variant: 'c' } ] } ) ).toEqual( [
		'Another prompt in this test is already version C. Unpublish one of them.',
	] );
} );
