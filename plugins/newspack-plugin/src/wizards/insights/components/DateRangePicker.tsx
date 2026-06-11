/**
 * DateRangePicker
 *
 * Preset-driven date range selector. Six presets (last-7, last-30 default,
 * last-90, this-month, last-month, custom). Custom mode reveals two date
 * inputs.
 *
 * Uses @wordpress/components SelectControl for the preset dropdown per
 * Newspack admin convention. The custom date inputs stay as
 * <input type="date"> because WordPress doesn't ship a date control.
 *
 * Component owns no state — caller wires it to useDateRange.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { DATE_RANGE_PRESETS, type DateRange, type DateRangePreset } from '../state/useDateRange';

export interface DateRangePickerProps {
	range: DateRange;
	onPresetChange: ( preset: DateRangePreset ) => void;
	onCustomChange: ( start: string, end: string ) => void;
	className?: string;
}

const DateRangePicker = ( { range, onPresetChange, onCustomChange, className }: DateRangePickerProps ) => {
	const startId = 'newspack-insights-date-range-start';
	const endId = 'newspack-insights-date-range-end';
	const options = DATE_RANGE_PRESETS.map( p => ( { value: p.key, label: p.label } ) );

	return (
		<div className={ className ?? 'newspack-insights__date-range-picker' }>
			<SelectControl
				className="newspack-insights__date-range-picker-select"
				label={ __( 'Date range', 'newspack-plugin' ) }
				hideLabelFromVision
				__nextHasNoMarginBottom
				value={ range.preset }
				options={ options }
				onChange={ value => onPresetChange( value as DateRangePreset ) }
			/>

			{ range.preset === 'custom' && (
				<div className="newspack-insights__date-range-picker-custom">
					<label htmlFor={ startId }>
						<span className="screen-reader-text">{ __( 'Start date', 'newspack-plugin' ) }</span>
						<input id={ startId } type="date" value={ range.start } onChange={ e => onCustomChange( e.target.value, range.end ) } />
					</label>
					<span className="newspack-insights__date-range-picker-sep" aria-hidden="true">
						{ '→' }
					</span>
					<label htmlFor={ endId }>
						<span className="screen-reader-text">{ __( 'End date', 'newspack-plugin' ) }</span>
						<input id={ endId } type="date" value={ range.end } onChange={ e => onCustomChange( range.start, e.target.value ) } />
					</label>
				</div>
			) }
		</div>
	);
};

export default DateRangePicker;
