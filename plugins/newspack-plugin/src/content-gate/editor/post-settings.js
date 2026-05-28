/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { registerPlugin } from '@wordpress/plugins';
import { ExternalLink, ToggleControl } from '@wordpress/components';

const { gates = [], taxonomyMap = {}, canEditGates = false } = window.newspackContentGates || {};

/**
 * Check if a gate's content rules match the current post state. Mirrors
 * the PHP `Content_Restriction_Control::get_post_gates()` evaluator.
 *
 * @param {Array}  contentRules Gate content rules.
 * @param {string} postType     Current post type.
 * @param {Object} termsByTax   Map of taxonomy REST base to array of term IDs.
 * @param {number} postId       Current post ID, for the specific_posts override.
 *
 * @return {boolean} Whether the gate applies to this post.
 */
function gateMatchesPost( contentRules, postType, termsByTax, postId ) {
	// Inclusion override: if this post ID is listed in any specific_posts
	// rule, the gate applies regardless of other rules.
	const specificMatch = contentRules.some(
		rule =>
			rule.slug === 'specific_posts' &&
			Array.isArray( rule.value ) &&
			rule.value.length > 0 &&
			rule.value.map( v => parseInt( v ) ).includes( parseInt( postId ) )
	);
	if ( specificMatch ) {
		return true;
	}

	// Standard AND evaluation across remaining rules. specific_posts is skipped
	// here; if it was the only rule and didn't match, the gate doesn't apply.
	let hasNonSpecificRule = false;
	const allMatch = contentRules.every( rule => {
		if ( rule.slug === 'specific_posts' ) {
			return true;
		}
		hasNonSpecificRule = true;
		const isExclusion = rule.exclusion;
		if ( rule.slug === 'post_types' ) {
			return isExclusion ? ! rule.value.includes( postType ) : rule.value.includes( postType );
		}
		const restBase = taxonomyMap[ rule.slug ];
		if ( ! restBase ) {
			return false;
		}
		const postTerms = termsByTax[ restBase ] || [];
		if ( ! isExclusion && ! postTerms.length ) {
			return false;
		}
		const hasOverlap = rule.value.some( id => postTerms.includes( parseInt( id ) ) );
		return isExclusion ? ! hasOverlap : hasOverlap;
	} );

	return allMatch && hasNonSpecificRule;
}

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
		() => gates.filter( gate => gateMatchesPost( gate.content_rules, postType, termsByTax, postId ) ),
		[ postId, postType, termsByTax ]
	);

	return (
		<PluginDocumentSettingPanel name="content-gate-post-exemptions-panel" title={ __( 'Access control settings', 'newspack-plugin' ) }>
			{ matchingGates.length > 0 ? (
				<p>
					{ __( 'Gates that apply to this post: ', 'newspack-plugin' ) }
					{ matchingGates.map( ( gate, index ) => (
						<span key={ gate.id }>
							{ index > 0 && ', ' }
							{ canEditGates && gate.edit_url ? <a href={ gate.edit_url }>{ gate.title }</a> : gate.title }
						</span>
					) ) }
				</p>
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
