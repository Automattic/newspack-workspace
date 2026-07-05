/* globals newspack_blocks */

/**
 * WordPress dependencies
 */
import { useBlockProps } from '@wordpress/block-editor';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Edit function for the Content Gate Countdown block.
 *
 * @return {JSX.Element} The Content Gate Countdown block.
 */
export default function Edit() {
	const blockProps = useBlockProps( { className: 'newspack-content-gate-countdown__wrapper' } );
	const {
		loggedin_metered_views: loggedinViews,
		anonymous_metered_views: anonymousViews,
		metered_views: views,
	} = newspack_blocks.content_gate_data || {};
	// Admin is always logged in, so if no loggedin metered views are set, use the anonymous views instead.
	const totalViews = loggedinViews > 0 ? loggedinViews : anonymousViews;

	return (
		<div { ...blockProps }>
			<span className="newspack-content-gate-countdown">
				{ sprintf(
					/* translators: 1: current number of metered views, 2: total metered views. */ __( '%1$d/%2$d', 'newspack-plugin' ),
					parseInt( views ),
					parseInt( totalViews )
				) }
			</span>
		</div>
	);
}
