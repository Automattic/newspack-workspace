'use strict';

import { FormToggle } from '@wordpress/components';
import { withDispatch, withSelect } from '@wordpress/data';

import { registerPlugin } from '@wordpress/plugins';
import { PluginPostStatusInfo } from '@wordpress/edit-post';
import { compose } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';

/**
 * Post meta toggle controls.
 */
const PostStatusExtensions = ( { meta, postType, updateMetaValue } ) => {
	if ( ! meta ) {
		return null;
	}
	const { newspack_hide_page_title, newspack_show_share_buttons } = meta;
	const { hide_title = [], show_share_buttons = [] } = window.newspack_post_meta_post_types;
	const hideTitle = 0 <= hide_title.indexOf( postType );
	const showShareButtons = 0 <= show_share_buttons.indexOf( postType );

	if ( ! hideTitle && ! showShareButtons ) {
		return null;
	}

	return (
		<PluginPostStatusInfo className="newspack__post-meta-toggles">
			{ hideTitle && 'page' === postType && (
				<div>
					<label htmlFor="hide_page_title">{ __( 'Hide page title', 'newspack-theme' ) }</label>
					<FormToggle
						checked={ newspack_hide_page_title }
						onChange={ () => updateMetaValue( 'newspack_hide_page_title', ! newspack_hide_page_title ) }
						id="hide_page_title"
					/>
				</div>
			) }
			{ showShareButtons && 'page' === postType && (
				<div>
					<label htmlFor="newspack_show_share_buttons">{ __( 'Show Jetpack share buttons', 'newspack-theme' ) }</label>
					<FormToggle
						checked={ newspack_show_share_buttons }
						onChange={ () => updateMetaValue( 'newspack_show_share_buttons', ! newspack_show_share_buttons ) }
						id="hide_page_title"
					/>
				</div>
			) }
		</PluginPostStatusInfo>
	);
};

/**
 * Map state to props
 */
const mapStateToProps = select => {
	const { getCurrentPostType, getEditedPostAttribute } = select( 'core/editor' );
	return {
		meta: getEditedPostAttribute( 'meta' ),
		postType: getCurrentPostType(),
	};
};

const mapDispatchToProps = dispatch => {
	const { editPost } = dispatch( 'core/editor' );
	return {
		updateMetaValue: ( key, value ) => editPost( { meta: { [ key ]: value } } ),
	};
};

/**
 * Register plugins
 */
const postStatusSidebar = compose( [ withSelect( mapStateToProps ), withDispatch( mapDispatchToProps ) ] )( PostStatusExtensions );

registerPlugin( 'post-status-sidebar', { render: postStatusSidebar } );
