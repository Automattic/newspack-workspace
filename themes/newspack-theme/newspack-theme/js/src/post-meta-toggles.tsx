'use strict';

import { FormToggle } from '@wordpress/components';
import { withDispatch, withSelect } from '@wordpress/data';

import { registerPlugin } from '@wordpress/plugins';
import { PluginPostStatusInfo } from '@wordpress/edit-post';
import { compose } from '@wordpress/compose';
import { ComponentType } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

interface PostMeta {
	newspack_hide_page_title?: boolean;
	newspack_show_share_buttons?: boolean;
	[ key: string ]: unknown;
}

interface PostStatusExtensionsProps {
	meta?: PostMeta;
	postType: string;
	updateMetaValue: ( key: string, value: unknown ) => void;
}

/**
 * Post meta toggle controls.
 */
const PostStatusExtensions = ( { meta, postType, updateMetaValue }: PostStatusExtensionsProps ) => {
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
const mapStateToProps = ( select: ( store: string ) => unknown ) => {
	const { getCurrentPostType, getEditedPostAttribute } = select( 'core/editor' ) as {
		getCurrentPostType: () => string;
		getEditedPostAttribute: ( attribute: string ) => PostMeta | undefined;
	};
	return {
		meta: getEditedPostAttribute( 'meta' ),
		postType: getCurrentPostType(),
	};
};

const mapDispatchToProps = ( dispatch: ( store: string ) => unknown ) => {
	const { editPost } = dispatch( 'core/editor' ) as { editPost: ( edits: Record< string, unknown > ) => void };
	return {
		updateMetaValue: ( key: string, value: unknown ) => editPost( { meta: { [ key ]: value } } ),
	} as Record< string, ( ...args: unknown[] ) => unknown >;
};

/**
 * Register plugins
 */
const postStatusSidebar = compose( withSelect( mapStateToProps ), withDispatch( mapDispatchToProps ) )( PostStatusExtensions ) as ComponentType;

registerPlugin( 'post-status-sidebar', { render: postStatusSidebar } );
