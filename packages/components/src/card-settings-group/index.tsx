/**
 * Card - Settings group component.
 */

/**
 * External dependencies
 */
import classNames from 'classnames';

/**
 * Internal dependencies
 */
import { Card } from '../';
import type { CoreCardHeaderAction, CoreCardProps } from '../card/core-card';
import './style.scss';

const CardSettingsGroup = ( {
	actionType = 'none',
	children,
	className,
	disabled = false,
	icon = null,
	headerAction,
	title = '',
	description = '',
	isActive = false,
	onEnable = () => {},
	onHeaderClick,
}: {
	actionType?: 'chevron' | 'toggle' | 'button' | 'link' | 'none';
	children?: React.ReactNode;
	className?: string;
	disabled?: boolean;
	icon?: CoreCardProps[ 'icon' ];
	title: string;
	headerAction?: CoreCardHeaderAction;
	description?: string;
	isActive?: boolean;
	onEnable?: () => void;
	onHeaderClick?: () => void;
} ) => {
	return (
		<Card
			className={ classNames( 'newspack-card--core--settings-group', className ) }
			actionType={ actionType }
			isSmall
			__experimentalCoreCard
			__experimentalCoreProps={ {
				header: (
					<>
						<h3>{ title }</h3>
						{ description && <p>{ description }</p> }
					</>
				),
				headerAction,
				onHeaderClick,
				onToggle: onEnable,
				disabled,
				icon,
				iconBackgroundColor: true,
				isActive,
				title,
			} }
		>
			{ isActive && children }
		</Card>
	);
};

export default CardSettingsGroup;
