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

const ButtonGroupControl = ( { buttonOptions, buttonSmall, className, help, hideLabelFromVision, label, onChange, value } ) => {
	const id = hooks.useUniqueId( 'button-group' );
	return (
		<BaseControl
			label={ label }
			help={ help }
			hideLabelFromVision={ hideLabelFromVision }
			id={ id.current }
			className={ classnames( className, 'components-select-control' ) }
		>
			<ButtonGroup>
				{ buttonOptions.map( option => {
					const isSelected = value === option.value;
					let Label = () => option.label;
					if ( option.icon ) {
						// eslint-disable-next-line react/display-name
						Label = () => <Icon icon={ option.icon } />;
					}
					return (
						<Button
							key={ option.value }
							variant={ isSelected ? 'primary' : null }
							isPressed={ isSelected }
							onClick={ () => onChange( option.value ) }
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
