/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';
import { useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Accordion, AccordionPanel, Badge, Grid } from '../../../../../packages/components/src';

const VERSIONS = [ 'v1', 'v2', 'neutral' ];

/**
 * Whether a definition is on its way out. The single input to both the sunset
 * visibility rule and sunset-last ordering, so a field's fate is only ever
 * `status` — no site-level schema is consulted.
 *
 * @param {Object} definition A definition from the settings payload.
 * @return {boolean} True for legacy definitions.
 */
const isSunset = definition => 'legacy' === definition?.status;

/**
 * Build UI rows from the merged definitions payload.
 *
 * One row per ESP field name, and never a version choice: the two schemas no
 * longer claim an ESP name in common (Field_Registry::get_conflict_groups() is
 * empty by construction, guarded by
 * Test_Field_Registry::test_no_esp_name_is_claimed_by_both_schemas), so a name
 * carrying both a v1 and a v2 definition is always a value-equivalent pair —
 * one field whose stored v1 ids the save path upgrades to the v2 twin. Those
 * collapse to a single row under the surviving identity.
 *
 * Visibility is the sunset rule, read off the active definition's `status`:
 * a legacy field lists only while enabled, so a site never picks up a new
 * dependency on a field that is on its way out, while a site already syncing
 * one keeps seeing (and can turn off) what it has. Everything else — new,
 * updated, existing, filter-added — always lists, on every site: there is no
 * per-site schema any more, so every current field has to be discoverable and
 * enableable everywhere. Unavailable definitions never list (matching the
 * pre-Phase-2 UI).
 *
 * @param {Object[]} definitions Definitions from the settings payload.
 * @param {string[]} enabledIds  Enabled field ids.
 * @return {Object[]} Ordered row objects.
 */
export const buildFieldRows = ( definitions, enabledIds ) => {
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
		const collapsed = candidates.v1.length > 0 && candidates.v2.length > 0;
		const enabledVersion = VERSIONS.find( v => candidates[ v ].some( d => enabled.has( d.id ) ) );
		const hasAvailable = v => candidates[ v ].some( d => d.available );
		// A collapsed pair renders under v2, its surviving spelling — falling
		// back to v1 only while v2 is unavailable. This overrides an enabled v1
		// id on purpose: a selection stored before the upgrade still means the
		// one field, so it shows checked under the v2 identity.
		let activeVersion = enabledVersion || present[ 0 ];
		if ( collapsed ) {
			activeVersion = hasAvailable( 'v2' ) || ! hasAvailable( 'v1' ) ? 'v2' : 'v1';
		}
		const active = candidates[ activeVersion ];
		if ( ! active.length || ! active.some( d => d.available ) ) {
			return;
		}
		const checked = Boolean( enabledVersion );
		const activeDefinition = active[ 0 ];
		if ( ! checked && isSunset( activeDefinition ) ) {
			return; // Sunset rule: legacy fields list only while enabled.
		}
		const supersededByDef = ( activeDefinition.superseded_by || [] ).map( id => ( definitions || [] ).find( d => d.id === id ) ).find( Boolean );
		rows.push( {
			key: activeDefinition.id,
			name,
			section: activeDefinition.section || __( 'Additional', 'newspack-plugin' ),
			activeVersion,
			activeDefinition,
			ids: active.map( d => d.id ),
			// Every version's ids, so unchecking a collapsed pair clears the
			// legacy side too.
			allIds: VERSIONS.flatMap( v => candidates[ v ].map( d => d.id ) ),
			checked,
			supersededHint: supersededByDef ? supersededByDef.name : null,
		} );
	} );
	// Sunset-last, scoped to each section: the rows a site should be adopting
	// come first, the ones it is keeping alive sink to the bottom of their own
	// section. Sorting on the section's first-appearance index leaves the
	// section order itself alone, and the sort is stable, so rows that tie keep
	// their definition order.
	const sectionOrder = new Map();
	rows.forEach( row => {
		if ( ! sectionOrder.has( row.section ) ) {
			sectionOrder.set( row.section, sectionOrder.size );
		}
	} );
	rows.sort(
		( a, b ) =>
			sectionOrder.get( a.section ) - sectionOrder.get( b.section ) ||
			Number( isSunset( a.activeDefinition ) ) - Number( isSunset( b.activeDefinition ) )
	);
	return rows;
};

/**
 * Group visible rows by section, preserving row order within each section.
 *
 * Sections made up entirely of sunset (legacy-status) rows sort after every
 * other section, including "Additional" — keyed on each row's own `status`
 * via `isSunset`, not the section's (translated) label, so this keeps working
 * regardless of what a legacy section is named or how it's translated.
 * Relative order is otherwise unchanged: within each partition, sections keep
 * the order they first appear in `rows`.
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
	const isLegacySection = section => section.rows.every( row => isSunset( row.activeDefinition ) );
	return [ ...sections.filter( section => ! isLegacySection( section ) ), ...sections.filter( isLegacySection ) ];
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
 * Badges for a row, read straight off the active definition's status: new and
 * updated fields arrived with the current schema. Legacy fields carry no
 * badge — they are surfaced by sorting into their own Legacy section instead,
 * last (see visibleSections). Everything else is unbadged.
 *
 * @param {Object} row Row from buildFieldRows.
 * @return {{text: string, level: string}[]} Badge descriptors.
 */
export const badgesForRow = row => {
	const status = row.activeDefinition.status;
	if ( 'new' === status || 'updated' === status ) {
		return [ { text: __( 'New', 'newspack-plugin' ), level: 'success' } ];
	}
	return [];
};

/**
 * The per-field Outbound selection list: one row per ESP field name with an
 * inline description and badges; posts ids via onChange.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.field    The outgoing_metadata_fields settings payload entry.
 * @param {string[]} props.value    Current enabled ids (draft or saved).
 * @param {Function} props.onChange Receives the next ids array.
 */
const OutboundFields = ( { field, value, onChange } ) => {
	const enabledIds = Array.isArray( value ) ? value : field.value_ids || [];
	const rows = useMemo( () => buildFieldRows( field.definitions, enabledIds ), [ field.definitions, enabledIds ] );
	const sections = useMemo( () => visibleSections( rows ), [ rows ] );
	return (
		<Accordion hideSingleTitle>
			{ sections.map( ( { section, rows: sectionRows }, index ) => (
				<AccordionPanel key={ section } title={ section } defaultOpen={ index === 0 }>
					<Grid columns={ 1 } rowGap={ 8 } noMargin>
						{ sectionRows.map( row => (
							<div className="newspack-outbound-field-row" key={ row.key }>
								<CheckboxControl
									className="newspack-outbound-field-row__checkbox"
									label={ row.name }
									help={
										[
											row.activeDefinition.description,
											row.supersededHint &&
												sprintf(
													/* translators: %s: name of the replacing field. */
													__( 'Superseded by %s.', 'newspack-plugin' ),
													row.supersededHint
												),
										]
											.filter( Boolean )
											.join( ' — ' ) || undefined
									}
									checked={ row.checked }
									onChange={ checked => onChange( toggleRow( enabledIds, row, checked ) ) }
								/>
								<span className="newspack-outbound-field-row__badges">
									{ badgesForRow( row ).map( badge => (
										<Badge key={ badge.text } text={ badge.text } level={ badge.level } />
									) ) }
								</span>
							</div>
						) ) }
					</Grid>
				</AccordionPanel>
			) ) }
		</Accordion>
	);
};

export default OutboundFields;
