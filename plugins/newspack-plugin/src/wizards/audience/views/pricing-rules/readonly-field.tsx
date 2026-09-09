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
	FlexBlock,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalInputControl as InputControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

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
			<HStack className={ classnames( 'newspack-pricing-rules__readonly', { 'is-monospace': isMonospace } ) } alignment="center" spacing={ 2 }>
				{ /* The fill goes on the container: the backdrop paints over the value. */ }
				<FlexBlock className="newspack-pricing-rules__readonly-value">
					<InputControl
						id={ id }
						value={ value }
						placeholder={ placeholder }
						aria-describedby={ help ? `${ id }__help` : undefined }
						readOnly
						__next40pxDefaultSize
					/>
				</FlexBlock>
				{ children }
			</HStack>
		</BaseControl>
	);
}
