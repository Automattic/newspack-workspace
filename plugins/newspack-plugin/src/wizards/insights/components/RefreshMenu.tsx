/**
 * RefreshMenu — header kebab dropdown with a single "Refresh now" item.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { DropdownMenu } from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';

export interface RefreshMenuProps {
	onRefresh: () => void;
	disabled: boolean;
}

const RefreshMenu = ( { onRefresh, disabled }: RefreshMenuProps ) => (
	<DropdownMenu
		icon={ moreVertical }
		label={ __( 'Insights options', 'newspack-plugin' ) }
		className="newspack-insights__refresh-menu"
		controls={ [
			{
				title: __( 'Refresh now', 'newspack-plugin' ),
				onClick: onRefresh,
				isDisabled: disabled,
			},
		] }
	/>
);

export default RefreshMenu;
