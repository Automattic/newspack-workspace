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

/**
 * SelectControl with optgroup support
 */
export default function GroupedSelectControl( { help, label, onChange, optgroups = [], className, hideLabelFromVision, ...props } ) {
	const onChangeValue = event => {
		const { value } = event.target;
		const optgroup = find( optgroups, group => some( group.options, [ 'value', value ] ) );
		onChange( value, optgroup );
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
