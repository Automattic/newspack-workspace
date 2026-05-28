/**
 * Component for inputting money amounts.
 */

/**
 * WordPress dependencies
 */
import { Component } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { TextControl } from '../../../../../packages/components/src';
import './style.scss';

/**
 * Settings for donation collection.
 */
class MoneyInput extends Component {
	/**
	 * Render.
	 */
	render() {
		const { currencySymbol, error, label, min, value, onChange } = this.props;

		return (
			<div className="newspack-donations-wizard__money-input-container">
				<p className="input-label">{ label }</p>
				<div className="newspack-donations-wizard__money-input">
					<div className="currency">{ currencySymbol }</div>
					<TextControl type="number" hideLabelFromVision label={ label } min={ min } value={ value } onChange={ onChange } />
				</div>
				{ error && <p className="newspack-donations-wizard__money-input-error">{ error }</p> }
			</div>
		);
	}
}

export default MoneyInput;
