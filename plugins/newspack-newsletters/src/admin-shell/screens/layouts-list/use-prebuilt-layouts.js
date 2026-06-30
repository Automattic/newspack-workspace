/**
 * One-shot fetch of prebuilt layouts (JSON-seeded, not WP posts).
 * Normalises the legacy shape into the REST shape the DataView expects.
 */

import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

export default function usePrebuiltLayouts() {
	const [ layouts, setLayouts ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );

	useEffect( () => {
		let cancelled = false;
		// `defaults_only=1` keeps the endpoint from also fetching the
		// saved-layouts WP_Query.
		apiFetch( { path: '/newspack-newsletters/v1/layouts?defaults_only=1' } )
			.then( items => {
				if ( cancelled || ! Array.isArray( items ) ) {
					return;
				}
				// `post_author === undefined` distinguishes JSON-seeded
				// prebuilts from real posts; client-side filter is a safety
				// net for older builds without `defaults_only` support.
				const prebuilts = items
					.filter( item => item && item.post_author === undefined )
					.map( ( item, idx ) => {
						const fallbackTitle = sprintf(
							/* translators: %d: 1-based prebuilt index. */
							__( 'Prebuilt %d', 'newspack-newsletters' ),
							idx + 1
						);
						return {
							id: `prebuilt-${ item.ID }`,
							is_prebuilt: true,
							title: {
								raw: item.post_title || fallbackTitle,
								rendered: item.post_title || fallbackTitle,
							},
							content: {
								raw: item.post_content || '',
								rendered: item.post_content || '',
							},
							modified: null,
							meta: {},
							// id=0 is unreachable for real users — doubles as the
							// prebuilt sentinel for the author filter.
							author: 0,
							_embedded: { author: [ { id: 0, name: __( 'Newspack', 'newspack-newsletters' ) } ] },
						};
					} );
				setLayouts( prebuilts );
			} )
			.catch( () => {
				// Prebuilts are auxiliary; a failure here just hides the
				// locked cards, saved rows remain unaffected.
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setIsLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	return { layouts, isLoading };
}
