import { __ } from '@wordpress/i18n';
import classNames from 'classnames';

export const StatusBadge = ( {
	status,
}: {
	status: 'publish' | 'draft' | 'disconnected';
} ) => {
	let label;

	if ( status === 'publish' ) {
		label = __( 'Published', 'newspack-profiles' );
	} else if ( status === 'draft' ) {
		label = __( 'Draft', 'newspack-profiles' );
	} else {
		label = __( 'Disconnected', 'newspack-profiles' );
	}

	return (
		<span
			className={ classNames( 'px-2 py-1 rounded text-center', {
				'bg-green-100 text-green-800': status === 'publish',
				'bg-yellow-100 text-yellow-800': status === 'draft',
				'bg-blue-100 text-blue-800': status === 'disconnected',
			} ) }
		>
			{ label }
		</span>
	);
};
