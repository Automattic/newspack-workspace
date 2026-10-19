/**
 * Controls for the published-age content rule: the gate's comparison and duration in the
 * Access control wizard, and the duration alone for a post's own time window in the post sidebar.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import {
	SelectControl,
	TextControl,
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import {
	AGE_MAX_COUNT,
	type AgeDuration,
	type AgeUnit,
	type PublishedAgeValue,
	isUsableAgeDuration,
	normalizeAgeDuration,
	normalizePublishedAgeValue,
} from '../utils/published-age';
import './published-age-rule-control.scss';

const isUsableCount = ( duration: AgeDuration ) => isUsableAgeDuration( { ...duration, duration_unit: duration.duration_unit || 'days' } );

const invalidCountMessage = () =>
	sprintf(
		// translators: %d: the largest number the field takes.
		__( 'Enter a whole number from 1 to %d.', 'newspack-plugin' ),
		AGE_MAX_COUNT
	);

/**
 * A number of days, months or years.
 */
export function AgeDurationFields( { value, onChange }: { value: unknown; onChange: ( value: AgeDuration ) => void } ) {
	const duration = normalizeAgeDuration( value );
	const isCountUsable = isUsableCount( duration );
	return (
		<Stack align="flex-start" gap="sm">
			<div className="newspack-published-age-rule__field">
				<TextControl
					label={ __( 'Duration', 'newspack-plugin' ) }
					type="number"
					min={ 1 }
					max={ AGE_MAX_COUNT }
					step={ 1 }
					value={ duration.duration_value || '' }
					help={ isCountUsable ? undefined : invalidCountMessage() }
					aria-invalid={ ! isCountUsable }
					onChange={ ( count: string ) => {
						// A fractional count stays as typed, so the field says why the server would refuse it.
						const next = { ...duration, duration_value: Math.max( 0, Number( count ) || 0 ) };
						if ( isCountUsable && ! isUsableCount( next ) ) {
							speak( invalidCountMessage() );
						}
						onChange( next );
					} }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			</div>
			<div className="newspack-published-age-rule__field">
				<SelectControl
					label={ __( 'Duration unit', 'newspack-plugin' ) }
					value={ duration.duration_unit }
					options={ [
						...( '' === duration.duration_unit
							? [ { value: '', label: __( 'Choose a unit', 'newspack-plugin' ), disabled: true } ]
							: [] ),
						{ value: 'days', label: __( 'Days', 'newspack-plugin' ) },
						{ value: 'months', label: __( 'Months', 'newspack-plugin' ) },
						{ value: 'years', label: __( 'Years', 'newspack-plugin' ) },
					] }
					onChange={ ( unit: string ) => onChange( { ...duration, duration_unit: unit as AgeUnit } ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			</div>
		</Stack>
	);
}

/**
 * The gate's age rule: published more than, or less than, a duration ago.
 */
export default function PublishedAgeRuleControl( {
	value,
	onChange,
	className,
}: {
	value: unknown;
	onChange: ( value: PublishedAgeValue ) => void;
	className?: string;
} ) {
	const age = normalizePublishedAgeValue( value );
	return (
		<Stack className={ className } direction="column" gap="lg">
			<ToggleGroupControl
				label={ __( 'Published', 'newspack-plugin' ) }
				value={ age.comparison }
				onChange={ comparison => onChange( { ...age, comparison: comparison as PublishedAgeValue[ 'comparison' ] } ) }
				help={
					'more_than' === age.comparison
						? __( 'The gate applies once a post is older than this, such as an archive behind a paywall.', 'newspack-plugin' )
						: __( 'The gate applies until a post is this old, then the post opens up.', 'newspack-plugin' )
				}
				isBlock
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			>
				<ToggleGroupControlOption label={ __( 'More than', 'newspack-plugin' ) } value="more_than" />
				<ToggleGroupControlOption label={ __( 'Less than', 'newspack-plugin' ) } value="less_than" />
			</ToggleGroupControl>
			<AgeDurationFields value={ age } onChange={ duration => onChange( { ...age, ...duration } ) } />
		</Stack>
	);
}
