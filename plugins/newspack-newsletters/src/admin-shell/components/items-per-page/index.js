/**
 * Items-per-page control, rendered inside the DataViews "View options"
 * popover in the slot the built-in control occupies.
 *
 * The built-in `ItemsPerPageControl` is suppressed via
 * `config={ { perPageSizes: [] } }` because it can't express "All" —
 * its option labels are the raw numbers, so the `PER_PAGE_ALL`
 * sentinel would render as "-1". DataViews offers no slot inside the
 * popover, so this component portals a look-alike ToggleGroupControl
 * into the popover when it opens (anchored on the same class names the
 * package styles against). This component itself is mounted in the
 * DataViews `header` slot; while a fetch-all walk runs it overlays a
 * centered spinner + progress message on the list (the popover is
 * closed during a walk, and the header offers no room for it).
 */

import { speak } from '@wordpress/a11y';
import {
	Spinner,
	__experimentalText as Text, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { createPortal, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { DEFAULT_PER_PAGE_OPTIONS, PER_PAGE_ALL } from '../../utils/per-page';

const CONTAINER_CLASS = 'newspack-newsletters-items-per-page';

const optionLabel = option => ( option === PER_PAGE_ALL ? __( 'All', 'newspack-newsletters' ) : String( option ) );

// Watch for the "View options" popover and hand back a container placed
// where the built-in items-per-page control renders: inside
// `.dataviews-view-config`, before the Properties block.
//
// The popover renders in a `Popover` slot outside the DataViews root, so
// the observer has to sit on `body` — it's scoped to `childList` only
// (no attribute or character-data churn) and the callback is a pair of
// querySelectors that bail on the first one when the popover is closed.
function usePopoverSlot() {
	const [ slot, setSlot ] = useState( null );

	useEffect( () => {
		const ensureSlot = () => {
			const popover = document.querySelector( '.dataviews-view-config' );
			if ( ! popover ) {
				setSlot( null );
				return;
			}
			const existing = popover.querySelector( `.${ CONTAINER_CLASS }` );
			if ( existing ) {
				return;
			}
			const container = document.createElement( 'div' );
			container.className = CONTAINER_CLASS;
			const properties = popover.querySelector( '.dataviews-field-control' );
			if ( properties && properties.parentElement ) {
				properties.parentElement.insertBefore( container, properties );
			} else {
				popover.appendChild( container );
			}
			setSlot( container );
		};

		ensureSlot();
		const observer = new MutationObserver( ensureSlot );
		observer.observe( document.body, { childList: true, subtree: true } );
		return () => observer.disconnect();
	}, [] );

	return slot;
}

/**
 * @param {Object}        props
 * @param {number}        props.value      Current `view.perPage`.
 * @param {Function}      props.onChange   Receives the new perPage value.
 * @param {Array<number>} [props.options]  Selectable values; `PER_PAGE_ALL` renders as "All".
 * @param {Object|null}   [props.progress] Fetch-all progress (`{ loaded, total }`) or null.
 */
export default function ItemsPerPage( { value, onChange, options = DEFAULT_PER_PAGE_OPTIONS, progress = null } ) {
	const slot = usePopoverSlot();
	const isLoadingAll = !! progress;

	// The visible message updates once per batch — up to ~100 times on a
	// full walk — so it is not a live region. Announce the two moments
	// that matter instead.
	const wasLoadingAllRef = useRef( false );
	useEffect( () => {
		if ( isLoadingAll === wasLoadingAllRef.current ) {
			return;
		}
		wasLoadingAllRef.current = isLoadingAll;
		speak(
			isLoadingAll
				? __( 'Loading all items. This may take a moment.', 'newspack-newsletters' )
				: __( 'Finished loading items.', 'newspack-newsletters' ),
			'polite'
		);
	}, [ isLoadingAll ] );

	// Center on the admin content area, not the viewport — otherwise the
	// admin menu skews the overlay off-center.
	const wpbodyRect = progress ? document.getElementById( 'wpbody' )?.getBoundingClientRect() : null;

	return (
		<>
			{ progress &&
				createPortal(
					<VStack
						className="newspack-newsletters-fetch-all-progress"
						spacing={ 3 }
						alignment="center"
						style={ wpbodyRect ? { left: wpbodyRect.left + wpbodyRect.width / 2 } : undefined }
					>
						<Spinner />
						<Text weight={ 600 }>
							{ sprintf(
								/* translators: 1: number of items loaded so far, 2: total number of items. */
								__( 'Loading %1$s of %2$s…', 'newspack-newsletters' ),
								progress.loaded.toLocaleString(),
								progress.total.toLocaleString()
							) }
						</Text>
					</VStack>,
					document.body
				) }
			{ slot &&
				createPortal(
					<ToggleGroupControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						isBlock
						label={ __( 'Items per page', 'newspack-newsletters' ) }
						value={ value }
						onChange={ next => onChange( typeof next === 'number' ? next : parseInt( next, 10 ) ) }
					>
						{ options.map( option => (
							<ToggleGroupControlOption key={ option } value={ option } label={ optionLabel( option ) } />
						) ) }
					</ToggleGroupControl>,
					slot
				) }
		</>
	);
}
