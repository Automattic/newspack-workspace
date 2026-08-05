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
import './style.scss';

const CardSettingsGroup = ( {
	actionType = 'none',
	children,
	className,
	disabled = false,
	icon = null,
	iconElement = null,
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
	icon?: React.ReactNode;
	/** A ready-rendered icon element (e.g. `<IntegrationIcon>`) shown as-is, without the default icon badge. */
	iconElement?: React.ReactNode;
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
				iconElement,
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
