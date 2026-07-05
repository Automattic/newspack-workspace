/**
 * Info Button
 */

/**
 * WordPress dependencies.
 */
import { Component } from '@wordpress/element';
import { Tooltip } from '@wordpress/components';
import { Icon, info } from '@wordpress/icons';

/**
 * External dependencies.
 */
import classnames from 'classnames';
import type { ComponentProps } from 'react';

/**
 * Internal dependencies.
 */
import './style.scss';

type InfoButtonProps = Omit< ComponentProps< typeof Tooltip >, 'children' > & {
	className?: string;
};

class InfoButton extends Component< InfoButtonProps > {
	/**
	 * Render.
	 */
	render() {
		const { className, ...otherProps } = this.props;
		return (
			<Tooltip { ...otherProps }>
				<span className={ classnames( 'newspack-info-button', className ) }>
					<Icon icon={ info } />
				</span>
			</Tooltip>
		);
	}
}

export default InfoButton;
