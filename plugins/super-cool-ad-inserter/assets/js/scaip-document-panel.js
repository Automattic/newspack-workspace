( function ( wp ) {
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var el = wp.element.createElement;
	var registerPlugin = wp.plugins.registerPlugin;
	var domReady = wp.domReady;
	// PluginDocumentSettingPanel moved from wp.editPost to wp.editor in WP 6.6+.
	// Fall back to wp.editPost for older Gutenberg versions.
	var PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var CheckboxControl = wp.components.CheckboxControl;
	var useEntityProp = wp.coreData.useEntityProp;
	var useSelect = wp.data.useSelect;

	function ScaipDocumentPanel() {
		var postType = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );

		// Both hooks must be called unconditionally before any early return
		// (React Rules of Hooks). useEntityProp tolerates undefined postType.
		var entityProp = useEntityProp( 'postType', postType, 'meta' );
		var meta = entityProp[ 0 ] || {};
		var setMeta = entityProp[ 1 ];

		// The classic metabox is registered only on 'post'; mirror that here.
		if ( postType !== 'post' ) {
			return null;
		}

		var settings = window.scaipDocumentPanel || {};

		// Settings come from window.scaipDocumentPanel, localized in
		// scaip_enqueue_document_panel_assets() (inc/scaip-metaboxes.php).
		var description = sprintf(
			/* translators: 1: number of ads inserted, 2: blocks before first insertion, 3: blocks between insertions, 4: minimum paragraphs required. */
			__( 'By default, %1$s ads will be inserted in a post, beginning %2$s blocks after the beginning and every %3$s paragraphs after that. They will not appear if this post is shorter than %4$s paragraphs long.', 'scaip' ),
			settings.repetitions,
			settings.start,
			settings.period,
			settings.minimum_paragraphs
		);

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'scaip-document-panel',
				title: __( 'Super Cool Ad Inserter', 'scaip' ),
			},
			el( 'p', null, description ),
			el( CheckboxControl, {
				label: __( 'Prevent automatic addition of ads to this post.', 'scaip' ),
				checked: !! meta.scaip_prevent_shortcode_addition,
				onChange: function ( value ) {
					setMeta( { scaip_prevent_shortcode_addition: value } );
				},
			} )
		);
	}

	// Defer registration so this panel renders after panels registered
	// synchronously by other plugins, pushing it toward the bottom of the
	// document settings sidebar.
	domReady( function () {
		registerPlugin( 'scaip-document-panel', {
			render: ScaipDocumentPanel,
		} );
	} );
} )( window.wp );
