import { render, fireEvent, screen } from '@testing-library/react';
import OutboundFields, { buildFieldRows, toggleRow, pickRowVersion, badgesForRow, visibleSections } from './outbound-fields';

const def = ( id, name, extra = {} ) => {
	const [ version, raw_key ] = id.split( ':' );
	return {
		id, version, raw_key, name,
		section: 'Test', available: true, dynamic_suffix: false,
		description: '', example: '', sync_type: 'field', status: 'existing',
		supersedes: null, superseded_by: [], in_conflict_group: false,
		...extra,
	};
};

const DEFS = [
	def( 'v1:account', 'Account', { in_conflict_group: true } ),
	def( 'v2:Account', 'Account', { in_conflict_group: true } ),
	def( 'v1:registration_page', 'Registration Page' ),
	def( 'v1:current_page_url', 'Registration Page' ),
	def( 'v1:registration_method', 'Registration Method', { superseded_by: [ 'v2:Registration_Strategy' ] } ),
	def( 'v2:Registration_Strategy', 'Registration Strategy', { status: 'new', supersedes: 'v1:registration_method' } ),
	def( 'neutral:woo_team', 'Team Name' ),
	def( 'v1:unavailable_thing', 'Unavailable Thing', { available: false } ),
];

describe( 'buildFieldRows', () => {
	it( 'merges conflict names into one row with the enabled version active', () => {
		const rows = buildFieldRows( DEFS, [ 'v2:Account' ], 'v1' );
		const account = rows.find( r => r.name === 'Account' );
		expect( account.conflict ).toBe( true );
		expect( account.activeVersion ).toBe( 'v2' );
		expect( account.checked ).toBe( true );
	} );

	it( 'defaults a conflict row to the origin version when disabled', () => {
		const rows = buildFieldRows( DEFS, [], 'v1' );
		expect( rows.find( r => r.name === 'Account' ).activeVersion ).toBe( 'v1' );
	} );

	it( 'groups same-version raw keys sharing a name into one row', () => {
		const rows = buildFieldRows( DEFS, [], 'v1' );
		const page = rows.find( r => r.name === 'Registration Page' );
		expect( page.ids.sort() ).toEqual( [ 'v1:current_page_url', 'v1:registration_page' ] );
	} );

	it( 'hides non-origin single-version fields unless enabled', () => {
		expect( buildFieldRows( DEFS, [], 'v1' ).find( r => r.name === 'Registration Strategy' ) ).toBeUndefined();
		expect(
			buildFieldRows( DEFS, [ 'v2:Registration_Strategy' ], 'v1' ).find( r => r.name === 'Registration Strategy' )?.checked
		).toBe( true );
	} );

	it( 'shows neutral fields on every origin and hides unavailable ones', () => {
		const rows = buildFieldRows( DEFS, [], 'v2' );
		expect( rows.find( r => r.name === 'Team Name' ) ).toBeDefined();
		expect( rows.find( r => r.name === 'Unavailable Thing' ) ).toBeUndefined();
	} );

	it( 'carries the superseded hint on visible legacy rows', () => {
		const rows = buildFieldRows( DEFS, [], 'v1' );
		expect( rows.find( r => r.name === 'Registration Method' ).supersededHint ).toBe( 'Registration Strategy' );
	} );

	it( 'falls back to the other version when the origin side of a conflict is unavailable', () => {
		const defs = [
			def( 'v1:last_payment', 'Last Payment', { in_conflict_group: true, available: false } ),
			def( 'v2:Last_Payment', 'Last Payment', { in_conflict_group: true } ),
		];
		const row = buildFieldRows( defs, [], 'v1' ).find( r => r.name === 'Last Payment' );
		expect( row ).toBeDefined();
		expect( row.activeVersion ).toBe( 'v2' );
	} );

	it( 'hides a conflict row only when both versions are unavailable', () => {
		const defs = [
			def( 'v1:last_payment', 'Last Payment', { in_conflict_group: true, available: false } ),
			def( 'v2:Last_Payment', 'Last Payment', { in_conflict_group: true, available: false } ),
		];
		expect( buildFieldRows( defs, [], 'v1' ).find( r => r.name === 'Last Payment' ) ).toBeUndefined();
	} );
} );

describe( 'toggleRow / pickRowVersion', () => {
	it( 'toggling a conflict row on enables all active-version ids; off removes both versions', () => {
		const rows = buildFieldRows( DEFS, [], 'v1' );
		const account = rows.find( r => r.name === 'Account' );
		const on = toggleRow( [], account, true );
		expect( on ).toEqual( [ 'v1:account' ] );
		const rows2 = buildFieldRows( DEFS, [ 'v2:Account' ], 'v1' );
		expect( toggleRow( [ 'v2:Account' ], rows2.find( r => r.name === 'Account' ), false ) ).toEqual( [] );
	} );

	it( 'picking a version swaps and enables', () => {
		const rows = buildFieldRows( DEFS, [ 'v1:account' ], 'v1' );
		const account = rows.find( r => r.name === 'Account' );
		expect( pickRowVersion( [ 'v1:account' ], account, 'v2' ) ).toEqual( [ 'v2:Account' ] );
		const rowsOff = buildFieldRows( DEFS, [], 'v1' );
		expect( pickRowVersion( [], rowsOff.find( r => r.name === 'Account' ), 'v2' ) ).toEqual( [ 'v2:Account' ] );
	} );

	it( 'toggle preserves unrelated ids', () => {
		const rows = buildFieldRows( DEFS, [ 'neutral:woo_team' ], 'v1' );
		const page = rows.find( r => r.name === 'Registration Page' );
		expect( toggleRow( [ 'neutral:woo_team' ], page, true ).sort() ).toEqual( [ 'neutral:woo_team', 'v1:current_page_url', 'v1:registration_page' ] );
	} );
} );

describe( 'badgesForRow', () => {
	it( 'labels new, legacy and conflict-version rows', () => {
		const v1Rows = buildFieldRows( DEFS, [ 'v2:Registration_Strategy' ], 'v1' );
		expect( badgesForRow( v1Rows.find( r => r.name === 'Registration Strategy' ), 'v1' ).map( b => b.text ) ).toContain( 'New' );
		expect( badgesForRow( v1Rows.find( r => r.name === 'Registration Method' ), 'v1' ).map( b => b.text ) ).toContain( 'Legacy' );
		expect( badgesForRow( v1Rows.find( r => r.name === 'Account' ), 'v1' ).map( b => b.text ) ).toContain( 'v1' );
	} );
} );

describe( 'visibleSections', () => {
	it( 'groups rows by section with empty sections labeled Additional', () => {
		const sections = visibleSections( buildFieldRows( [ ...DEFS, def( 'v1:extra', 'Extra', { section: '' } ) ], [], 'v1' ) );
		expect( sections.map( s => s.section ) ).toContain( 'Additional' );
		expect( sections.find( s => s.section === 'Test' ).rows.length ).toBeGreaterThan( 0 );
	} );
} );

describe( 'OutboundFields', () => {
	const field = { key: 'outgoing_metadata_fields', definitions: DEFS, value_ids: [], schema_origin: 'v1' };

	it( 'renders sections with checkboxes and posts ids on toggle', () => {
		const onChange = jest.fn();
		render( <OutboundFields field={ field } value={ [] } onChange={ onChange } /> );
		fireEvent.click( screen.getByRole( 'checkbox', { name: /^Account/ } ) );
		expect( onChange ).toHaveBeenCalledWith( [ 'v1:account' ] );
	} );

	it( 'opens the details modal from the row cog', () => {
		render( <OutboundFields field={ field } value={ [ 'v1:account' ] } onChange={ () => {} } /> );
		fireEvent.click( screen.getAllByRole( 'button', { name: /field details/i } )[ 0 ] );
		expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
	} );
} );
