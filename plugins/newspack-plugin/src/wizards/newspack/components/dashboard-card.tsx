/**
 * Newspack - Dashboard, Card
 *
 * Linked card shared by the Quick actions row and the section grids.
 */

/**
 * WordPress dependencies
 */
import { Icon } from '@wordpress/icons';
import { Card, Stack, Text } from '@wordpress/ui';

/**
 * Internal dependencies
 */
/* eslint import/namespace: ['error', { allowComputed: true }] */
import { icons } from './icons';

type DashboardCardProps = {
	href: string;
	icon: keyof typeof icons;
	title: string;
	description?: string;
};

const DashboardCard = ( { href, icon, title, description }: DashboardCardProps ) => (
	/* eslint-disable-next-line jsx-a11y/anchor-has-content -- content is supplied via the Card children through @wordpress/ui's render prop. */
	<Card.Root className="newspack-dashboard__card" render={ <a href={ href } /> }>
		<Card.Content className="newspack-dashboard__card-content">
			<Stack className="newspack-dashboard__card-layout" direction="row" gap="lg" align="center">
				<span className="newspack-dashboard__card-icon">
					<Icon size={ 24 } icon={ icon in icons ? icons[ icon ] : icons.help } />
				</span>
				<Stack direction="column" gap="xs">
					{ /* eslint-disable-next-line jsx-a11y/heading-has-content -- content is supplied via the Text children through @wordpress/ui's render prop. */ }
					<Text variant="heading-lg" render={ <h4 /> }>
						{ title }
					</Text>
					{ description && (
						<Text className="newspack-dashboard__card-description" variant="body-sm" render={ <p /> }>
							{ description }
						</Text>
					) }
				</Stack>
			</Stack>
		</Card.Content>
	</Card.Root>
);

export default DashboardCard;
