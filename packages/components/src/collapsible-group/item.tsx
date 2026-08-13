/**
 * WordPress dependencies
 */
import { Icon, chevronDown } from '@wordpress/icons';
import { Collapsible } from '@wordpress/ui';

/**
 * External dependencies
 */
import classNames from 'classnames';

/**
 * Internal dependencies
 */
import type { CollapsibleGroupItemProps } from './types';

const Item = ( { children, className, defaultOpen = false, title }: CollapsibleGroupItemProps ) => {
	if ( ! title ) {
		return <div className={ classNames( 'newspack-collapsible-group__item', className ) }>{ children }</div>;
	}

	return (
		<Collapsible.Root className={ classNames( 'newspack-collapsible-group__item', className ) } defaultOpen={ defaultOpen }>
			{ /* Heading wraps the trigger, per the W3C accordion pattern. */ }
			<h2 className="newspack-collapsible-group__heading">
				<Collapsible.Trigger className="newspack-collapsible-group__trigger">
					{ title }
					<Icon icon={ chevronDown } size={ 24 } />
				</Collapsible.Trigger>
			</h2>
			{ /* `hiddenUntilFound` lets the browser's find-in-page expand a collapsed item. */ }
			<Collapsible.Panel className="newspack-collapsible-group__panel" hiddenUntilFound>
				<div className="newspack-collapsible-group__panel-inner">{ children }</div>
			</Collapsible.Panel>
		</Collapsible.Root>
	);
};

export default Item;
