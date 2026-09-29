/**
 * Newspack > Settings > Advanced Settings > Choice Toggle
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';

type ChoiceToggleProps = {
	label: string;
	help?: string;
	checked: boolean;
	onChange: ( checked: boolean ) => void;
	onLabel?: string;
	offLabel?: string;
	disabled?: boolean;
};

/**
 * An on/off setting as a two-option toggle group, "on" first.
 */
export default function ChoiceToggle( {
	label,
	help,
	checked,
	onChange,
	onLabel = __( 'Show', 'newspack-plugin' ),
	offLabel = __( 'Hide', 'newspack-plugin' ),
	disabled = false,
}: ChoiceToggleProps ) {
	return (
		<ToggleGroupControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			isBlock
			label={ label }
			help={ help }
			value={ checked ? 'on' : 'off' }
			onChange={ value => onChange( value === 'on' ) }
		>
			<ToggleGroupControlOption value="on" label={ onLabel } disabled={ disabled } />
			<ToggleGroupControlOption value="off" label={ offLabel } disabled={ disabled } />
		</ToggleGroupControl>
	);
}
