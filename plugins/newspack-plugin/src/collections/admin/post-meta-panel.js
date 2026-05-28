import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { TextControl, ToggleControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useCallback } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

const PostMetaPanel = ( { panelTitle, metaDefinitions } ) => {
	const { editPost } = useDispatch( editorStore );

	// Get the current post type and meta data.
	const { currentPostType, meta = {} } = useSelect( select => {
		const editor = select( editorStore );
		return {
			currentPostType: editor.getCurrentPostType(),
			meta: editor.getEditedPostAttribute( 'meta' ) || {},
		};
	}, [] );

	// Update meta data.
	const updateMeta = useCallback(
		( key, value, type ) => {
			let sanitized = value;

			if ( type === 'integer' ) {
				sanitized = value === '' ? '' : parseInt( value, 10 ) || 0;
			} else if ( type === 'boolean' ) {
				sanitized = !! value;
			}

			editPost( { meta: { [ key ]: sanitized } } );
		},
		[ editPost ]
	);

	// Render control based on type.
	const renderControl = ( fieldKey, field ) => {
		const { key, type, label, help } = field;

		if ( type === 'boolean' ) {
			return (
				<ToggleControl
					key={ fieldKey }
					label={ label }
					checked={ !! meta[ key ] }
					onChange={ value => updateMeta( key, value, type ) }
					help={ help }
				/>
			);
		} else if ( type === 'integer' ) {
			return (
				<TextControl
					key={ fieldKey }
					label={ label }
					type="number"
					value={ meta[ key ] || '' }
					onChange={ value => updateMeta( key, value, type ) }
					help={ help }
					min={ 0 }
				/>
			);
		}

		return null;
	};

	return (
		// Only render the panel for posts.
		'post' === currentPostType && (
			<PluginDocumentSettingPanel name="newspack-post-meta-panel" title={ panelTitle } icon="media-document">
				{ Object.entries( metaDefinitions ).map( ( [ fieldKey, field ] ) => renderControl( fieldKey, field ) ) }
			</PluginDocumentSettingPanel>
		)
	);
};

domReady( () => {
	const { postMeta: props } = window.newspackCollections || {};
	if ( props?.panelTitle && props?.metaDefinitions ) {
		registerPlugin( 'newspack-post-meta-panel', {
			render: () => <PostMetaPanel { ...props } />,
			icon: 'media-document',
		} );
	}
} );
