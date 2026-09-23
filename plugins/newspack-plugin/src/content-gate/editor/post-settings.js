/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { registerPlugin } from '@wordpress/plugins';
import { ExternalLink, ToggleControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { gateMatchesPost } from './gate-matching';

const { gates = [], taxonomyMap = {}, canEditGates = false } = window.newspackContentGates || {};

const renderGateLink = gate => ( canEditGates && gate.edit_url ? <a href={ gate.edit_url }>{ gate.title }</a> : gate.title );

function PostSettings() {
	const { meta, postId, postType, termsByTax } = useSelect( select => {
		const { getEditedPostAttribute, getCurrentPostId } = select( 'core/editor' );
		const terms = {};
		Object.values( taxonomyMap ).forEach( restBase => {
			terms[ restBase ] = getEditedPostAttribute( restBase ) || [];
		} );
		return {
			meta: getEditedPostAttribute( 'meta' ),
			postId: getCurrentPostId(),
			postType: getEditedPostAttribute( 'type' ),
			termsByTax: terms,
		};
	} );
	const { editPost } = useDispatch( 'core/editor' );

	const matchingGates = useMemo(
		() => gates.filter( gate => gateMatchesPost( gate.content_rules, postType, termsByTax, postId, gate.content_rules_match, taxonomyMap ) ),
		[ postId, postType, termsByTax ]
	);

	return (
		<PluginDocumentSettingPanel
			name="content-gate-post-exemptions-panel"
			className="newspack-content-gate-panel"
			title={ __( 'Access Control Settings', 'newspack-plugin' ) }
		>
			{ matchingGates.length > 0 ? (
				<>
					{ /* Gates arrive in priority order, and the first matching gate decides access alone (NPPD-2289). */ }
					<p>
						{ __( 'Gate that decides access to this post: ', 'newspack-plugin' ) }
						{ renderGateLink( matchingGates[ 0 ] ) }
					</p>
					{ matchingGates.length > 1 && (
						<p>
							{ __( 'Also matching, but ranked lower: ', 'newspack-plugin' ) }
							{ matchingGates.slice( 1 ).map( ( gate, index ) => (
								<span key={ gate.id }>
									{ index > 0 && ', ' }
									{ renderGateLink( gate ) }
								</span>
							) ) }
						</p>
					) }
				</>
			) : (
				<p>{ __( 'No gates apply to this post.', 'newspack-plugin' ) }</p>
			) }
			<hr />
			<ToggleControl
				label={ __( 'Disable access control restrictions for this post', 'newspack-plugin' ) }
				help={
					<>
						{ __(
							'If enabled, this post will be accessible to all readers regardless of content restriction rules.',
							'newspack-plugin'
						) }{ ' ' }
						<ExternalLink href="/wp-admin/admin.php?page=newspack-audience-access-control">
							{ __( 'Manage access control', 'newspack-plugin' ) }
						</ExternalLink>
					</>
				}
				checked={ meta.newspack_content_restriction_is_exempt }
				onChange={ value => editPost( { meta: { newspack_content_restriction_is_exempt: value } } ) }
			/>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'newspack-content-gate-post-exemptions', {
	render: PostSettings,
	icon: null,
} );
