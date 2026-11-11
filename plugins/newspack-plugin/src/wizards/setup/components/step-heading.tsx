import type { ReactNode, Ref } from 'react';

import { Stack, Text } from '@wordpress/ui';

type StepHeadingProps = {
	title: string;
	lede: ReactNode;
	headingRef?: Ref< HTMLHeadingElement >;
};

export default function StepHeading( { title, lede, headingRef }: StepHeadingProps ) {
	return (
		<Stack direction="column" gap="xs" className="setup-heading">
			<h2 className="setup-heading__title" ref={ headingRef } tabIndex={ -1 }>
				{ title }
			</h2>
			<Text variant="body-lg" className="setup-heading__lede setup-muted" render={ <p /> }>
				{ lede }
			</Text>
		</Stack>
	);
}
