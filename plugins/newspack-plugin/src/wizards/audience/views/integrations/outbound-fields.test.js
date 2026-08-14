import { render, fireEvent, screen } from '@testing-library/react';
import OutboundFields, { buildFieldRows, toggleRow, badgesForRow, visibleSections } from './outbound-fields';

const def = ( id, name, extra = {} ) => {
	const [ version, raw_key ] = id.split( ':' );
	return {
		id,
		version,
		raw_key,
		name,
		section: 'Test',
		available: true,
		dynamic_suffix: false,
		description: '',
		example: '',

		status: 'existing',
		supersedes: null,
		superseded_by: [],
		...extra,
	};
};

const DEFS = [
	// One field under two spellings: both schemas carry the ESP name "Account",
	// which post-pivot can only mean a value-equivalent pair.
	def( 'v1:account', 'Account', { status: 'legacy' } ),
	def( 'v2:Account', 'Account' ),
	// Same-version siblings sharing one ESP name.
	def( 'v1:registration_page', 'Registration Page', { status: 'legacy' } ),
	def( 'v1:current_page_url', 'Registration Page', { status: 'legacy' } ),
	// A rename: the v2 field took its own ESP name, so these are two rows.
	def( 'v1:registration_method', 'Registration Method', { status: 'legacy', superseded_by: [ 'v2:Registration_Strategy' ] } ),
	def( 'v2:Registration_Strategy', 'Registration Strategy', { status: 'new', supersedes: 'v1:registration_method' } ),
	def( 'neutral:woo_team', 'Team Name' ),
	def( 'v1:unavailable_thing', 'Unavailable Thing', { available: false } ),
];

describe( 'buildFieldRows', () => {
	it( 'collapses a name carried by both schemas into one row under the v2 identity', () => {
		const account = buildFieldRows( DEFS, [] ).find( r => r.name === 'Account' );
		expect( account ).toBeDefined();
		expect( account.activeVersion ).toBe( 'v2' );
		expect( account.checked ).toBe( false );
		expect( toggleRow( [], account, true ) ).toEqual( [ 'v2:Account' ] );
	} );

	it( 'renders a stale v1-enabled pair checked under its v2 identity', () => {
		const row = buildFieldRows( DEFS, [ 'v1:account' ] ).find( r => r.name === 'Account' );
		expect( row.checked ).toBe( true );
		expect( row.activeVersion ).toBe( 'v2' );
		expect( toggleRow( [ 'v1:account' ], row, false ) ).toEqual( [] );
	} );

	it( 'falls back to the v1 side of a pair while v2 is unavailable', () => {
		const defs = [ def( 'v1:total_paid', 'Total Paid' ), def( 'v2:Total_Paid', 'Total Paid', { available: false } ) ];
		const row = buildFieldRows( defs, [] ).find( r => r.name === 'Total Paid' );
		expect( row ).toBeDefined();
		expect( row.activeVersion ).toBe( 'v1' );
		expect( row.ids ).toEqual( [ 'v1:total_paid' ] );
	} );

	it( 'hides a pair only when both versions are unavailable', () => {
		const defs = [ def( 'v1:total_paid', 'Total Paid', { available: false } ), def( 'v2:Total_Paid', 'Total Paid', { available: false } ) ];
		expect( buildFieldRows( defs, [] ).find( r => r.name === 'Total Paid' ) ).toBeUndefined();
	} );

	it( 'groups same-version raw keys sharing a name into one row', () => {
		// Enabled, since the pair is legacy and would otherwise be sunset out.
		const rows = buildFieldRows( DEFS, [ 'v1:registration_page' ] );
		const page = rows.find( r => r.name === 'Registration Page' );
		expect( page.ids.sort() ).toEqual( [ 'v1:current_page_url', 'v1:registration_page' ] );
	} );

	// The sunset rule is read off `status` alone, so it holds identically on
	// every site — there is no site-level schema left to consult.
	it( 'hides legacy fields unless enabled', () => {
		expect( buildFieldRows( DEFS, [] ).find( r => r.name === 'Registration Method' ) ).toBeUndefined();
		expect( buildFieldRows( DEFS, [ 'v1:registration_method' ] ).find( r => r.name === 'Registration Method' )?.checked ).toBe( true );
	} );

	// Sunsetting on the draft would pull the row — and its checkbox — out of
	// the list the moment it was unchecked, with no way to undo and the field
	// still enabled on the server until saved.
	it( 'keeps a stored legacy field listed after it is unchecked in the draft', () => {
		const saved = [ 'v1:registration_method' ];
		const row = buildFieldRows( DEFS, [], saved ).find( r => r.name === 'Registration Method' );
		expect( row ).toBeDefined();
		expect( row.checked ).toBe( false );
	} );

	// ...and it does sunset on the next load, once the removal is stored.
	it( 'sunsets a legacy field once the save removes it', () => {
		expect( buildFieldRows( DEFS, [], [] ).find( r => r.name === 'Registration Method' ) ).toBeUndefined();
	} );

	// Enabling a legacy field mid-draft lists it right away: the sunset rule
	// only ever hides, so a draft addition is visible before it is saved.
	it( 'lists a legacy field enabled in the draft but not yet saved', () => {
		const row = buildFieldRows( DEFS, [ 'v1:registration_method' ], [] ).find( r => r.name === 'Registration Method' );
		expect( row?.checked ).toBe( true );
	} );

	// The migration motion the pivot needs: every current field is offered
	// everywhere, including on sites that only ever synced the legacy schema.
	it( 'lists non-legacy fields whether or not they are enabled', () => {
		const rows = buildFieldRows( DEFS, [] );
		expect( rows.find( r => r.name === 'Registration Strategy' ) ).toBeDefined();
		expect( rows.find( r => r.name === 'Registration Strategy' ).checked ).toBe( false );
		expect( buildFieldRows( DEFS, [ 'v2:Registration_Strategy' ] ).find( r => r.name === 'Registration Strategy' ).checked ).toBe( true );
	} );

	// A collapsed pair lists under the v2 identity, whose status is not
	// 'legacy' — the legacy status on its v1 side never sunsets the row.
	it( 'lists a collapsed pair even though its legacy side is on the way out', () => {
		expect( buildFieldRows( DEFS, [] ).find( r => r.name === 'Account' ) ).toBeDefined();
	} );

	it( 'shows neutral fields and hides unavailable ones', () => {
		const rows = buildFieldRows( DEFS, [] );
		expect( rows.find( r => r.name === 'Team Name' ) ).toBeDefined();
		expect( rows.find( r => r.name === 'Unavailable Thing' ) ).toBeUndefined();
	} );

	it( 'orders legacy rows last within their section', () => {
		const defs = [
			def( 'v1:legacy_one', 'Legacy One', { status: 'legacy' } ),
			def( 'v2:New_One', 'New One', { status: 'new' } ),
			def( 'v1:legacy_two', 'Legacy Two', { status: 'legacy' } ),
			def( 'v2:New_Two', 'New Two', { status: 'new' } ),
		];
		const rows = buildFieldRows( defs, [ 'v1:legacy_one', 'v1:legacy_two' ] );
		expect( rows.map( r => r.name ) ).toEqual( [ 'New One', 'New Two', 'Legacy One', 'Legacy Two' ] );
	} );

	it( 'keeps section order and sorts sunset rows within each section', () => {
		const defs = [
			def( 'v1:first_legacy', 'First Legacy', { section: 'First', status: 'legacy' } ),
			def( 'v2:Second_New', 'Second New', { section: 'Second' } ),
			def( 'v2:First_New', 'First New', { section: 'First' } ),
		];
		const sections = visibleSections( buildFieldRows( defs, [ 'v1:first_legacy' ] ) );
		expect( sections.map( s => s.section ) ).toEqual( [ 'First', 'Second' ] );
		expect( sections[ 0 ].rows.map( r => r.name ) ).toEqual( [ 'First New', 'First Legacy' ] );
	} );

	it( 'carries the superseded hint on visible legacy rows', () => {
		const rows = buildFieldRows( DEFS, [ 'v1:registration_method' ] );
		expect( rows.find( r => r.name === 'Registration Method' ).supersededHint ).toBe( 'Registration Strategy' );
	} );
} );

describe( 'toggleRow', () => {
	it( 'toggling on enables the active-version ids; off removes every version', () => {
		const rows = buildFieldRows( DEFS, [] );
		const account = rows.find( r => r.name === 'Account' );
		expect( toggleRow( [], account, true ) ).toEqual( [ 'v2:Account' ] );
		const rows2 = buildFieldRows( DEFS, [ 'v2:Account' ] );
		expect(
			toggleRow(
				[ 'v2:Account' ],
				rows2.find( r => r.name === 'Account' ),
				false
			)
		).toEqual( [] );
	} );

	it( 'toggle preserves unrelated ids', () => {
		const rows = buildFieldRows( DEFS, [ 'neutral:woo_team', 'v1:registration_page' ] );
		const page = rows.find( r => r.name === 'Registration Page' );
		expect( toggleRow( [ 'neutral:woo_team' ], page, true ).sort() ).toEqual( [
			'neutral:woo_team',
			'v1:current_page_url',
			'v1:registration_page',
		] );
	} );
} );

describe( 'badgesForRow', () => {
	const badgeText = row => badgesForRow( row ).map( b => b.text );

	it( 'badges new and updated fields New; legacy fields carry no badge', () => {
		const rows = buildFieldRows( DEFS, [ 'v1:registration_method', 'v2:Registration_Strategy' ] );
		expect( badgeText( rows.find( r => r.name === 'Registration Strategy' ) ) ).toEqual( [ 'New' ] );
		expect( badgeText( rows.find( r => r.name === 'Registration Method' ) ) ).toEqual( [] );
		expect( badgeText( { activeDefinition: { status: 'updated' } } ) ).toEqual( [ 'New' ] );
	} );

	// A renamed field (own v2 ESP name, `supersedes` a v1 field, status
	// 'updated') mirrors the real Payment_Page -> Last Payment Page rename:
	// distinct names keep it a non-collapsed row, and its badge comes from
	// the full buildFieldRows -> badgesForRow pipeline, not a bare object.
	it( 'badges a renamed field New via the full row pipeline', () => {
		const defs = [
			def( 'v1:payment_page', 'Payment Page', { status: 'legacy', superseded_by: [ 'v2:Payment_Page' ] } ),
			def( 'v2:Payment_Page', 'Last Payment Page', { status: 'updated', supersedes: 'v1:payment_page' } ),
		];
		const row = buildFieldRows( defs, [ 'v2:Payment_Page' ] ).find( r => r.name === 'Last Payment Page' );
		expect( badgeText( row ) ).toEqual( [ 'New' ] );
	} );

	it( 'leaves existing fields unbadged, including the surviving side of a pair', () => {
		const rows = buildFieldRows( DEFS, [] );
		expect( badgeText( rows.find( r => r.name === 'Account' ) ) ).toEqual( [] );
		expect( badgeText( rows.find( r => r.name === 'Team Name' ) ) ).toEqual( [] );
	} );
} );

describe( 'visibleSections', () => {
	it( 'groups rows by section with empty sections labeled Additional', () => {
		const sections = visibleSections( buildFieldRows( [ ...DEFS, def( 'v1:extra', 'Extra', { section: '' } ) ], [] ) );
		expect( sections.map( s => s.section ) ).toContain( 'Additional' );
		expect( sections.find( s => s.section === 'Test' ).rows.length ).toBeGreaterThan( 0 );
	} );

	it( 'sorts an all-legacy-status section last, after Additional, regardless of its label', () => {
		const defs = [
			// Named "Vintage", not "Legacy" — the ordering must key on each row's
			// own status, not on the section's translated label.
			def( 'v1:vintage_field', 'Vintage Field', { section: 'Vintage', status: 'legacy' } ),
			def( 'v2:new_field', 'New Field', { section: 'Current', status: 'new' } ),
			def( 'neutral:extra_field', 'Extra Field', { section: '' } ),
		];
		const sections = visibleSections( buildFieldRows( defs, [ 'v1:vintage_field' ] ) );
		expect( sections.map( s => s.section ) ).toEqual( [ 'Current', 'Additional', 'Vintage' ] );
	} );
} );

describe( 'OutboundFields', () => {
	const field = { key: 'outgoing_metadata_fields', definitions: DEFS, value_ids: [] };

	it( 'renders sections with checkboxes and posts ids on toggle', () => {
		const onChange = jest.fn();
		render( <OutboundFields field={ field } value={ [] } onChange={ onChange } /> );
		fireEvent.click( screen.getByRole( 'checkbox', { name: /^Account/ } ) );
		expect( onChange ).toHaveBeenCalledWith( [ 'v2:Account' ] );
	} );

	// Regression guard for the dropped version picker: no field ever needs a
	// version choice, so rows carry no per-field details control to open one.
	it( 'renders no per-row details control', () => {
		render( <OutboundFields field={ field } value={ [ 'v1:account' ] } onChange={ () => {} } /> );
		expect( screen.queryByRole( 'button', { name: /field details/i } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );
} );
