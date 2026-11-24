/**
 * The traffic split as two linked ranges, one per version, that always total
 * 100: moving either sets the other.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { RangeControl } from '@wordpress/components';

const MIN = 20;
const MAX = 80;

/**
 * @param {Object}   props
 * @param {number}   props.controlShare Percent of readers who see A.
 * @param {Function} props.onChange     Called with the new share for A.
 * @param {boolean}  props.disabled     Whether the ranges are read-only.
 * @param {string}   props.help         Help under the second range.
 */
const SplitControls = ( { controlShare, onChange, disabled = false, help } ) => (
	<>
		<RangeControl
			label={ __( 'Readers who see A', 'newspack-popups' ) }
			value={ controlShare }
			min={ MIN }
			max={ MAX }
			step={ 1 }
			onChange={ value => onChange( value ?? controlShare ) }
			disabled={ disabled }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
		<RangeControl
			label={ __( 'Readers who see B', 'newspack-popups' ) }
			value={ 100 - controlShare }
			min={ MIN }
			max={ MAX }
			step={ 1 }
			onChange={ value => onChange( 100 - ( value ?? 100 - controlShare ) ) }
			disabled={ disabled }
			help={ help }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	</>
);

export default SplitControls;
