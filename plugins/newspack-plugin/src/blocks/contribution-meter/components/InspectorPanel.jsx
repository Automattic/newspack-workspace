/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, PanelColorSettings } from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	BaseControl,
	DatePicker,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { dateI18n } from '@wordpress/date';

/**
 * Inspector panel component for contribution meter block settings.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to update attributes.
 * @return {Element} InspectorPanel component.
 */
const InspectorPanel = ( { attributes, setAttributes } ) => {
	const { goalAmount, startDate, endDate, progressBarColor, thickness, showGoal, showAmountRaised, showPercentage } = attributes;

	// Convert YYYY-MM-DD string to Date object at local midnight to avoid timezone issues.
	// Only set currentStartDate if startDate exists, otherwise let DatePicker default to today.
	const currentStartDate = startDate ? new Date( startDate + 'T00:00:00' ) : undefined;
	const currentEndDate = endDate ? new Date( endDate + 'T00:00:00' ) : undefined;
	const maxEndDateStr = window.newspack_contribution_meter_data?.maxEndDate;
	const maxEndDate = maxEndDateStr ? new Date( maxEndDateStr + 'T00:00:00' ) : undefined;

	const currencySymbol = window.newspack_contribution_meter_data?.currencySymbol || '$';

	return (
		<InspectorControls>
			<PanelColorSettings
				title={ __( 'Color', 'newspack-plugin' ) }
				colorSettings={ [
					{
						value: progressBarColor,
						onChange: value => setAttributes( { progressBarColor: value } ),
						label: __( 'Progress bar', 'newspack-plugin' ),
					},
				] }
			/>

			<PanelBody title={ __( 'Contribution data', 'newspack-plugin' ) }>
				<BaseControl
					id="contribution-meter-goal-amount"
					label={ __( 'Goal amount', 'newspack-plugin' ) + ` (${ currencySymbol })` }
					help={ __( 'Set the total contribution goal.', 'newspack-plugin' ) }
				>
					<input
						id="contribution-meter-goal-amount"
						type="number"
						value={ goalAmount }
						onChange={ e => setAttributes( { goalAmount: parseInt( e.target.value, 10 ) || 0 } ) }
						min="0"
						step="1"
						className="components-text-control__input"
						style={ { minHeight: '40px' } }
					/>
				</BaseControl>

				<BaseControl
					id="contribution-meter-start-date"
					label={ __( 'Start date', 'newspack-plugin' ) }
					help={ __( 'Contributions from this date are included in the total amount raised.', 'newspack-plugin' ) }
				>
					<DatePicker
						currentDate={ currentStartDate }
						isInvalidDate={ date => {
							const minDateStr = window.newspack_contribution_meter_data?.minStartDate;
							if ( ! minDateStr ) {
								return false;
							}
							const minDate = new Date( minDateStr );
							return date < minDate;
						} }
						onChange={ newDate => {
							setAttributes( { startDate: newDate ? dateI18n( 'Y-m-d', newDate ) : '' } );
						} }
					/>
				</BaseControl>

				<ToggleControl
					label={ __( 'Set end date', 'newspack-plugin' ) }
					checked={ !! endDate }
					onChange={ value => {
						if ( value ) {
							// Set to today when toggled on
							const today = new Date();
							setAttributes( { endDate: dateI18n( 'Y-m-d', today ) } );
						} else {
							// Clear end date when toggled off
							setAttributes( { endDate: '' } );
						}
					} }
					help={ __(
						'Enable this if the contribution meter should stop counting contributions after a specific date.',
						'newspack-plugin'
					) }
				/>

				{ endDate && (
					<BaseControl
						id="contribution-meter-end-date"
						label={ __( 'End date', 'newspack-plugin' ) }
						help={ __( 'Contributions up to and including this date are counted in the total amount raised.', 'newspack-plugin' ) }
					>
						<DatePicker
							currentDate={ currentEndDate }
							isInvalidDate={ date => {
								// End date requires a start date and must be after start date.
								if ( ! currentStartDate || date < currentStartDate ) {
									return true;
								}

								// End date cannot exceed the configured maximum.
								if ( maxEndDate && date > maxEndDate ) {
									return true;
								}

								return false;
							} }
							onChange={ newDate => {
								setAttributes( { endDate: newDate ? dateI18n( 'Y-m-d', newDate ) : '' } );
							} }
						/>
					</BaseControl>
				) }
			</PanelBody>

			<PanelBody title={ __( 'Progress bar', 'newspack-plugin' ) } initialOpen={ false }>
				<ToggleControl
					label={ __( 'Show goal', 'newspack-plugin' ) }
					checked={ showGoal }
					onChange={ value => setAttributes( { showGoal: value } ) }
					help={ __( 'Display the total target amount next to the progress bar.', 'newspack-plugin' ) }
				/>

				<ToggleControl
					label={ __( 'Show amount raised', 'newspack-plugin' ) }
					checked={ showAmountRaised }
					onChange={ value => setAttributes( { showAmountRaised: value } ) }
					help={ __( 'Display the total contributions received so far.', 'newspack-plugin' ) }
				/>

				<ToggleControl
					label={ __( 'Show percentage', 'newspack-plugin' ) }
					checked={ showPercentage }
					onChange={ value => setAttributes( { showPercentage: value } ) }
					help={ __( 'Display progress as a percentage of the goal.', 'newspack-plugin' ) }
				/>

				<ToggleGroupControl
					label={ __( 'Thickness', 'newspack-plugin' ) }
					value={ thickness }
					onChange={ value => setAttributes( { thickness: value } ) }
					isBlock
					help={ __( 'Adjust the visual weight of the progress bar.', 'newspack-plugin' ) }
					__next40pxDefaultSize
				>
					<ToggleGroupControlOption label={ __( 'XS', 'newspack-plugin' ) } value="xs" title={ __( 'Extra Small', 'newspack-plugin' ) } />
					<ToggleGroupControlOption label={ __( 'S', 'newspack-plugin' ) } value="s" title={ __( 'Small', 'newspack-plugin' ) } />
					<ToggleGroupControlOption label={ __( 'M', 'newspack-plugin' ) } value="m" title={ __( 'Medium', 'newspack-plugin' ) } />
					<ToggleGroupControlOption label={ __( 'L', 'newspack-plugin' ) } value="l" title={ __( 'Large', 'newspack-plugin' ) } />
				</ToggleGroupControl>
			</PanelBody>
		</InspectorControls>
	);
};

export default InspectorPanel;
