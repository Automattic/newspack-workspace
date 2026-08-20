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
 * package styles against). It is mounted in the DataViews `header` slot.
 *
 * Loading is left entirely to DataViews, which pulses the list and sets
 * `aria-busy` while a fetch is in flight. A second, custom indicator on
 * top of that read as two competing animations.
 */

import {
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { createPortal, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

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
 * @param {number}        props.value     Current `view.perPage`.
 * @param {Function}      props.onChange  Receives the new perPage value.
 * @param {Array<number>} [props.options] Selectable values; `PER_PAGE_ALL` renders as "All".
 */
export default function ItemsPerPage( { value, onChange, options = DEFAULT_PER_PAGE_OPTIONS } ) {
	const slot = usePopoverSlot();

	return (
		<>
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
