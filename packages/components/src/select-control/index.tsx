/**
 * Select Control
 *
 * @deprecated For new features, use the core `SelectControl` (and `__experimentalToggleGroupControl` for
 * grouped/button-style selections) from `@wordpress/components` instead of this wrapper. This component
 * is kept only for existing usages and should not be used in new code.
 */

/**
 * WordPress dependencies
 */
import { SelectControl as BaseComponent } from '@wordpress/components';
import { Component } from '@wordpress/element';

/**
 * External dependencies
 */
import classNames from 'classnames';

/**
 * Internal dependencies
 */
import './style.scss';
import GroupedSelectControl, { GroupedSelectControlOptgroup } from './GroupedSelectControl';
import ButtonGroupControl, { ButtonGroupControlOption } from './ButtonGroupControl';

export interface SelectControlOption {
	/** The option's label. */
	label: string;
	/** The option's value. */
	value: string;
	/** Whether the option is disabled. */
	disabled?: boolean;
}

export interface SelectControlProps {
	/** Additional CSS class name. */
	className?: string;
	/** Options grouped in optgroups. When set, renders a GroupedSelectControl. */
	optgroups?: GroupedSelectControlOptgroup[];
	/** Options displayed as a button group. When set, renders a ButtonGroupControl. */
	buttonOptions?: ButtonGroupControlOption[];
	/** Whether the button group renders small buttons. */
	buttonSmall?: boolean;
	/** Control label. */
	label?: React.ReactNode;
	/** Help text displayed under the control. */
	help?: React.ReactNode;
	/** If true, the label will only be visible to screen readers. */
	hideLabelFromVision?: boolean;
	/** Whether the control is disabled. */
	disabled?: boolean;
	/** Whether multiple selections are allowed. */
	multiple?: boolean;
	/** Whether a selection is required. */
	required?: boolean;
	/** The `name` attribute of the select element. */
	name?: string;
	/** The selected value(s). */
	value?: string | number | string[];
	/** The options to display. */
	options?: SelectControlOption[];
	/** Called with the selected value; grouped selects also pass the optgroup. */
	onChange?( value: never, extra?: never ): void;
	children?: never;
	[ propName: string ]: unknown;
}

class SelectControl extends Component< SelectControlProps > {
	/**
	 * Render.
	 */
	render() {
		const { className, optgroups, buttonOptions, buttonSmall, ...otherProps } = this.props;
		const classes = classNames(
			'newspack-select-control',
			optgroups && 'newspack-grouped-select-control',
			buttonOptions && 'newspack-buttons-select-control',
			className
		);
		// The mode-specific control receives the pass-through props; each mode
		// duck-types them (they are validated per-mode, not here).
		const passThroughProps = otherProps as Record< string, never >;
		return (
			<div className={ classes }>
				{ /* eslint-disable no-nested-ternary */ }
				{ optgroups ? (
					<GroupedSelectControl optgroups={ optgroups } { ...passThroughProps } />
				) : buttonOptions ? (
					<ButtonGroupControl buttonOptions={ buttonOptions } buttonSmall={ buttonSmall } { ...passThroughProps } />
				) : (
					<BaseComponent { ...passThroughProps } />
				) }
				{ /* eslint-enable no-nested-ternary */ }
			</div>
		);
	}
}

export default SelectControl;
