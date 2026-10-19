/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { registerPlugin } from '@wordpress/plugins';
import { ExternalLink, ToggleControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { gateMatchesPost } from './gate-matching';
import { AgeDurationFields } from '../components/published-age-rule-control';
import { AGE_OVERRIDE_META_KEY, PUBLISHED_AGE_RULE, formatAgeDuration, isUsableAgeDuration, normalizeAgeDuration } from '../utils/published-age';
import './post-settings.scss';

const { gates = [], taxonomyMap = {}, canEditGates = false } = window.newspackContentGates || {};

const getAgeRule = gate => gate.content_rules.find( rule => rule.slug === PUBLISHED_AGE_RULE );

// A stored rule with an unreadable unit would fail the meta's schema and with it every post save.
const seedAgeOverride = value => {
	const duration = normalizeAgeDuration( value );
	return { duration_value: duration.duration_value, duration_unit: duration.duration_unit || 'days' };
};

const renderGateLink = gate => ( canEditGates && gate.edit_url ? <a href={ gate.edit_url }>{ gate.title }</a> : gate.title );

function PostSettings() {
	const { meta, savedAgeOverride, postId, postType, termsByTax, publishedGmt } = useSelect( select => {
		const { getEditedPostAttribute, getCurrentPostAttribute, getCurrentPostId } = select( 'core/editor' );
		const terms = {};
		Object.values( taxonomyMap ).forEach( restBase => {
			terms[ restBase ] = getEditedPostAttribute( restBase ) || [];
		} );
		return {
			meta: getEditedPostAttribute( 'meta' ),
			savedAgeOverride: getCurrentPostAttribute( 'meta' )?.[ AGE_OVERRIDE_META_KEY ],
			postId: getCurrentPostId(),
			postType: getEditedPostAttribute( 'type' ),
			termsByTax: terms,
			// Mirrors the server: only a published post has an age. The date is the saved one: the
			// date picker edits the local `date` only, so a new date shows here once the post is saved.
			publishedGmt: 'publish' === getEditedPostAttribute( 'status' ) ? getEditedPostAttribute( 'date_gmt' ) : null,
		};
	} );
	const ageOverride = meta[ AGE_OVERRIDE_META_KEY ];
	// The schema stores only a known unit, so a unit is set exactly when the override is on.
	const isAgeOverrideOn = Boolean( ageOverride?.duration_unit );
	const editedDuration = normalizeAgeDuration( ageOverride );
	const savedDuration = normalizeAgeDuration( savedAgeOverride );
	// Only an unsaved edit locks saving: a stored value may have no control on screen to correct it.
	const isUnusableEdit =
		isAgeOverrideOn &&
		! isUsableAgeDuration( editedDuration ) &&
		( editedDuration.duration_value !== savedDuration.duration_value || editedDuration.duration_unit !== savedDuration.duration_unit );
	const { editPost, lockPostSaving, unlockPostSaving, lockPostAutosaving, unlockPostAutosaving } = useDispatch( 'core/editor' );

	useEffect( () => {
		if ( ! isUnusableEdit ) {
			return;
		}
		lockPostSaving( AGE_OVERRIDE_META_KEY );
		lockPostAutosaving( AGE_OVERRIDE_META_KEY );
		return () => {
			unlockPostSaving( AGE_OVERRIDE_META_KEY );
			unlockPostAutosaving( AGE_OVERRIDE_META_KEY );
		};
	}, [ isUnusableEdit ] );

	const { hasSeveralAgeGates, matchingGates, matchingGatesWithoutOverride, seedAgeRule } = useMemo( () => {
		// REST dates carry no zone, and are UTC.
		const published = publishedGmt ? new Date( `${ publishedGmt }Z` ) : null;
		const matches = ( gate, rules, override ) =>
			gateMatchesPost( rules, postType, termsByTax, postId, gate.content_rules_match, taxonomyMap, published, override );
		// The override starts from the duration it replaces: the age rule of a gate that covers
		// this post on its other rules, or of any gate when none does.
		const coveringGate = gates.find(
			gate =>
				getAgeRule( gate ) &&
				matches(
					gate,
					gate.content_rules.filter( rule => rule.slug !== PUBLISHED_AGE_RULE ),
					null
				)
		);
		return {
			hasSeveralAgeGates: gates.filter( getAgeRule ).length > 1,
			matchingGates: gates.filter( gate => matches( gate, gate.content_rules, ageOverride ) ),
			matchingGatesWithoutOverride: gates.filter( gate => matches( gate, gate.content_rules, null ) ),
			seedAgeRule: getAgeRule( coveringGate ?? gates.find( getAgeRule ) ?? { content_rules: [] } ),
		};
	}, [ postId, postType, termsByTax, publishedGmt, ageOverride ] );

	const getAgeOverrideHelp = () => {
		if ( meta.newspack_content_restriction_is_exempt ) {
			return __( 'Has no effect while access control restrictions are disabled for this post.', 'newspack-plugin' );
		}
		if ( hasSeveralAgeGates ) {
			return __( 'Replaces the time window in each gate’s publication date rule for this post only.', 'newspack-plugin' );
		}
		return sprintf(
			// translators: %s: the gate's own time window, such as "7 days".
			__( 'Replaces the time window in the gate’s publication date rule (%s) for this post only.', 'newspack-plugin' ),
			formatAgeDuration( normalizeAgeDuration( seedAgeRule.value ) )
		);
	};

	return (
		<PluginDocumentSettingPanel
			name="content-gate-post-exemptions-panel"
			className="newspack-content-gate-panel"
			title={ __( 'Access Control Settings', 'newspack-plugin' ) }
		>
			<Stack className="newspack-content-gate-panel__settings" direction="column" gap="lg">
				{ matchingGates.length > 0 ? (
					<>
						{ /* Gates arrive in priority order, and the first matching gate decides access alone (NPPD-2289). */ }
						<p>
							{ meta.newspack_content_restriction_is_exempt
								? __( 'Gate that would decide access if this post weren’t exempt: ', 'newspack-plugin' )
								: __( 'Gate that decides access to this post: ', 'newspack-plugin' ) }
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
					<p>
						{ isAgeOverrideOn && matchingGatesWithoutOverride.length > 0
							? __(
									'No gates apply to this post: its own time window keeps it outside the gates that would otherwise apply.',
									'newspack-plugin'
							  )
							: __( 'No gates apply to this post.', 'newspack-plugin' ) }
					</p>
				) }
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
					__nextHasNoMarginBottom
				/>
				{ seedAgeRule && (
					<Stack direction="column" gap="lg">
						<ToggleControl
							label={ __( 'Use a different time window for this post', 'newspack-plugin' ) }
							help={ getAgeOverrideHelp() }
							checked={ isAgeOverrideOn }
							onChange={ isOn =>
								editPost( {
									meta: {
										[ AGE_OVERRIDE_META_KEY ]: isOn ? seedAgeOverride( seedAgeRule.value ) : null,
									},
								} )
							}
							__nextHasNoMarginBottom
						/>
						{ isAgeOverrideOn && (
							<AgeDurationFields
								value={ ageOverride }
								onChange={ duration => editPost( { meta: { [ AGE_OVERRIDE_META_KEY ]: duration } } ) }
							/>
						) }
					</Stack>
				) }
			</Stack>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'newspack-content-gate-post-exemptions', {
	render: PostSettings,
	icon: null,
} );
