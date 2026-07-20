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

export type MoneyInputProps = {
	/** The currency symbol displayed next to the input. */
	currencySymbol: string;
	/** Validation message displayed under the input. */
	error?: React.ReactNode;
	/** The input's label. */
	label: string;
	/** Minimum allowed amount. */
	min?: number;
	/** The current amount. */
	value: string | number;
	/** Called with the new amount. */
	onChange: ( value: string ) => void;
};

/**
 * Settings for donation collection.
 */
class MoneyInput extends Component< MoneyInputProps > {
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
