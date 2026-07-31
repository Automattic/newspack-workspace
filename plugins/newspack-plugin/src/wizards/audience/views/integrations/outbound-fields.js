/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

const VERSIONS = [ 'v1', 'v2', 'neutral' ];

/**
 * Build UI rows from the merged definitions payload.
 *
 * One row per ESP field name. A name present in both v1 and v2 is a conflict
 * row: one checkbox, the modal's comparison cards pick the version. A
 * single-version name renders per the sunset rule: origin-version and neutral
 * fields always list; non-origin fields list only while enabled. Unavailable
 * definitions never list (matching the pre-Phase-2 UI).
 *
 * @param {Object[]} definitions Definitions from the settings payload.
 * @param {string[]} enabledIds  Enabled field ids.
 * @param {string}   origin      The site's schema origin ('v1'|'v2').
 * @return {Object[]} Ordered row objects.
 */
export const buildFieldRows = ( definitions, enabledIds, origin ) => {
	const enabled = new Set( enabledIds || [] );
	const byName = new Map();
	( definitions || [] ).forEach( d => {
		if ( ! byName.has( d.name ) ) {
			byName.set( d.name, { v1: [], v2: [], neutral: [] } );
		}
		byName.get( d.name )[ d.version ]?.push( d );
	} );

	const rows = [];
	byName.forEach( ( candidates, name ) => {
		const present = VERSIONS.filter( v => candidates[ v ].length );
		const conflict = candidates.v1.length > 0 && candidates.v2.length > 0;
		const enabledVersion = VERSIONS.find( v => candidates[ v ].some( d => enabled.has( d.id ) ) );
		const hasAvailable = v => candidates[ v ].some( d => d.available );
		let activeVersion = enabledVersion || ( conflict ? origin : present[ 0 ] );
		if ( ! enabledVersion && conflict && ! hasAvailable( activeVersion ) ) {
			const alternate = 'v1' === activeVersion ? 'v2' : 'v1';
			if ( hasAvailable( alternate ) ) {
				activeVersion = alternate;
			}
		}
		const active = candidates[ activeVersion ];
		if ( ! active.length || active.every( d => ! d.available ) ) {
			return;
		}
		const checked = Boolean( enabledVersion );
		if ( ! conflict && ! checked && 'neutral' !== activeVersion && activeVersion !== origin ) {
			return; // Sunset rule: non-origin fields list only while enabled.
		}
		const activeDefinition = active[ 0 ];
		const supersededByDef = ( activeDefinition.superseded_by || [] )
			.map( id => ( definitions || [] ).find( d => d.id === id ) )
			.find( Boolean );
		rows.push( {
			key: conflict ? `name:${ name }` : activeDefinition.id,
			name,
			section: activeDefinition.section || __( 'Additional', 'newspack-plugin' ),
			conflict,
			activeVersion,
			activeDefinition,
			candidates,
			ids: active.map( d => d.id ),
			allIds: VERSIONS.flatMap( v => candidates[ v ].map( d => d.id ) ),
			checked,
			supersededHint: supersededByDef ? supersededByDef.name : null,
			originOrder: activeVersion === origin || 'neutral' === activeVersion ? 0 : 1,
		} );
	} );
	rows.sort( ( a, b ) => a.originOrder - b.originOrder );
	return rows;
};

/**
 * Group visible rows by section, preserving row order.
 *
 * @param {Object[]} rows Rows from buildFieldRows.
 * @return {{section: string, rows: Object[]}[]} Ordered section groups.
 */
export const visibleSections = rows => {
	const sections = [];
	const index = new Map();
	( rows || [] ).forEach( row => {
		if ( ! index.has( row.section ) ) {
			index.set( row.section, sections.length );
			sections.push( { section: row.section, rows: [] } );
		}
		sections[ index.get( row.section ) ].rows.push( row );
	} );
	return sections;
};

/**
 * Toggle a row on (enable all active-version ids) or off (remove every
 * candidate id of every version).
 *
 * @param {string[]} enabledIds Current enabled ids.
 * @param {Object}   row        Row from buildFieldRows.
 * @param {boolean}  checked    Next checked state.
 * @return {string[]} Next enabled ids.
 */
export const toggleRow = ( enabledIds, row, checked ) => {
	const without = ( enabledIds || [] ).filter( id => ! row.allIds.includes( id ) );
	return checked ? [ ...without, ...row.ids ] : without;
};

/**
 * Pick a schema version for a conflict row. Always enables the chosen
 * version's ids (picking a card is an explicit enable).
 *
 * @param {string[]} enabledIds Current enabled ids.
 * @param {Object}   row        Row from buildFieldRows.
 * @param {string}   version    'v1' or 'v2'.
 * @return {string[]} Next enabled ids.
 */
export const pickRowVersion = ( enabledIds, row, version ) => {
	const without = ( enabledIds || [] ).filter( id => ! row.allIds.includes( id ) );
	return [ ...without, ...( row.candidates[ version ] || [] ).map( d => d.id ) ];
};

/**
 * Badges for a row: New (status), Legacy (v1 on the way out), and the active
 * version on conflict rows.
 *
 * @param {Object} row    Row from buildFieldRows.
 * @param {string} origin The site's schema origin.
 * @return {{text: string, level: string}[]} Badge descriptors.
 */
export const badgesForRow = ( row, origin ) => {
	const badges = [];
	if ( row.conflict ) {
		badges.push( { text: row.activeVersion, level: 'info' } );
	}
	if ( 'new' === row.activeDefinition.status ) {
		badges.push( { text: __( 'New', 'newspack-plugin' ), level: 'success' } );
	}
	if ( 'v1' === row.activeVersion && ( 'v2' === origin || ( row.activeDefinition.superseded_by || [] ).length ) ) {
		badges.push( { text: __( 'Legacy', 'newspack-plugin' ), level: 'default' } );
	}
	return badges;
};
