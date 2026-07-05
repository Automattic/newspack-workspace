/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { withSelect, withDispatch } from '@wordpress/data';
import { compose } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * External dependencies
 */
import { WebPreview } from 'newspack-components';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import type { PromptMeta } from './utils';

/** Props injected by the `withSelect`/`withDispatch` HOCs below. */
interface PreviewSettingProps {
	autosavePost: () => Promise< void >;
	isSavingPost: boolean;
	postId?: number;
	metaFields?: PromptMeta;
}

const PreviewSetting = ( { autosavePost, isSavingPost, postId, metaFields }: PreviewSettingProps ) => {
	if ( ! postId || ! metaFields ) {
		return null;
	}

	const previewQueryKeys = window.newspack_popups_data?.preview_query_keys || {};
	const frontendUrl = window?.newspack_popups_data?.frontend_url || '/';
	const abbreviatedKeys: Record< string, unknown > = {};
	Object.keys( metaFields ).forEach( key => {
		if ( previewQueryKeys.hasOwnProperty( key ) ) {
			abbreviatedKeys[ previewQueryKeys[ key ] ] = metaFields[ key ];
		}
	} );

	const query = {
		pid: postId,
		// Autosave does not handle meta fields, so these will be passed in the URL
		...abbreviatedKeys,
	};

	const isArchivePagesPrompt = metaFields.placement === 'archives';
	// `newspack_popups_data` is only unset off the prompt editor screen, where this component never renders.
	const previewURL = window.newspack_popups_data![ isArchivePagesPrompt ? 'preview_archive' : 'preview_post' ] || '/';

	const onWebPreviewLoad = ( iframeEl: HTMLIFrameElement | null ) => {
		if ( iframeEl ) {
			[ ...iframeEl.contentWindow!.document.querySelectorAll( 'a[href^="' + frontendUrl + '"]' ) ].forEach( anchor => {
				anchor.setAttribute( 'href', addQueryArgs( anchor.getAttribute( 'href' )!, query ) );
			} );
		}
	};

	return (
		<WebPreview
			url={ addQueryArgs( previewURL, query ) }
			onLoad={ onWebPreviewLoad }
			renderButton={ ( { showPreview } ) => (
				<Button isPrimary isBusy={ isSavingPost } disabled={ isSavingPost } onClick={ () => autosavePost().then( showPreview ) }>
					{ __( 'Preview', 'newspack-popups' ) }
				</Button>
			) }
		/>
	);
};

// Passed as separate arguments (rather than the original single-array form) to match
// compose()'s declared variadic signature -- its real implementation `.flat()`s its
// arguments either way, so this is not a behavior change.
const connectPreviewSetting = compose(
	withSelect( select => {
		const { isSavingPost, getCurrentPostId, getEditedPostAttribute } = select( 'core/editor' ) as {
			isSavingPost: () => boolean;
			getCurrentPostId: () => number;
			getEditedPostAttribute: ( attribute: string ) => unknown;
		};
		return {
			postId: getCurrentPostId(),
			metaFields: getEditedPostAttribute( 'meta' ) as PromptMeta | undefined,
			isSavingPost: isSavingPost(),
		};
	} ),
	withDispatch( dispatch => {
		return {
			autosavePost: () => ( dispatch( 'core/editor' ) as { autosave: () => Promise< void > } ).autosave(),
		};
	} )
);

export default connectPreviewSetting( PreviewSetting ) as ComponentType;
