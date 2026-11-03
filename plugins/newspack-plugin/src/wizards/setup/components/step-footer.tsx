import type { ReactNode } from 'react';

import { Stack } from '@wordpress/ui';

export default function StepFooter( { children }: { children: ReactNode } ) {
	return (
		<Stack direction="row" gap="sm" align="center" justify="flex-end" wrap="wrap" className="setup-footer">
			{ children }
		</Stack>
	);
}
