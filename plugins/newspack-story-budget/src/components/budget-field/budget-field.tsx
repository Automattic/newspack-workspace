/**
 * WordPress dependencies.
 */
import { Dropdown } from '@wordpress/components';

/**
 * External dependencies.
 */
import type { ReactNode } from 'react';

export interface BudgetFieldProps {
	isOpen: boolean;
	toggleButton: ReactNode;
	popoverContent: ( onClose: () => void ) => ReactNode;
	onClose?: () => void;
	className?: string;
}

/**
 * Budget field base component
 */
const BudgetField = ( { isOpen, toggleButton, popoverContent, onClose = () => {}, className = '' }: BudgetFieldProps ) => {
	return (
		<div className={ className }>
			<Dropdown
				open={ isOpen }
				popoverProps={ {
					placement: 'bottom-start',
					shift: true,
				} }
				className="newspack-story-budget__field__dropdown-buttons"
				contentClassName="newspack-story-budget__field__popover"
				onClose={ onClose }
				renderToggle={ () => toggleButton }
				renderContent={ ( { onClose: popoverOnClose } ) => popoverContent( popoverOnClose ) }
			/>
		</div>
	);
};

export default BudgetField;
