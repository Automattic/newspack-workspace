/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { Icon, chevronRight } from '@wordpress/icons';

/**
 * External dependencies
 */
import classNames from 'classnames';

/**
 * Internal dependencies
 */
import './style.scss';

const Accordion = ( { children, title, defaultOpen = false } ) => {
	const [ isOpen, setIsOpen ] = useState( defaultOpen );
	return (
		<details
			className={ classNames( 'newspack-accordion', { 'newspack-accordion--is-open': isOpen } ) }
			open={ isOpen }
			onToggle={ e => setIsOpen( e.currentTarget.open ) }
		>
			<summary>
				{ title }
				<Icon className="newspack-accordion__icon" icon={ chevronRight } size={ 24 } />
			</summary>
			<div className="newspack-accordion__content">{ children }</div>
		</details>
	);
};

export default Accordion;
