import { useInstanceId } from '@wordpress/compose';
import { Card } from '@wordpress/ui';

import ActionRow, { actionRowLabels } from './action-row';

export type LinkListItem = {
	key: string;
	title: string;
	description: string;
	icon: JSX.Element;
	href: string;
};

type LinkListProps = {
	label: string;
	items: LinkListItem[];
};

export default function LinkList( { label, items }: LinkListProps ) {
	const instanceId = useInstanceId( LinkList, 'setup-link-list' );

	return (
		<Card.Root render={ <ul /> } className="setup-link-list" aria-label={ label }>
			{ items.map( item => {
				const id = `${ instanceId }-${ item.key }`;
				return (
					<li key={ item.key } className="setup-link-list__item">
						<a href={ item.href } className="setup-action-row" { ...actionRowLabels( id ) }>
							<ActionRow id={ id } title={ item.title } description={ item.description } icon={ item.icon } />
						</a>
					</li>
				);
			} ) }
		</Card.Root>
	);
}
