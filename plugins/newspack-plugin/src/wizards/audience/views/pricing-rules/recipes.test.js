/**
 * Unit tests for the pricing-path recipe map.
 */
import { RECIPES, LIFECYCLE_CONDITIONS, applyRecipeConditions, isConditionVisible, intentLabel } from './recipes';

describe( 'recipes', () => {
	it( 'save presets pending_cancellation + locked + all-subscriptions scope', () => {
		expect( RECIPES.save.lifecycleCondition ).toBe( 'pending_cancellation' );
		expect( RECIPES.save.application ).toBe( 'locked' );
		expect( RECIPES.save.defaultScope ).toBe( 'all_subscriptions' );
	} );

	it( 'retention has no lifecycle matcher and current application', () => {
		expect( RECIPES.retention.lifecycleCondition ).toBeNull();
		expect( RECIPES.retention.application ).toBe( 'current' );
	} );

	it( 'custom presets nothing and defaults to all-products scope', () => {
		expect( RECIPES.custom.lifecycleCondition ).toBeNull();
		expect( RECIPES.custom.application ).toBeNull();
		expect( RECIPES.custom.isCustom ).toBe( true );
		expect( RECIPES.custom.defaultScope ).toBe( 'all_products' );
	} );

	it( 'applyRecipeConditions clears all lifecycle matchers and sets the path one', () => {
		const start = { lapsed_subscriber: true, reader_segment: [ 5 ] };
		const next = applyRecipeConditions( 'save', start );
		expect( next.pending_cancellation ).toBe( true );
		expect( next.lapsed_subscriber ).toBeUndefined();
		expect( next.reader_segment ).toEqual( [ 5 ] );
	} );

	it( 'applyRecipeConditions for retention clears all lifecycle matchers and sets none', () => {
		const next = applyRecipeConditions( 'retention', { first_time_only: true } );
		LIFECYCLE_CONDITIONS.forEach( id => expect( next[ id ] ).toBeUndefined() );
	} );

	it( 'named paths show only select conditions; custom shows all', () => {
		expect( isConditionVisible( 'save', 'select' ) ).toBe( true );
		expect( isConditionVisible( 'save', 'boolean' ) ).toBe( false );
		expect( isConditionVisible( 'save', 'datetime' ) ).toBe( false );
		expect( isConditionVisible( 'custom', 'boolean' ) ).toBe( true );
	} );

	it( 'intentLabel maps known values and passes through unknown', () => {
		expect( intentLabel( 'winback' ) ).toBe( 'Win-back' );
		expect( intentLabel( 'mystery' ) ).toBe( 'mystery' );
	} );

	it( 'retention defaults the cycle anchor to rule application', () => {
		expect( RECIPES.retention.cycleAnchor ).toBe( 'rule_application' );
	} );

	it( 'non-retention recipes default the cycle anchor to subscription start', () => {
		expect( RECIPES.new_subscriptions.cycleAnchor ).toBe( 'subscription_start' );
		expect( RECIPES.save.cycleAnchor ).toBe( 'subscription_start' );
		expect( RECIPES.winback.cycleAnchor ).toBe( 'subscription_start' );
		expect( RECIPES.custom.cycleAnchor ).toBe( 'subscription_start' );
	} );
} );
