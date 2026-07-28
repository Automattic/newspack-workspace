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
 * Stand-in name for a stored value the option list can no longer describe. Keyed by rule
 * slug so the wording names the right kind of thing.
 */
const MISSING_OPTION_LABELS: Record< string, () => string > = {
	subscription: () => __( '(product unavailable)', 'newspack-plugin' ),
	institution: () => __( '(institution unavailable)', 'newspack-plugin' ),
	gate: () => __( '(gate unavailable)', 'newspack-plugin' ),
};

/**
 * The stand-in name to use for a rule's unresolvable values.
 *
 * @param slug The rule slug.
 *
 * @return The stand-in name.
 */
export function getMissingOptionLabel( slug: string ): string {
	return ( MISSING_OPTION_LABELS[ slug ] ?? MISSING_OPTION_LABELS.subscription )();
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
 *                when no option matches them, so a value whose product has since been
 *                deleted is preserved rather than silently dropped.
 *
 * @return The selected option values, deduplicated, in token order.
 */
export function resolveAccessRuleOptionTokens(
	tokens: ( string | TokenItem )[],
	options: AccessRuleOption[],
	stored: unknown = []
): ( string | number )[] {
	const byLabel = new Map( options.map( option => [ formatAccessRuleOptionLabel( option ), option.value ] ) );
	const storedValues = Array.isArray( stored ) ? stored : [];
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
			value = findAccessRuleOption( options, id )?.value ?? storedValues.find( v => String( v ) === id );
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
 * Used as `FormTokenField`'s input validator so free text is rejected inline instead of
 * becoming a token that silently disappears on the next render.
 *
 * @param input   The typed input.
 * @param options The available rule options.
 *
 * @return Whether the input resolves.
 */
export function isAccessRuleOptionInput( input: string, options: AccessRuleOption[] ): boolean {
	return resolveAccessRuleOptionTokens( [ input ], options ).length > 0;
}
