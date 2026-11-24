import { createRoot } from '@wordpress/element';
import { Stack, Text } from '@wordpress/ui';
import SectionHeader from '@np/section-header';
import { connectScreen } from 'newspack-playground/bridge';

createRoot( document.getElementById( 'root' ) ).render(
	<Stack direction="column" gap="xl">
		<SectionHeader title="Hello playground" description="Built against the workspace components." noMargin />
		<Text>This text is rendered by the playground fixture.</Text>
	</Stack>
);

connectScreen( { onSetState: () => {} } ).report( { ready: true } );
