/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * WordPress dependencies.
 */
import { forwardRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon, info } from '@wordpress/icons';
import { getWpCompatOverlaySlot, Popover, VisuallyHidden } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import './style.scss';

type InfoButtonProps = React.ComponentPropsWithoutRef< 'button' > & {
	description?: string;
	triggerLabel?: string;
};

/**
 * Uses `Popover` rather than `Tooltip`: a tooltip never opens from a touch
 * pointer, and its popup is not exposed to assistive technology.
 */
const InfoButton = forwardRef< HTMLButtonElement, InfoButtonProps >( function InfoButton(
	{ description, className, triggerLabel, 'aria-label': ariaLabel, ...rest },
	ref
) {
	if ( ! description ) {
		return null;
	}

	const name = triggerLabel || ariaLabel || __( 'More information', 'newspack-plugin' );

	return (
		<Popover.Root>
			<Popover.Trigger
				ref={ ref }
				openOnHover
				delay={ 200 }
				closeDelay={ 200 }
				aria-label={ name }
				className={ classnames( 'newspack-info-button', className ) }
				{ ...rest }
			>
				<Icon icon={ info } size={ 20 } />
			</Popover.Trigger>
			<Popover.Popup
				variant="unstyled"
				className="newspack-info-button__popup"
				portal={ <Popover.Portal className="newspack-info-button__portal" container={ getWpCompatOverlaySlot() } /> }
				positioner={ <Popover.Positioner className="newspack-info-button__positioner" side="top" sideOffset={ 4 } /> }
			>
				<VisuallyHidden render={ <Popover.Title /> }>{ name }</VisuallyHidden>
				<Popover.Description>{ description }</Popover.Description>
			</Popover.Popup>
		</Popover.Root>
	);
} );

export default InfoButton;
