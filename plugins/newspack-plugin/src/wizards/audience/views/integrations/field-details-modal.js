/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Badge, Grid, Modal } from '../../../../../packages/components/src';

const SYNC_TYPE_LABELS = {
	field: __( 'Synced as a contact field', 'newspack-plugin' ),
	tag: __( 'Synced as a tag', 'newspack-plugin' ),
};

const VersionCard = ( { definition, isActive, isPicker, isPickable, onPick } ) => (
	<div className={ `newspack-field-version-card${ isActive ? ' is-active' : '' }` }>
		<div className="newspack-field-version-card__header">
			{ isPicker && <Badge text={ definition.version } level={ isActive ? 'info' : 'default' } /> }
			{ 'new' === definition.status && <Badge text={ __( 'New', 'newspack-plugin' ) } level="success" /> }
		</div>
		{ definition.description && <p>{ definition.description }</p> }
		{ definition.example && (
			<p className="newspack-field-version-card__example">
				{ sprintf(
					/* translators: %s: example value for the field. */
					__( 'Example: %s', 'newspack-plugin' ),
					definition.example
				) }
			</p>
		) }
		{ SYNC_TYPE_LABELS[ definition.sync_type ] && <p>{ SYNC_TYPE_LABELS[ definition.sync_type ] }</p> }
		{ isPicker && (
			<>
				<Button variant={ isActive ? 'secondary' : 'primary' } disabled={ isActive || ! isPickable } onClick={ onPick }>
					{ sprintf(
						/* translators: %s: schema version (v1 or v2). */
						__( 'Use %s', 'newspack-plugin' ),
						definition.version
					) }
				</Button>
				{ ! isPickable && (
					<p className="newspack-field-version-card__unavailable">
						{ __( "Not available with the site's current configuration.", 'newspack-plugin' ) }
					</p>
				) }
			</>
		) }
	</div>
);

/**
 * Per-field details: full description, example value and sync behavior; on
 * conflict rows the v1/v2 cards double as the version picker.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.row           Row from buildFieldRows.
 * @param {Function} props.onPickVersion Called with 'v1'|'v2' when a card is picked.
 * @param {Function} props.onClose       Close handler.
 */
const FieldDetailsModal = ( { row, onPickVersion, onClose } ) => {
	const versions = [ 'v1', 'v2' ].filter( v => row.candidates[ v ]?.length );
	const cards = row.conflict ? versions.map( v => row.candidates[ v ][ 0 ] ) : [ row.activeDefinition ];
	return (
		<Modal title={ row.name } onRequestClose={ onClose } size={ row.conflict ? 'large' : 'medium' }>
			{ row.conflict && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Both schema versions write to this field name. Switching versions changes the format of the values sent — segments and automations that reference the current values may need updating.',
						'newspack-plugin'
					) }
				</Notice>
			) }
			<Grid columns={ cards.length } gutter={ 16 } noMargin>
				{ cards.map( definition => (
					<VersionCard
						key={ definition.id }
						definition={ definition }
						isActive={ definition.version === row.activeVersion && row.checked }
						isPicker={ row.conflict }
						isPickable={ row.availableVersions?.[ definition.version ] !== false }
						onPick={ () => onPickVersion( definition.version ) }
					/>
				) ) }
			</Grid>
			{ row.supersededHint && (
				<p className="newspack-field-details__superseded">
					{ sprintf(
						/* translators: %s: name of the replacing field. */
						__( 'Superseded by %s.', 'newspack-plugin' ),
						row.supersededHint
					) }
				</p>
			) }
		</Modal>
	);
};

export default FieldDetailsModal;
