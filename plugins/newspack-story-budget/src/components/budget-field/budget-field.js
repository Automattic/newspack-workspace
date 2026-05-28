/**
 * WordPress dependencies.
 */
import { Dropdown } from '@wordpress/components';

/**
 * Budget field base component
 */
const BudgetField = ( { isOpen, toggleButton, popoverContent, onClose = () => {}, className = '' } ) => {
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
