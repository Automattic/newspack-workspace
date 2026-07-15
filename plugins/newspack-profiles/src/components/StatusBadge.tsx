import { __ } from '@wordpress/i18n';
import Badge from 'newspack-components/dist/esm/badge';
import type { Status } from '../types/profile-collection';

const LEVEL: Record< Status, 'success' | 'warning' > = {
	publish: 'success',
	draft: 'warning',
};

export const StatusBadge = ( { status }: { status: Status } ) => {
	const label =
		status === 'publish'
			? __( 'Published', 'newspack-profiles' )
			: __( 'Draft', 'newspack-profiles' );

	return <Badge level={ LEVEL[ status ] ?? 'default' } text={ label } />;
};
