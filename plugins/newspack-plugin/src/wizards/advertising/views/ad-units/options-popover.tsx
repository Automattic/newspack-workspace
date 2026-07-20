/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { MenuItem } from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';
import { ESCAPE } from '@wordpress/keycodes';

/**
 * Internal dependencies
 */
import { Button, Popover } from '../../../../../packages/components/src';

type OptionsPopoverProps = {
	/** Callback archiving the ad unit. */
	deleteLink: () => void;
	/** URL of the ad unit's edit screen. */
	editLink: string;
};

// WP boundary: MenuItem's typings accept only button-element props, but at
// runtime it forwards all props to Button, which renders an anchor (with
// link styling via `isLink`) when `href` is set.
const MenuItemLink = MenuItem as React.ComponentType< React.ComponentProps< typeof MenuItem > & { href?: string; isLink?: boolean } >;

const OptionsPopover = ( props: OptionsPopoverProps ) => {
	const [ isVisible, setIsVisible ] = useState( false );
	const toggleVisible = () => {
		setIsVisible( state => ! state );
	};
	const { deleteLink, editLink } = props;

	return (
		<>
			<Button
				className={ isVisible ? 'popover-active' : undefined }
				onClick={ toggleVisible }
				icon={ moreVertical }
				label={ __( 'More options', 'newspack-plugin' ) }
				tooltipPosition="bottom center"
			/>
			{ isVisible && (
				<Popover position="bottom left" onFocusOutside={ toggleVisible } onKeyDown={ event => ESCAPE === event.keyCode && toggleVisible }>
					<MenuItem onClick={ toggleVisible } className="screen-reader-text">
						{ __( 'Close Popover', 'newspack-plugin' ) }
					</MenuItem>
					<MenuItemLink href={ editLink } className="newspack-button" isLink>
						{ __( 'Edit', 'newspack-plugin' ) }
					</MenuItemLink>
					<MenuItem onClick={ deleteLink } className="newspack-button">
						{ __( 'Archive', 'newspack-plugin' ) }
					</MenuItem>
				</Popover>
			) }
		</>
	);
};

export default OptionsPopover;
