/**
 * WordPress dependencies.
 */
import {
	__experimentalNumberControl as NumberControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Notice } from '../../../../../../packages/components/src';

interface MeteringProps {
	description?: string;
	metering: Metering;
	onChange: React.Dispatch< React.SetStateAction< Metering > > | ( ( metering: Metering ) => void );
}

export default function Metering( { description, metering, onChange }: MeteringProps ) {
	const count = typeof metering.count === 'number' ? metering.count : parseInt( String( metering.count ), 10 );
	const isCountZero = ! isNaN( count ) && count === 0;

	return (
		<>
			<ToggleControl
				label={ __( 'Metering', 'newspack-plugin' ) }
				help={ description || __( 'Allow limited free views before access conditions apply.', 'newspack-plugin' ) }
				checked={ metering.enabled }
				onChange={ () => onChange( { ...metering, enabled: ! metering.enabled } ) }
			/>
			{ metering.enabled && (
				<>
					{ metering.enabled && isCountZero && (
						<Notice
							isWarning
							noticeText={ __(
								'Metering is enabled but the number of views is set to 0. Content will be gated for all readers.',
								'newspack-plugin'
							) }
						/>
					) }
					<NumberControl
						label={ __( 'Free views', 'newspack-plugin' ) }
						help={ __( 'Free views before the gate appears.', 'newspack-plugin' ) }
						min={ 1 }
						value={ count }
						onChange={ v => onChange( { ...metering, count: v !== undefined ? Number( v ) : 0 } ) }
						__next40pxDefaultSize
					/>
					<ToggleGroupControl
						label={ __( 'Reset period', 'newspack-plugin' ) }
						help={ __( 'How often free views reset.', 'newspack-plugin' ) }
						value={ metering.period }
						onChange={ v => onChange( { ...metering, period: v as Metering[ 'period' ] } ) }
						isBlock
						__next40pxDefaultSize
					>
						<ToggleGroupControlOption label={ __( 'Monthly', 'newspack-plugin' ) } value="month" />
						<ToggleGroupControlOption label={ __( 'Weekly', 'newspack-plugin' ) } value="week" />
					</ToggleGroupControl>
				</>
			) }
		</>
	);
}
