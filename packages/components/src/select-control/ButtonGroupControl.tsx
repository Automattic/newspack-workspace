/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { Icon } from '@wordpress/icons';
import { BaseControl, Button, ButtonGroup } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { hooks } from '..';

export interface ButtonGroupControlOption {
	/** The option's label. */
	label?: React.ReactNode;
	/** The option's value. */
	value: string | number | boolean | null;
	/** Icon rendered instead of the label. */
	icon?: React.ComponentProps< typeof Icon >[ 'icon' ];
}

type ButtonGroupControlProps = {
	/** The options to display as buttons. */
	buttonOptions: ButtonGroupControlOption[];
	/** Whether to render small buttons. */
	buttonSmall?: boolean;
	/** Additional CSS class name. */
	className?: string;
	/** Help text displayed under the control. */
	help?: React.ReactNode;
	/** If true, the label will only be visible to screen readers. */
	hideLabelFromVision?: boolean;
	/** Control label. */
	label?: React.ReactNode;
	/** Called with the selected option's value. */
	onChange?: ( value: string | number | boolean | null ) => void;
	/** The selected value. */
	value?: string | number | boolean | null;
};

const ButtonGroupControl = ( {
	buttonOptions,
	buttonSmall,
	className,
	help,
	hideLabelFromVision,
	label,
	onChange,
	value,
}: ButtonGroupControlProps ) => {
	const id = hooks.useUniqueId( 'button-group' );
	return (
		<BaseControl
			label={ label }
			help={ help }
			hideLabelFromVision={ hideLabelFromVision }
			id={ id }
			className={ classnames( className, 'components-select-control' ) }
		>
			<ButtonGroup>
				{ buttonOptions.map( option => {
					const isSelected = value === option.value;
					const optionIcon = option.icon;
					let Label = () => option.label;
					if ( optionIcon ) {
						// eslint-disable-next-line react/display-name
						Label = () => <Icon icon={ optionIcon } />;
					}
					return (
						<Button
							key={ option.value === null ? undefined : String( option.value ) }
							variant={ isSelected ? 'primary' : undefined }
							isPressed={ isSelected }
							onClick={ () => onChange?.( option.value ) }
							isSmall={ buttonSmall }
						>
							<Label />
						</Button>
					);
				} ) }
			</ButtonGroup>
		</BaseControl>
	);
};

export default ButtonGroupControl;
