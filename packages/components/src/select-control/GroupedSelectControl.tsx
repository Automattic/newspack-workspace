/**
 * WordPress dependencies
 */
import { BaseControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Icon, chevronDown } from '@wordpress/icons';

/**
 * External dependencies
 */
import classnames from 'classnames';
import find from 'lodash/find';
import some from 'lodash/some';

/**
 * Internal dependencies
 */
import { hooks } from '..';

export interface GroupedSelectControlOption {
	/** The option's label. */
	label: string;
	/** The option's value. */
	value: string;
	/** Whether the option is disabled. */
	disabled?: boolean;
}

export interface GroupedSelectControlOptgroup {
	/** The optgroup's label. */
	label: string;
	/** The optgroup's options. */
	options: GroupedSelectControlOption[];
}

type GroupedSelectControlProps = {
	/** Help text displayed under the control. */
	help?: React.ReactNode;
	/** Control label. */
	label?: React.ReactNode;
	/** Called with the selected value and the optgroup it belongs to. */
	onChange?( value: string, optgroup?: GroupedSelectControlOptgroup ): void;
	/** The options to display, grouped in optgroups. */
	optgroups?: GroupedSelectControlOptgroup[];
	/** Additional CSS class name. */
	className?: string;
	/** If true, the label will only be visible to screen readers. */
	hideLabelFromVision?: boolean;
} & Omit< React.SelectHTMLAttributes< HTMLSelectElement >, 'onChange' | 'className' >;

/**
 * SelectControl with optgroup support
 */
export default function GroupedSelectControl( {
	help,
	label,
	onChange,
	optgroups = [],
	className,
	hideLabelFromVision,
	...props
}: GroupedSelectControlProps ) {
	const onChangeValue = ( event: React.ChangeEvent< HTMLSelectElement > ) => {
		const { value } = event.target;
		const optgroup = find( optgroups, group => some( group.options, [ 'value', value ] ) );
		onChange?.( value, optgroup );
	};
	const id = hooks.useUniqueId( 'group-select' );

	return (
		<BaseControl
			label={ label }
			hideLabelFromVision={ hideLabelFromVision }
			id={ id }
			help={ help }
			className={ classnames( className, 'components-select-control' ) }
		>
			<div className="relative">
				<select
					id={ id }
					className="components-select-control__input"
					onChange={ onChangeValue }
					aria-describedby={ !! help ? `${ id }__help` : undefined }
					{ ...props }
				>
					<option value="">{ __( '-- Select --', 'newspack-plugin' ) }</option>
					{ optgroups.map( ( { label: optgroupLabel, options }, optgroupIndex ) => (
						<optgroup label={ optgroupLabel } key={ optgroupIndex }>
							{ options.map( ( option, optionIndex ) => (
								<option
									key={ `${ option.label }-${ option.value }-${ optionIndex }` }
									value={ option.value }
									disabled={ option.disabled }
								>
									{ option.label }
								</option>
							) ) }
						</optgroup>
					) ) }
				</select>
				<div className="components-select-control__arrow-wrapper">
					<Icon icon={ chevronDown } size={ 18 } />
				</div>
			</div>
		</BaseControl>
	);
}
