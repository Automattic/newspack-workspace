/**
 * Range access rule control.
 *
 * Shared between the Audience > Access control wizard and the block editor's
 * block-visibility panel: renders minimum and maximum inputs for a rule
 * registered with `is_range` (a reader data field matched as a number). The value
 * is `{ min, max }`, either side optional, which is the shape
 * `Promoted_Fields::sanitize_range_value()` saves and the rule's callback compares
 * against.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { getRangeRuleValueNotice, normalizeRangeValue, type RangeValue } from '../utils/access-rule-value';
import './range-rule-control.scss';

/**
 * A typed bound as the number it names, so the stored value compares as one. Text
 * the browser let through as not-a-number stays as typed, so the save refuses it
 * rather than dropping a bound the editor meant to set.
 *
 * @param input The input's value.
 */
const toBound = ( input: string ) => {
	const number = Number( input );
	return '' !== input.trim() && Number.isFinite( number ) ? number : input;
};

export default function RangeRuleControl( { label, value, onChange }: { label: string; value: unknown; onChange: ( value: RangeValue ) => void } ) {
	const range = normalizeRangeValue( value );
	const notice = getRangeRuleValueNotice( value );
	const noticeId = useInstanceId( RangeRuleControl, 'newspack-range-rule-notice' );
	const update = ( bound: keyof RangeValue, input: string ) => {
		const next = { ...range };
		if ( '' === input ) {
			delete next[ bound ];
		} else {
			next[ bound ] = toBound( input );
		}
		onChange( next );
	};

	return (
		<>
			{ /* The group is always rendered, unlike AccessRuleValueNotice's: the first
			     keystroke clears the "not set" notice, and wrapping the inputs only while
			     a notice shows would rebuild them mid-entry and drop the editor's focus. */ }
			<Stack role="group" aria-label={ label } aria-describedby={ notice ? noticeId : undefined } align="flex-start" gap="sm">
				<div className="newspack-range-rule__field">
					<TextControl
						label={ __( 'Minimum', 'newspack-plugin' ) }
						type="number"
						value={ range.min ?? '' }
						onChange={ ( input: string ) => update( 'min', input ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</div>
				<div className="newspack-range-rule__field">
					<TextControl
						label={ __( 'Maximum', 'newspack-plugin' ) }
						type="number"
						value={ range.max ?? '' }
						onChange={ ( input: string ) => update( 'max', input ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</div>
			</Stack>
			{ notice && (
				<p id={ noticeId } role="note" className="newspack-access-rule-values-notice">
					{ notice }
				</p>
			) }
		</>
	);
}
