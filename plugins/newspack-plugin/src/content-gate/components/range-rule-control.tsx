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
import { speak } from '@wordpress/a11y';
import { TextControl } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useEffect, useRef } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { type AccessRuleShape, getRangeRuleValueNotice, normalizeRangeValue, type RangeValue } from '../utils/access-rule-value';
import './range-rule-control.scss';

/**
 * How long typing has to pause before the notice it left is announced, in
 * milliseconds. Typing a range passes through states that are briefly wrong: a
 * maximum of 1 on the way to 100 reads as inverted against a minimum of 50.
 */
export const ANNOUNCE_DELAY = 1000;

export default function RangeRuleControl( {
	config,
	value,
	onChange,
}: {
	config: AccessRuleShape & { name: string };
	value: unknown;
	onChange: ( value: RangeValue ) => void;
} ) {
	const range = normalizeRangeValue( value );
	const notice = getRangeRuleValueNotice( config, value );
	const noticeId = useInstanceId( RangeRuleControl, 'newspack-range-rule-notice' );

	// A notice raised by what the editor typed is announced once typing has paused for
	// ANNOUNCE_DELAY: every keystroke restarts the wait, so a notice that holds through
	// several keys (a maximum of 1, 10, 100 against a minimum of 5000) is not spoken
	// mid-entry, and one the next key clears is never spoken. Polite rather than
	// assertive, so it doesn't cut across the screen reader's echo of the keys. The
	// notice present on mount describes the stored value and stays silent, as standing
	// state does elsewhere in the editors.
	const boundsKey = `${ range.min ?? '' }|${ range.max ?? '' }`;
	const spokenNotice = useRef( notice );
	useEffect( () => {
		if ( ! notice ) {
			// Cleared: the next notice is news, even one matching the last.
			spokenNotice.current = notice;
			return;
		}
		if ( notice === spokenNotice.current ) {
			return;
		}
		const timer = setTimeout( () => {
			speak( notice, 'polite' );
			spokenNotice.current = notice;
		}, ANNOUNCE_DELAY );
		return () => clearTimeout( timer );
	}, [ notice, boundsKey ] );

	// The typed text is kept, trimmed, rather than parsed. A number input would report
	// text it can't parse as empty, which reads as a cleared bound and silently widens
	// the range; as text, it reaches the "must be numbers" notice, then the gate save's
	// refusal or, on a block, a rule that admits no one.
	const update = ( bound: keyof RangeValue, input: string ) => {
		const next = { ...range };
		if ( '' === input.trim() ) {
			delete next[ bound ];
		} else {
			next[ bound ] = input.trim();
		}
		onChange( next );
	};

	const fieldProps = {
		inputMode: 'decimal' as const,
		'aria-describedby': notice ? noticeId : undefined,
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
	};

	return (
		<>
			{ /* The group is always rendered, unlike AccessRuleValueNotice's: the first
			     keystroke clears the "not set" notice, and wrapping the inputs only while
			     a notice shows would rebuild them mid-entry and drop the editor's focus. */ }
			<Stack role="group" aria-label={ config.name } align="flex-start" gap="sm">
				<div className="newspack-range-rule__field">
					<TextControl
						label={ __( 'Minimum', 'newspack-plugin' ) }
						value={ String( range.min ?? '' ) }
						onChange={ ( input: string ) => update( 'min', input ) }
						{ ...fieldProps }
					/>
				</div>
				<div className="newspack-range-rule__field">
					<TextControl
						label={ __( 'Maximum', 'newspack-plugin' ) }
						value={ String( range.max ?? '' ) }
						onChange={ ( input: string ) => update( 'max', input ) }
						{ ...fieldProps }
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
