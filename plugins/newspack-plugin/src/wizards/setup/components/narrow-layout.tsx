import type { ReactNode } from 'react';

import { Stack } from '@wordpress/ui';

import Grid from '../../../../packages/components/src/grid';

type NarrowLayoutProps = {
	children?: ReactNode;
	footer?: ReactNode;
};

export default function NarrowLayout( { children, footer }: NarrowLayoutProps ) {
	return (
		<Grid columns={ 4 } noMargin>
			<Stack direction="column" data-start="2" data-end="4">
				{ children && (
					<Stack direction="column" gap="2xl">
						{ children }
					</Stack>
				) }
				{ footer }
			</Stack>
		</Grid>
	);
}
