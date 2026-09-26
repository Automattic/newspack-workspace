/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Get edit gate layout URL.
 */
export function getEditGateLayoutUrl( gateId: number, gateMode: string ) {
	const audienceGates = ( window as any ).newspackAudienceContentGates;

	if ( ! audienceGates || typeof audienceGates.edit_gate_layout_url !== 'string' || ! audienceGates.edit_gate_layout_url ) {
		// Fallback to avoid runtime errors if the global config is not available.
		// eslint-disable-next-line no-console
		console.error( 'newspackAudienceContentGates.edit_gate_layout_url is not defined on window.' );
		return '';
	}

	let url = audienceGates.edit_gate_layout_url;
	if ( gateId ) {
		url = addQueryArgs( url, { gate_id: gateId } );
	}
	if ( gateMode ) {
		url = addQueryArgs( url, { gate_mode: gateMode } );
	}
	return url;
}

/**
 * Resolve the free-view allowance one audience path grants.
 *
 * The count lives on the site meter unless the path opts out, so a stale count
 * left on the gate must not be read while it is sharing.
 *
 * @param metering  The path's metering settings.
 * @param siteCount The site meter count governing this path.
 */
export const getMeteringCount = ( metering?: Metering, siteCount?: number ) => {
	if ( ! metering ) {
		return 0;
	}
	if ( metering.scope === 'gate' ) {
		return Number( metering.count ) || 0;
	}
	// No allowance rather than a guessed one: the summaries print this number and the
	// metered/not-metered helpers read it, so a default states an allowance nobody grants.
	return Number( siteCount ?? 0 ) || 0;
};

/**
 * Whether a gate actually meters, i.e. it grants at least one free view.
 *
 * Metering switched on with 0 free views gates every reader on their first view, so
 * nothing downstream of metering (the countdown banner, content gifting) has anything
 * to count. This mirrors `Newspack\Metering::is_gate_metered()` on the PHP side, which
 * is what those surfaces are gated on at render time - a section only meters while it
 * is active, has metering on, and allows a positive number of views.
 */
export const isGateMetered = ( gate: Gate, siteMeter?: SiteMeterConfig ) => {
	const meters = ( section?: Registration | CustomAccess, siteCount?: number ) =>
		Boolean( section?.active && section?.metering?.enabled && getMeteringCount( section.metering, siteCount ) > 0 );
	// Signed-out readers fall through to the paywall when there is no registration wall.
	const signedOutPath = gate.registration?.active ? gate.registration : gate.custom_access;
	return meters( signedOutPath, siteMeter?.anonymous_count ) || meters( gate.custom_access, siteMeter?.registered_count );
};

/**
 * Whether any of a gate's audience paths keeps its own allowance.
 *
 * Scope is stored per audience path, and adoption stamps only the paths that disagree
 * with the shared allowance, so a gate can hold one of each. This answers "any path
 * opted out", which is the question for warning that a gate is not wholly governed by
 * the Metering page. It is not the complement of `hasSharedMeteredPath()`: a mixed gate
 * satisfies both.
 *
 * @param gate The gate.
 */
export const hasOwnMeter = ( gate: Gate ) => {
	const optsOut = ( section?: Registration | CustomAccess ) =>
		Boolean( section?.active && section?.metering?.enabled && section.metering.scope === 'gate' );
	return optsOut( gate.registration ) || optsOut( gate.custom_access );
};

/**
 * Whether any of a gate's audience paths still draws on the shared allowance.
 *
 * The question the Metering page needs before warning that changing the allowance
 * leaves gate wording behind: a gate with one path pinned and one path sharing still
 * quotes the shared number somewhere.
 *
 * @param gate      The gate.
 * @param siteMeter The site meter, once the wizard has loaded it.
 */
export const hasSharedMeteredPath = ( gate: Gate, siteMeter?: SiteMeterConfig ) => {
	const shares = ( section: Registration | CustomAccess | undefined, siteCount?: number ) =>
		Boolean(
			section?.active && section?.metering?.enabled && section.metering.scope !== 'gate' && getMeteringCount( section.metering, siteCount ) > 0
		);
	const signedOutPath = gate.registration?.active ? gate.registration : gate.custom_access;
	return shares( signedOutPath, siteMeter?.anonymous_count ) || shares( gate.custom_access, siteMeter?.registered_count );
};

/**
 * Whether a gate draws on the shared allowance at all, whatever that allowance is.
 *
 * Deliberately blind to the count, which is what separates it from
 * `hasSharedMeteredPath()`: the Metering page needs this to warn that an allowance of
 * 0 gates readers immediately, and at 0 the count test that helper applies is false.
 *
 * @param gate The gate.
 */
export const sharesTheSiteMeter = ( gate: Gate ) => {
	const shares = ( section?: Registration | CustomAccess ) =>
		Boolean( section?.active && section?.metering?.enabled && section.metering.scope !== 'gate' );
	return shares( gate.registration ) || shares( gate.custom_access );
};

// The two verdicts a rule's stored value can carry are shared with the block
// editor's visibility panel, which renders the same rules from the same registry.
export {
	getAccessRuleValueNotice,
	isAccessRulePickerInert,
	isMalformedAccessRuleValue,
	isUnconstrainedAccessRuleValue,
} from '../../../../content-gate/utils/access-rule-value';

export const getGateStatus = ( status: GateStatus ) => {
	return status === 'publish' ? __( 'Active', 'newspack-plugin' ) : __( 'Inactive', 'newspack-plugin' );
};

// An inactive gate is an unpublished draft post, not a settled "off" state.
export const getGateStatusBadgeIntent = ( status: GateStatus ): 'stable' | 'draft' => {
	return status === 'publish' ? 'stable' : 'draft';
};

/**
 * Whether a gate asks for paid access: custom access on, with at least one rule.
 * Custom access with no rules restricts nobody.
 *
 * @param gate The gate.
 */
const requiresPaidAccess = ( gate: Gate ) =>
	Boolean( gate.custom_access?.active ) && ( gate.custom_access?.access_rules ?? [] ).some( group => group.length > 0 );

/**
 * A content rule's values as strings, so term IDs stored as numbers and as strings compare equal.
 * A single value counts as a one-item list, as the server treats taxonomy rules.
 *
 * @param rule The content rule.
 */
const getRuleValues = ( rule: GateContentRule ): string[] => {
	const value: unknown = rule.value;
	if ( value === undefined || value === null ) {
		return [];
	}
	return ( Array.isArray( value ) ? value : [ value ] ).map( String );
};

type ContentScope = {
	// Ways into the gate by rule. A post meeting every rule in any one clause is covered.
	clauses: GateContentRule[][];
	// Posts listed by ID, covered whatever the other rules say.
	specificPosts: string[];
	exclusions: GateContentRule[];
};

/**
 * Summarize which content a gate can match, following Content_Restriction_Control::get_post_gates().
 *
 * @param gate The gate.
 */
const getContentScope = ( gate: Gate ): ContentScope => {
	const rules = ( gate.content_rules ?? [] ).filter( rule => getRuleValues( rule ).length > 0 );
	const otherRules = rules.filter( rule => rule.slug !== 'specific_posts' );
	const inclusions = otherRules.filter( rule => ! rule.exclusion );
	let clauses: GateContentRule[][] = [ inclusions ];
	if ( otherRules.length === 0 ) {
		// With no rules beyond specific posts, only the listed posts match, and with no rules at all, nothing does.
		clauses = [];
	} else if ( gate.content_rules_match === 'any' && inclusions.length > 1 ) {
		// Under "any", each inclusion rule is a way in on its own.
		clauses = inclusions.map( rule => [ rule ] );
	}
	return {
		clauses,
		specificPosts: rules.filter( rule => rule.slug === 'specific_posts' ).flatMap( getRuleValues ),
		exclusions: otherRules.filter( rule => rule.exclusion ),
	};
};

/**
 * The values a post needs, rule by rule, to fall under two clauses at once: the shared values where
 * both clauses set a rule, and one clause's values where only it does.
 *
 * @param a One gate's clause.
 * @param b The other gate's clause.
 * @return The combined values by rule slug, or null when a rule both clauses set shares no value.
 */
const combineClauses = ( a: GateContentRule[], b: GateContentRule[] ): Map< string, string[] > | null => {
	const combined = new Map< string, string[] >();
	for ( const rule of [ ...a, ...b ] ) {
		const values = getRuleValues( rule );
		const current = combined.get( rule.slug );
		const next = current ? current.filter( value => values.includes( value ) ) : values;
		if ( ! next.length ) {
			return null;
		}
		combined.set( rule.slug, next );
	}
	return combined;
};

/**
 * Whether exclusions carve out everything a post needs to fall under both clauses, which happens
 * when an exclusion covers every value a rule allows.
 *
 * @param combined   The combined values, from combineClauses().
 * @param exclusions Both gates' exclusion rules.
 */
const isCarvedOut = ( combined: Map< string, string[] >, exclusions: GateContentRule[] ) =>
	[ ...combined ].some( ( [ slug, values ] ) =>
		exclusions.some( exclusion => exclusion.slug === slug && values.every( value => getRuleValues( exclusion ).includes( value ) ) )
	);

/**
 * Whether two gates cover some of the same content, judged from the values their rules store.
 *
 * Where both gates set a rule, they must share a value. Where only one does, its content counts as
 * falling inside the other's: a category gate above an all-posts gate covers posts in both. The
 * page doesn't load the posts or terms themselves, so two things go unseen. A post filed under two
 * different categories falls under gates on each, and a parent term covers its child terms. A post
 * listed by ID counts as shared with any content the other gate covers, since its type and terms
 * aren't known here.
 *
 * @param a One gate's content scope.
 * @param b The other gate's content scope.
 */
const sharesContent = ( a: ContentScope, b: ContentScope ) => {
	if ( a.specificPosts.some( id => b.specificPosts.includes( id ) ) ) {
		return true;
	}
	if ( ( a.specificPosts.length > 0 && b.clauses.length > 0 ) || ( b.specificPosts.length > 0 && a.clauses.length > 0 ) ) {
		return true;
	}
	const exclusions = [ ...a.exclusions, ...b.exclusions ];
	return a.clauses.some( aClause =>
		b.clauses.some( bClause => {
			const combined = combineClauses( aClause, bClause );
			return combined !== null && ! isCarvedOut( combined, exclusions );
		} )
	);
};

/**
 * Warnings for gates ranked where they let readers skip a paid gate below them.
 *
 * The first gate matching a post decides access alone (NPPD-2289). A gate that asks for no paid
 * access, ranked above a paid gate on content the two share, lets readers through there without
 * paying. That is how a free section inside a paid one is built, and also how a paywall gets opened
 * by mistake, so the shape is named rather than blocked.
 *
 * @param gates The gates, in any order. Ranked like the server ranks them: priority, then age.
 *              Only published gates take part, since the server skips the rest.
 * @return Warning text keyed by the ID of the higher-ranked gate.
 */
export const getPriorityWarnings = ( gates: Gate[] ): Record< number, string > => {
	const ranked = gates.filter( gate => gate.status === 'publish' ).sort( ( a, b ) => a.priority - b.priority || a.id - b.id );
	const scopes = ranked.map( getContentScope );
	const warnings: Record< number, string > = {};
	ranked.forEach( ( gate, index ) => {
		const takesPart = gate.registration?.active || gate.custom_access?.active;
		if ( ! takesPart || requiresPaidAccess( gate ) ) {
			return;
		}
		const opensPaidContent = ranked.some(
			( lower, lowerIndex ) => lowerIndex > index && requiresPaidAccess( lower ) && sharesContent( scopes[ index ], scopes[ lowerIndex ] )
		);
		if ( opensPaidContent ) {
			warnings[ gate.id ] = __( 'This grants registered readers access to content also restricted by paid access rules.', 'newspack-plugin' );
		}
	} );
	return warnings;
};

/**
 * Describe the shared allowance, for the Metering card and the Metering page header.
 *
 * @param siteMeter The site meter, once the wizard has loaded it.
 */
export const getMeteringDescription = ( siteMeter?: SiteMeterConfig ) => {
	if ( ! siteMeter ) {
		return __( 'Set how many articles readers can view for free before a gate applies.', 'newspack-plugin' );
	}
	return sprintf(
		// translators: 1: free views for signed-out readers, 2: free views for signed-in readers, 3: how often the allowance resets, e.g. "monthly".
		__(
			'Free views reset %3$s: %1$d for signed-out readers, %2$d for signed-in. Every gate shares this allowance unless it keeps its own.',
			'newspack-plugin'
		),
		siteMeter.anonymous_count,
		siteMeter.registered_count,
		siteMeter.period === 'week' ? __( 'weekly', 'newspack-plugin' ) : __( 'monthly', 'newspack-plugin' )
	);
};
