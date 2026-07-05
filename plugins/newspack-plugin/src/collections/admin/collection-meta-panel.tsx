/**
 * Collection meta fields functionality.
 */

import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { TextControl, SelectControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useCallback } from '@wordpress/element';
import { store as editorStore } from '@wordpress/editor';
import { domReady } from '../../utils';

import CollectionMetaCtasField from './collection-meta-ctas-field';
import { isValidUrl } from './utils';
import './collection-meta-panel.scss';

const CollectionMetaPanel = ( { postType, metaDefinitions, panelTitle } ) => {
	const [ fieldErrors, setFieldErrors ] = useState( {} );
	const { editPost } = useDispatch( editorStore );

	// Get the current post type and meta data.
	const { currentPostType, meta = {} } = useSelect( select => {
		const editor = select( editorStore );
		return {
			currentPostType: editor.getCurrentPostType(),
			meta: editor.getEditedPostAttribute( 'meta' ) || {},
		};
	}, [] );

	// Update the meta data.
	const updateMeta = useCallback(
		( key, value ) => {
			editPost( { meta: { [ key ]: value } } );
		},
		[ editPost ]
	);

	// Remove an error for a specific field.
	const removeFieldError = useCallback( key => {
		setFieldErrors( prev => Object.fromEntries( Object.entries( prev ).filter( ( [ k ] ) => k !== key ) ) );
	}, [] );

	// Handle the fields blur event.
	const handleMetaBlur = useCallback(
		( key, value, type ) => {
			if ( ! value ) {
				updateMeta( key, null );
				removeFieldError( key );
				return;
			}

			if ( type === 'url' ) {
				setFieldErrors( prev => {
					if ( ! value || isValidUrl( value ) ) {
						removeFieldError( key );
						return prev;
					}
					return {
						...prev,
						[ key ]: __( 'Please enter a valid URL.', 'newspack-plugin' ),
					};
				} );
			}
		},
		[ updateMeta, removeFieldError ]
	);

	return (
		// Only render the panel if the post type matches the current post type.
		postType === currentPostType && (
			<PluginDocumentSettingPanel
				name="newspack-collections-meta-panel"
				title={ panelTitle }
				className="newspack-collections-meta-panel"
				icon="media-document"
			>
				<div className="collection-meta-fields">
					{ Object.entries( metaDefinitions ).map( ( [ name, def ] ) => {
						if ( 'ctas' === name ) {
							return (
								<CollectionMetaCtasField
									key={ def.key }
									metaKey={ def.key }
									label={ def.label }
									help={ def.help }
									meta={ meta }
									updateMeta={ updateMeta }
								/>
							);
						}

						if ( def.field_type === 'select' && def.options ) {
							return (
								<SelectControl
									key={ def.key }
									label={ def.label }
									help={ def.help }
									value={ meta[ def.key ] || def.default || '' }
									options={ def.options }
									onChange={ value => updateMeta( def.key, value ) }
								/>
							);
						}

						const hasError = !! fieldErrors[ def.key ];
						return (
							<TextControl
								key={ def.key }
								label={ def.label }
								help={ fieldErrors[ def.key ] || def.help }
								value={ meta[ def.key ] || '' }
								type={ def.type }
								onChange={ value => updateMeta( def.key, value ) }
								onBlur={ event => handleMetaBlur( def.key, event.target.value, def.type ) }
								className={ hasError ? 'meta-field-error' : '' }
							/>
						);
					} ) }
				</div>
			</PluginDocumentSettingPanel>
		)
	);
};

// Register the plugin if the collection meta definitions are available.
domReady( () => {
	const { collectionPostType: props } = window.newspackCollections || {};

	if ( props?.postType && props?.metaDefinitions && props?.panelTitle ) {
		registerPlugin( 'newspack-collection-meta-panel', {
			render: () => <CollectionMetaPanel { ...props } />,
			icon: 'media-document',
		} );
	}
} );
