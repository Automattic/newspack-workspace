/**
 * Card - Settings group component.
 */

/**
 * Internal dependencies
 */
import { Card } from '../';
import './style.scss';

const CardSettingsGroup = ( {
	actionType = 'none',
	children,
	icon = null,
	headerAction,
	title = '',
	description = '',
	isActive = false,
	onEnable = () => {},
	onHeaderClick = () => {},
}: {
	actionType?: 'chevron' | 'toggle' | 'button' | 'link' | 'none';
	children?: React.ReactNode;
	icon?: React.ReactNode;
	title: string;
	headerAction?: {
		label: string;
		icon?: React.ReactNode;
		href?: string;
		onClick?: () => void;
		disabled?: boolean;
		destructive?: boolean;
		tone?: 'primary' | 'secondary' | 'tertiary' | 'link';
		variant?: 'primary' | 'secondary' | 'tertiary' | 'link';
	};
	description?: string;
	isActive?: boolean;
	onEnable?: () => void;
	onHeaderClick?: () => void;
} ) => {
	return (
		<Card
			className="newspack-card--core--settings-group"
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
