/**
 * Helpers for the access-rule option pickers (Paid Access → "Active subscription",
 * "Institutional access"), shared by the Audience wizard's gate editor and the block
 * editor's visibility panel.
 *
 * An option's own label is just the product or institution name, which is not unique:
 * sites routinely reuse names like "Annual" or "Monthly" across legacy and current
 * product tiers. Tokens are therefore rendered as `<name> (#<id>)`, so that every token
 * identifies exactly one option, the ID is visible when verifying a gate's
 * configuration, and typing an ID narrows the suggestions — while the name stays the
 * part that reads first.
 *
 * This also keeps selection round-tripping correct. Tokens travel through
 * `FormTokenField` as plain strings, so matching them back by name alone made every
 * option sharing a name resolve together: removing one "Annual" token re-selected all of
 * them. Matching on the ID carried in the token resolves one option per token.
 *
 * The trailing ` (#<id>)` is a parsing contract as well as display copy —
 * `resolveAccessRuleOptionTokens` reads the ID back out of it — so its shape is fixed
 * rather than translatable. Only the name preceding it is publisher-facing text.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import type { TokenItem } from '@wordpress/components/build-types/form-token-field/types.d.ts';

export type AccessRuleOption = { value: string | number; label: string };

/**
 * The ID a token carries, e.g. `188250` in `Annual (#188250)`.
 */
const TOKEN_ID_PATTERN = /\(#([^)]+)\)\s*$/;

/**
 * An input that is nothing but an ID, e.g. `188251`.
 */
const BARE_ID_PATTERN = /^\d+$/;

/**
 * Rules whose option list is the complete set of values that can ever match, so an ID the
 * list does not describe is not a value at all and is refused rather than stored.
 *
 * The other rules are why a bare ID is admitted in the first place: an "Active
 * subscription" rule matches a variation ID and a one-time purchase the `_variation_id`
 * on an order item, neither of which the list holds. Gates and institutions work the
 * other way — each list is the published posts, and each server-side test resolves from
 * that same set — so an ID outside the list matches nobody. For a gate that is not a
 * harmless no-op: a block whose gates all fail `Block_Visibility::has_active_gates()` is
 * shown to everyone.
 */
const EXHAUSTIVE_OPTION_RULES = [ 'gate', 'institution' ];

/**
 * Stand-in name for a stored value the option list does not contain. Keyed by rule slug
 * so the wording names the right kind of thing.
 *
 * The wording says "not listed", not "deleted" or "unavailable", because the two are not
 * the same and only one of them is safe to remove. An option list holds parent products
 * and published institutions, while evaluation resolves more than that — an "Active
 * subscription" rule matches a variation ID, and a one-time purchase matches the
 * `_variation_id` on an order item. Such an ID grants access every day while never
 * appearing in the list, so a publisher must not read it as dead configuration: removing
 * it widens the gate, and removing a rule's last value widens it further, since an empty
 * value list applies no filter at all.
 */
const MISSING_OPTION_LABELS: Record< string, () => string > = {
	subscription: () => __( '(product not listed)', 'newspack-plugin' ),
	one_time_purchase: () => __( '(product not listed)', 'newspack-plugin' ),
	institution: () => __( '(institution not listed)', 'newspack-plugin' ),
	gate: () => __( '(gate not listed)', 'newspack-plugin' ),
};

/**
 * Wording for a rule this module knows nothing about. `Access_Rules::register_rule()` is
 * public, so an options-backed rule may come from anywhere and need not hold products.
 */
const DEFAULT_MISSING_OPTION_LABEL = () => __( '(not listed)', 'newspack-plugin' );

/**
 * The stand-in name to use for a rule's unresolvable values.
 *
 * @param slug The rule slug.
 *
 * @return The stand-in name.
 */
export function getMissingOptionLabel( slug: string ): string {
	return ( MISSING_OPTION_LABELS[ slug ] ?? DEFAULT_MISSING_OPTION_LABEL )();
}

/**
 * Find the option a stored value refers to.
 *
 * Values are compared loosely: gates may store IDs as numbers or as strings depending on
 * how the rule was written, while the option list always carries them as integers.
 *
 * @param options The available rule options.
 * @param value   The stored value.
 *
 * @return The matching option, or undefined.
 */
export function findAccessRuleOption( options: AccessRuleOption[], value: unknown ): AccessRuleOption | undefined {
	return options.find( option => String( option.value ) === String( value ) );
}

/**
 * Build the token label for an option: `<name> (#<id>)`.
 *
 * @param option The rule option.
 *
 * @return The token label.
 */
export function formatAccessRuleOptionLabel( option: AccessRuleOption ): string {
	return `${ decodeEntities( option.label ) } (#${ option.value })`;
}

/**
 * Build the token label for a stored value whose option is no longer available — a
 * deleted product, a product that stopped being a subscription, an unpublished
 * institution.
 *
 * These are rendered rather than hidden so a publisher auditing a gate can see the rule
 * still holds an ID, and so the value survives an unrelated edit instead of being
 * dropped on the next save.
 *
 * @param value        The stored value.
 * @param missingLabel The stand-in name, from `getMissingOptionLabel`.
 *
 * @return The token label.
 */
export function formatMissingAccessRuleOptionLabel( value: string | number, missingLabel: string ): string {
	return `${ missingLabel } (#${ value })`;
}

/**
 * Build the token labels for the currently selected option values, in stored order.
 *
 * @param options      The available rule options.
 * @param value        The selected option values.
 * @param missingLabel The stand-in name for values with no matching option.
 *
 * @return The token labels to display.
 */
export function getAccessRuleOptionTokens( options: AccessRuleOption[], value: unknown, missingLabel: string ): string[] {
	const selected = Array.isArray( value ) ? value : [];
	return selected.map( stored => {
		const option = findAccessRuleOption( options, stored );
		return option ? formatAccessRuleOptionLabel( option ) : formatMissingAccessRuleOptionLabel( stored, missingLabel );
	} );
}

/**
 * Resolve tokens coming back from `FormTokenField` to option values.
 *
 * A token matches its option by the ID it carries, so options sharing a name stay
 * distinct. For a rule whose option list is not the whole story, a token typed or pasted
 * as a bare ID resolves whether or not an option describes it: evaluation resolves more
 * than the list holds — an "Active subscription" rule matches a variation ID, a one-time
 * purchase the `_variation_id` on an order item — so a publisher who removes such a token
 * can type it back in. For the rules in `EXHAUSTIVE_OPTION_RULES` there is nothing to
 * type back in, and an ID from those lists' outside is dropped.
 *
 * @param tokens        The tokens from the field.
 * @param options       The available rule options.
 * @param config        The rule the tokens belong to.
 * @param config.slug   The rule slug, which decides whether an ID no option describes is
 *                      a value at all.
 * @param config.stored The values currently stored on the rule. IDs among these resolve
 *                      even when no option matches them, so a value the option list
 *                      cannot describe is preserved rather than silently dropped — for
 *                      every rule, since an unpublished gate is still the gate the block
 *                      was pointed at. A full `<name> (#<id>)` token for an unknown ID
 *                      resolves only from here, so a rule's own tokens survive a round
 *                      trip without a pasted one inventing a value.
 *
 * @return The selected option values, deduplicated, in token order.
 */
export function resolveAccessRuleOptionTokens(
	tokens: ( string | TokenItem )[],
	options: AccessRuleOption[],
	{ slug, stored = [] }: { slug: string; stored?: readonly ( string | number )[] }
): ( string | number )[] {
	const byLabel = new Map( options.map( option => [ formatAccessRuleOptionLabel( option ), option.value ] ) );
	const acceptsUnlistedIds = ! EXHAUSTIVE_OPTION_RULES.includes( slug );
	const resolved: ( string | number )[] = [];

	for ( const token of tokens ) {
		const raw = typeof token === 'string' ? token : token?.value;
		if ( ! raw ) {
			continue;
		}
		// Match the whole token first: an option's name may itself end in something that
		// looks like an ID suffix, and the exact label is unambiguous where it applies.
		let value = byLabel.get( raw );
		if ( undefined === value ) {
			const trimmed = String( raw ).trim();
			const id = ( trimmed.match( TOKEN_ID_PATTERN )?.[ 1 ] ?? trimmed ).trim();
			value = findAccessRuleOption( options, id )?.value ?? stored.find( v => String( v ) === id );
			// Post IDs start at 1, and `sanitize_access_rule` filters falsy values out of
			// the saved list, so a `0` accepted here would vanish on the next reload.
			if ( undefined === value && acceptsUnlistedIds && BARE_ID_PATTERN.test( trimmed ) && Number( trimmed ) > 0 ) {
				value = Number( trimmed );
			}
		}
		if ( undefined !== value && ! resolved.some( v => String( v ) === String( value ) ) ) {
			resolved.push( value );
		}
	}

	return resolved;
}

/**
 * Whether an input typed into the field resolves to a value: an option's full token, or —
 * for a rule whose option list is not exhaustive — a bare ID the list need not describe.
 *
 * Used as `FormTokenField`'s input validator, which drops anything it rejects rather than
 * letting it become a token that silently disappears on the next render. The field
 * announces the rejection to assistive technology and renders nothing, so pair it with
 * `getAccessRuleTokenFieldMessages()` — its wording is the only account of what happened
 * — and with `__experimentalAutoSelectFirstMatch`, which commits the highlighted
 * suggestion so typing a name and pressing Enter selects rather than being rejected.
 *
 * This only guards the one-token-at-a-time path. Input carrying a separator — pasted, or
 * typed with a comma — reaches the field's `addNewTokens`, which never consults the
 * validator, so pasting `Annual, Monthly` still makes tokens that resolve to nothing and
 * disappear on the next render, unannounced. Pasted IDs come through intact.
 *
 * @param input   The typed input.
 * @param options The available rule options.
 * @param slug    The rule slug.
 *
 * @return Whether the input resolves.
 */
export function isAccessRuleOptionInput( input: string, options: AccessRuleOption[], slug: string ): boolean {
	return resolveAccessRuleOptionTokens( [ input ], options, { slug } ).length > 0;
}

/**
 * Wording for an input the field refused, keyed by rule slug: it names the kind of thing
 * the list holds, and it promises typing an ID only where an unlisted one is accepted.
 */
const INVALID_INPUT_MESSAGES: Record< string, () => string > = {
	subscription: () => __( 'Not a selectable product. Pick one from the list, or type its ID.', 'newspack-plugin' ),
	one_time_purchase: () => __( 'Not a selectable product. Pick one from the list, or type its ID.', 'newspack-plugin' ),
	institution: () => __( 'Not a selectable institution. Pick one from the list.', 'newspack-plugin' ),
	gate: () => __( 'Not a selectable gate. Pick one from the list.', 'newspack-plugin' ),
};

/**
 * Wording for a rule this module knows nothing about, which accepts an unlisted ID.
 */
const DEFAULT_INVALID_INPUT_MESSAGE = () => __( 'Not a selectable option. Pick one from the list, or type its ID.', 'newspack-plugin' );

/**
 * `FormTokenField`'s announcement strings.
 *
 * The component takes `messages` as a whole object with no per-key merge, so the three
 * generic strings have to be repeated to override the fourth. The default for that
 * fourth one is "Invalid item", which says nothing about what the field accepts.
 *
 * @param slug The rule slug, which decides the wording for a rejected input.
 *
 * @return The messages for `FormTokenField`.
 */
export function getAccessRuleTokenFieldMessages( slug: string ) {
	return {
		added: __( 'Item added.', 'newspack-plugin' ),
		removed: __( 'Item removed.', 'newspack-plugin' ),
		remove: __( 'Remove item', 'newspack-plugin' ),
		__experimentalInvalid: ( INVALID_INPUT_MESSAGES[ slug ] ?? DEFAULT_INVALID_INPUT_MESSAGE )(),
	};
}

/**
 * Notice for a rule whose fetched options could not be loaded.
 *
 * The picker falls back to the list localised with the page, which was complete when the
 * page loaded and is the better fallback — but it may no longer name every item, and a
 * publisher editing against it should be told so rather than left to assume otherwise.
 *
 * @return The notice text.
 */
export function getAccessRuleOptionsFetchFailedNotice(): string {
	return __( 'Failed to load options. The list may be outdated.', 'newspack-plugin' );
}

/**
 * Whether a rule holds values that no option describes.
 *
 * @param options The available rule options.
 * @param value   The selected option values.
 *
 * @return Whether any stored value is absent from the option list.
 */
export function hasUnlistedAccessRuleValues( options: AccessRuleOption[], value: unknown ): boolean {
	return Array.isArray( value ) && value.some( stored => ! findAccessRuleOption( options, stored ) );
}

/**
 * Caution shown alongside a picker holding values no option describes, so the reading
 * that the token invites — stale entry, safe to delete — does not go unchallenged.
 *
 * @return The caution text.
 */
export function getUnlistedAccessRuleValuesNotice(): string {
	return __(
		'Entries marked “not listed” are not in this list — a product variation, or an item that was deleted or unpublished. They are still checked when access is evaluated, so removing one widens who this gate lets in.',
		'newspack-plugin'
	);
}
