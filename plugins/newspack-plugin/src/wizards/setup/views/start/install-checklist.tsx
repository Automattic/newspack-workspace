import { Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, check, closeSmall } from '@wordpress/icons';
import { Stack, Text, VisuallyHidden } from '@wordpress/ui';

import type { InstallRow, RowStatus } from './use-install';

const STATUS_TEXT: Record< RowStatus, () => string > = {
	waiting: () => __( 'Waiting', 'newspack-plugin' ),
	running: () => __( 'In progress', 'newspack-plugin' ),
	done: () => __( 'Done', 'newspack-plugin' ),
	failed: () => __( 'Failed', 'newspack-plugin' ),
};

function StatusIcon( { status }: { status: RowStatus } ) {
	if ( status === 'done' ) {
		return (
			<span className="setup-status setup-status--done" aria-hidden="true">
				<Icon icon={ check } size={ 20 } />
			</span>
		);
	}
	if ( status === 'failed' ) {
		return (
			<span className="setup-status setup-status--failed" aria-hidden="true">
				<Icon icon={ closeSmall } size={ 20 } />
			</span>
		);
	}
	if ( status === 'running' ) {
		return (
			<span className="setup-status" aria-hidden="true">
				<Spinner className="setup-status__spinner" />
			</span>
		);
	}
	return <span className="setup-status setup-status--waiting" aria-hidden="true" />;
}

export default function InstallChecklist( { rows }: { rows: InstallRow[] } ) {
	return (
		<Stack render={ <ul /> } direction="column" className="setup-checklist" aria-live="polite">
			{ rows.map( row => {
				const showCount = ( row.status === 'running' || row.status === 'failed' ) && row.total !== undefined;
				return (
					<Stack
						render={ <li /> }
						key={ row.key }
						align="center"
						gap="md"
						className="setup-checklist__row"
						data-status={ row.status }
						aria-atomic="true"
					>
						<StatusIcon status={ row.status } />
						<Text variant="body-md">{ row.label }</Text>
						<VisuallyHidden>{ STATUS_TEXT[ row.status ]() }</VisuallyHidden>
						{ showCount && (
							<Text variant="body-sm" className="setup-muted setup-checklist__meta" aria-hidden="true">
								{ sprintf(
									/* translators: 1: items finished so far, 2: items in total. */
									__( '%1$d of %2$d', 'newspack-plugin' ),
									row.done ?? 0,
									row.total ?? 0
								) }
							</Text>
						) }
					</Stack>
				);
			} ) }
		</Stack>
	);
}
