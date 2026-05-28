/**
 * Form Token Field
 */

/**
 * WordPress dependencies.
 */
import { FormTokenField as BaseComponent } from '@wordpress/components';
import { Component } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import './style.scss';

/**
 * External dependencies.
 */
import classnames from 'classnames';

class FormTokenField extends Component {
	/**
	 * Render.
	 */
	render() {
		const { className, description, hideHelpFromVision, hideLabelFromVision, ...otherProps } = this.props;
		const classes = classnames( 'newspack-form-token-field__input-container', className );
		return (
			<div
				className={ classnames(
					{
						'newspack-form-token-field--label-hidden': hideLabelFromVision,
						'newspack-form-token-field--help-hidden': hideHelpFromVision,
					},
					'newspack-form-token-field'
				) }
			>
				<BaseComponent className={ classes } { ...otherProps } />
				{ description && <p className="newspack-form-token-field__help">{ description }</p> }
			</div>
		);
	}
}

export default FormTokenField;
