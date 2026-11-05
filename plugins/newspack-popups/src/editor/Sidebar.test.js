/**
 * The inline Placement select while a prompt is in an A/B test: placements a
 * test can't use are left out, except the current one, which stays visible but
 * can't be chosen again.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Sidebar from './Sidebar';

jest.mock( './PositionPlacementControl', () => () => null );
jest.mock( '@wordpress/components', () => ( {
	RadioControl: () => null,
	RangeControl: () => null,
	ToggleControl: () => null,
	CheckboxControl: () => null,
	SelectControl: ( { label, options = [], help } ) => (
		<div>
			<select aria-label={ label }>
				{ options.map( option => (
					<option key={ option.value } value={ option.value } disabled={ option.disabled }>
						{ option.label }
					</option>
				) ) }
			</select>
			{ help && <p>{ help }</p> }
		</div>
	),
} ) );

const options = () => Array.from( screen.getByLabelText( 'Placement' ).options ).map( option => [ option.value, option.disabled ] );

beforeEach( () => {
	window.newspack_popups_data = {
		custom_placements: { custom1: 'Custom Placement 1' },
		ab_testing: { enabled: true, ineligible_placements: [ 'manual', 'custom1' ] },
	};
} );

afterEach( () => {
	delete window.newspack_popups_data;
} );

it( 'leaves out manual and custom placements while the prompt is in a test, and says why', () => {
	render( <Sidebar placement="inline" newspack_popups_ab_test_id="spring-drive" onMetaFieldChange={ jest.fn() } /> );
	expect( options() ).toEqual( [
		[ 'inline', false ],
		[ 'archives', false ],
		[ 'above_header', false ],
	] );
	expect( screen.getByText( /Manual and custom placements aren’t available while this prompt is in an A\/B test\./ ) ).toBeTruthy();
} );

it( 'keeps an ineligible current placement listed but disabled', () => {
	render( <Sidebar placement="custom1" newspack_popups_ab_test_id="spring-drive" onMetaFieldChange={ jest.fn() } /> );
	expect( options() ).toContainEqual( [ 'custom1', true ] );
	expect( options() ).not.toContainEqual( [ 'manual', false ] );
	expect( options() ).not.toContainEqual( [ 'manual', true ] );
} );

it( 'offers every placement to a prompt that is not in a test', () => {
	render( <Sidebar placement="inline" newspack_popups_ab_test_id="" onMetaFieldChange={ jest.fn() } /> );
	expect( options().map( ( [ value ] ) => value ) ).toEqual( [ 'inline', 'archives', 'above_header', 'manual', 'custom1' ] );
} );
