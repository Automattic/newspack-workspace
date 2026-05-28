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
import GroupedSelectControl from './GroupedSelectControl';
import ButtonGroupControl from './ButtonGroupControl';

class SelectControl extends Component {
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
		return (
			<div className={ classes }>
				{ /* eslint-disable no-nested-ternary */ }
				{ optgroups ? (
					<GroupedSelectControl optgroups={ optgroups } { ...otherProps } />
				) : buttonOptions ? (
					<ButtonGroupControl buttonOptions={ buttonOptions } buttonSmall={ buttonSmall } { ...otherProps } />
				) : (
					<BaseComponent { ...otherProps } />
				) }
				{ /* eslint-enable no-nested-ternary */ }
			</div>
		);
	}
}

export default SelectControl;
