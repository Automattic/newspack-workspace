/**
 * A labelled value the publisher cannot edit here, with one action beside it.
 * Built on BaseControl so the action sits inline with the input rather than
 * below the help text.
 */

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import {
	BaseControl,
	__experimentalInputControl as InputControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { Stack } from '@wordpress/ui';

interface ReadonlyFieldProps {
	id: string;
	label: string;
	help?: React.ReactNode;
	value: string;
	placeholder?: string;
	isMonospace?: boolean;
	children?: React.ReactNode;
}

export default function ReadonlyField( { id, label, help, value, placeholder, isMonospace, children }: ReadonlyFieldProps ) {
	return (
		<BaseControl id={ id } label={ label } help={ help } __nextHasNoMarginBottom>
			<Stack className={ classnames( 'newspack-pricing-rules__readonly', { 'is-monospace': isMonospace } ) } align="center" gap="sm">
				{ /* The fill goes on the container: the backdrop paints over the value. */ }
				<div className="newspack-pricing-rules__readonly-value">
					<InputControl
						id={ id }
						value={ value }
						placeholder={ placeholder }
						aria-describedby={ help ? `${ id }__help` : undefined }
						readOnly
						__next40pxDefaultSize
					/>
				</div>
				{ children }
			</Stack>
		</BaseControl>
	);
}
