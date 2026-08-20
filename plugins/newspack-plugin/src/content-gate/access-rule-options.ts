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
 * distinct. A token typed or pasted as a bare product ID resolves too, which is how the
 * field supports adding by ID.
 *
 * @param tokens  The tokens from the field.
 * @param options The available rule options.
 * @param stored  The values currently stored on the rule. IDs among these resolve even
 *                when no option matches them, so a value the option list cannot describe
 *                is preserved rather than silently dropped.
 *
 * @return The selected option values, deduplicated, in token order.
 */
export function resolveAccessRuleOptionTokens(
	tokens: ( string | TokenItem )[],
	options: AccessRuleOption[],
	stored: readonly ( string | number )[] = []
): ( string | number )[] {
	const byLabel = new Map( options.map( option => [ formatAccessRuleOptionLabel( option ), option.value ] ) );
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
			const id = ( String( raw ).match( TOKEN_ID_PATTERN )?.[ 1 ] ?? String( raw ) ).trim();
			value = findAccessRuleOption( options, id )?.value ?? stored.find( v => String( v ) === id );
		}
		if ( undefined !== value && ! resolved.some( v => String( v ) === String( value ) ) ) {
			resolved.push( value );
		}
	}

	return resolved;
}

/**
 * Whether an input typed into the field names a selectable option.
 *
 * Used as `FormTokenField`'s input validator, which drops anything it rejects rather than
 * letting it become a token that silently disappears on the next render. The field
 * announces the rejection to assistive technology and renders nothing, so pair it with
 * `getAccessRuleTokenFieldMessages()` — its wording is the only account of what happened
 * — and with `__experimentalAutoSelectFirstMatch`, which commits the highlighted
 * suggestion so typing a name and pressing Enter selects rather than being rejected.
 *
 * @param input   The typed input.
 * @param options The available rule options.
 *
 * @return Whether the input resolves.
 */
export function isAccessRuleOptionInput( input: string, options: AccessRuleOption[] ): boolean {
	return resolveAccessRuleOptionTokens( [ input ], options ).length > 0;
}

/**
 * `FormTokenField`'s announcement strings.
 *
 * The component takes `messages` as a whole object with no per-key merge, so the three
 * generic strings have to be repeated to override the fourth. The default for that
 * fourth one is "Invalid item", which says nothing about what the field accepts.
 *
 * @param invalid Wording for a rejected input. Defaults to the option-picker phrasing.
 *
 * @return The messages for `FormTokenField`.
 */
export function getAccessRuleTokenFieldMessages( invalid?: string ) {
	return {
		added: __( 'Item added.', 'newspack-plugin' ),
		removed: __( 'Item removed.', 'newspack-plugin' ),
		remove: __( 'Remove item', 'newspack-plugin' ),
		__experimentalInvalid: invalid ?? __( 'Not a selectable option. Pick one from the list, or type its ID.', 'newspack-plugin' ),
	};
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
