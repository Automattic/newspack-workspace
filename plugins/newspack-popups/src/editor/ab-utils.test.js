/**
 * Pure helpers behind the A/B Test panel. The panel's own tests stub the editor
 * stores; these run the logic the panel delegates to with no stubs at all.
 */
import { slugifyTestId, isEligiblePlacement, splitSummary, promptLacksGoalBlock, testPromptWarnings } from './ab-utils';

describe( 'slugifyTestId', () => {
	// Expected values are what the server's sanitize_test_id() stores for the same
	// input, so a Test ID typed in the editor matches the stored one.
	it.each( [
		[ 'Spring Drive 2026', 'spring-drive-2026' ],
		[ '  footer_test  ', 'footer-test' ],
		[ 'Test--ID!!', 'test-id' ],
		[ 'already-a-slug', 'already-a-slug' ],
		// The PHP test's own input: the server turns periods and slashes into hyphens.
		[ 'Footer_Test v1.2', 'footer-test-v1-2' ],
		[ 'a/b', 'a-b' ],
	] )( 'turns %p into %p', ( input, expected ) => expect( slugifyTestId( input ) ).toBe( expected ) );
} );

it( 'treats manual and custom placements as ineligible', () => {
	expect( isEligiblePlacement( 'manual', [ 'manual', 'custom1' ] ) ).toBe( false );
	expect( isEligiblePlacement( 'custom1', [ 'manual', 'custom1' ] ) ).toBe( false );
	expect( isEligiblePlacement( 'inline', [ 'manual', 'custom1' ] ) ).toBe( true );
} );

it( 'summarizes the split from the control share', () => {
	expect( splitSummary( 60 ) ).toBe( '60% control, 40% challenger' );
} );

it( 'flags a missing goal block only for goals with a known block', () => {
	expect( promptLacksGoalBlock( 'donation', [ 'core/paragraph' ] ) ).toBe( true );
	expect( promptLacksGoalBlock( 'donation', [ 'core/paragraph', 'newspack-blocks/donate' ] ) ).toBe( false );
	expect( promptLacksGoalBlock( 'link_click', [ 'core/paragraph' ] ) ).toBe( false );
	expect( promptLacksGoalBlock( '', [] ) ).toBe( false );
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
		'Another prompt is already variant C of this test.',
	] );
} );
