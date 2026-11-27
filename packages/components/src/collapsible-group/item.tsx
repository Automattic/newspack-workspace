/**
 * WordPress dependencies
 */
import { useInstanceId } from '@wordpress/compose';
import { Icon, chevronDown } from '@wordpress/icons';
import { Collapsible } from '@wordpress/ui';

/**
 * External dependencies
 */
import classNames from 'classnames';

/**
 * Internal dependencies
 */
import { useTitleLevel } from './context';
import type { CollapsibleGroupItemProps } from './types';

const Item = ( { children, className, defaultOpen = false, description, title }: CollapsibleGroupItemProps ) => {
	const titleLevel = useTitleLevel();
	const descriptionId = useInstanceId( Item, 'newspack-collapsible-group-description' );

	if ( ! title ) {
		return <div className={ classNames( 'newspack-collapsible-group__item', className ) }>{ children }</div>;
	}

	const Heading = `h${ titleLevel }` as const;

	return (
		<Collapsible.Root className={ classNames( 'newspack-collapsible-group__item', className ) } defaultOpen={ defaultOpen }>
			<Heading className="newspack-collapsible-group__heading">
				<Collapsible.Trigger className="newspack-collapsible-group__trigger" aria-describedby={ description ? descriptionId : undefined }>
					{ title }
					<Icon icon={ chevronDown } size={ 24 } />
				</Collapsible.Trigger>
			</Heading>
			{ description && (
				<p className="newspack-collapsible-group__description" id={ descriptionId }>
					{ description }
				</p>
			) }
			<Collapsible.Panel className="newspack-collapsible-group__panel" hiddenUntilFound>
				<div className="newspack-collapsible-group__panel-inner">{ children }</div>
			</Collapsible.Panel>
		</Collapsible.Root>
	);
};

export default Item;
