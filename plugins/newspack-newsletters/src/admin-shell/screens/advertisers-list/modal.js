/**
 * Add/Edit Advertiser Modal — single component, two modes.
 *
 * Posts directly to `/wp/v2/<taxonomy>`; slug collisions and the
 * parent-self guard surface inline. `TreeSelect` (not `SelectControl`)
 * for the parent picker — the latter doesn't render indentation.
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, Modal, Notice, TextControl, TextareaControl, TreeSelect } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const TAXONOMY_PATH = '/wp/v2/newspack_nl_advertiser';

/**
 * Build the indented `<TreeSelect>` tree from a flat advertiser list.
 *
 * `TreeSelect` expects `{ name, id, children }` recursively; the WP REST
 * term collection is flat with `parent` references. Filter out `excludeId`
 * and any of its descendants so the edit-mode dropdown can't pick a
 * descendant as its own parent (which would create a hierarchy loop —
 * the parent-self guard catches the trivial case, but a deeper cycle is
 * just as broken).
 *
 * @param {Array}   advertisers Flat list from the REST collection.
 * @param {?number} excludeId   Optional id to omit (and its descendants).
 * @return {Array}                Tree rooted at top-level (`parent === 0`).
 */
export function buildAdvertiserTree( advertisers, excludeId = null ) {
	const safe = Array.isArray( advertisers ) ? advertisers : [];

	// Pre-index by parent so the recursive walk is O(n) overall — a
	// `safe.filter(...)` per `buildChildren` call would scan the full
	// list for every node and turn the build into O(n²), which is
	// noticeable on sites with many advertisers (and especially since
	// the tree rebuilds on every modal re-render).
	const byParent = new Map();
	for ( const term of safe ) {
		const siblings = byParent.get( term.parent );
		if ( siblings ) {
			siblings.push( term );
		} else {
			byParent.set( term.parent, [ term ] );
		}
	}

	const excluded = new Set();
	if ( excludeId ) {
		// DFS through the parent index to mark `excludeId` and every
		// descendant — ensures the modal can't pick a sub-tree as its
		// own parent at any depth.
		excluded.add( excludeId );
		const stack = [ excludeId ];
		while ( stack.length > 0 ) {
			const current = stack.pop();
			const children = byParent.get( current ) || [];
			for ( const child of children ) {
				if ( ! excluded.has( child.id ) ) {
					excluded.add( child.id );
					stack.push( child.id );
				}
			}
		}
	}

	const buildChildren = parentId =>
		( byParent.get( parentId ) || [] )
			.filter( term => ! excluded.has( term.id ) )
			.map( term => ( {
				name: term.name,
				id: String( term.id ),
				children: buildChildren( term.id ),
			} ) );

	return buildChildren( 0 );
}

/**
 * @param {Object}   props
 * @param {?Object}  props.advertiser  Existing term to edit. Null/undefined for create.
 * @param {Array}    props.advertisers Full flat list from the REST collection.
 * @param {Function} props.onClose     Called when the user dismisses the modal.
 * @param {Function} props.onSaved     Called after a successful save (refresh the list).
 */
export default function AdvertiserModal( { advertiser = null, advertisers = [], onClose, onSaved } ) {
	const isEdit = Boolean( advertiser?.id );

	const [ name, setName ] = useState( advertiser?.name || '' );
	const [ description, setDescription ] = useState( advertiser?.description || '' );
	const [ slug, setSlug ] = useState( advertiser?.slug || '' );
	const [ parent, setParent ] = useState( advertiser?.parent ? String( advertiser.parent ) : '' );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	// Memoised so form-state changes (typing in Name / Description /
	// Slug) don't trigger an O(n) rebuild of the tree on every keystroke.
	// Recomputes only when the underlying advertiser collection or the
	// edit-mode exclusion target changes.
	const tree = useMemo( () => buildAdvertiserTree( advertisers, isEdit ? advertiser.id : null ), [ advertisers, isEdit, advertiser?.id ] );

	const submit = async event => {
		event.preventDefault();

		if ( ! name.trim() ) {
			setError( __( 'Name is required.', 'newspack-newsletters' ) );
			return;
		}

		setIsBusy( true );
		setError( '' );

		const path = isEdit ? `${ TAXONOMY_PATH }/${ advertiser.id }` : TAXONOMY_PATH;
		const data = {
			name: name.trim(),
			description,
			parent: parent ? parseInt( parent, 10 ) : 0,
		};
		// Slug forwarding rules:
		// - Create: omit `slug` when blank so `wp_insert_term` generates one.
		// - Edit:   only send `slug` when it changed from the existing value.
		//           Sending the original would be a no-op; clearing the field
		//           sends `''`, which `wp_update_term` regenerates from the
		//           name (matching the field's "Leave blank to auto-generate"
		//           help text). Not comparing would either always regenerate
		//           on edit (overwriting a deliberately-edited slug) or never
		//           regenerate (lying to the help text).
		const trimmedSlug = slug.trim();
		const originalSlug = advertiser?.slug || '';
		if ( ! isEdit ) {
			if ( trimmedSlug !== '' ) {
				data.slug = trimmedSlug;
			}
		} else if ( trimmedSlug !== originalSlug ) {
			data.slug = trimmedSlug;
		}

		try {
			await apiFetch( { path, method: 'POST', data } );
			onSaved();
			onClose();
		} catch ( err ) {
			// REST WP_Error responses come through with `{ code, message }`.
			// Fall back to a generic message so the modal never silently
			// swallows a failure.
			setError( err?.message || __( 'Failed to save advertiser. Please try again.', 'newspack-newsletters' ) );
			setIsBusy( false );
		}
	};

	return (
		<Modal
			title={ isEdit ? __( 'Edit advertiser', 'newspack-newsletters' ) : __( 'Add new advertiser', 'newspack-newsletters' ) }
			onRequestClose={ onClose }
			size="medium"
			className="newspack-newsletters-advertiser-modal"
		>
			<form onSubmit={ submit }>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<TextControl
					label={ __( 'Name', 'newspack-newsletters' ) }
					value={ name }
					onChange={ setName }
					required
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<TextareaControl
					label={ __( 'Description', 'newspack-newsletters' ) }
					help={ __( 'Optional description for this advertiser.', 'newspack-newsletters' ) }
					value={ description }
					onChange={ setDescription }
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Slug', 'newspack-newsletters' ) }
					help={ __( 'Leave blank to auto-generate from the name.', 'newspack-newsletters' ) }
					value={ slug }
					onChange={ setSlug }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<TreeSelect
					label={ __( 'Parent advertiser', 'newspack-newsletters' ) }
					noOptionLabel={ __( '— None —', 'newspack-newsletters' ) }
					tree={ tree }
					selectedId={ parent }
					onChange={ setParent }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<div style={ { display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '16px' } }>
					<Button variant="tertiary" onClick={ onClose } disabled={ isBusy }>
						{ __( 'Cancel', 'newspack-newsletters' ) }
					</Button>
					<Button variant="primary" type="submit" isBusy={ isBusy } disabled={ isBusy }>
						{ isEdit ? __( 'Save changes', 'newspack-newsletters' ) : __( 'Add advertiser', 'newspack-newsletters' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}
