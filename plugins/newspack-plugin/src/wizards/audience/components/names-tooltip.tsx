/**
 * WordPress dependencies.
 */
import { getWpCompatOverlaySlot, Popover, VisuallyHidden } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import '../../../../packages/components/src/info-button/style.scss';
import './names-tooltip.scss';

type NamesTooltipProps = {
	/** The visible text, e.g. "+2 more products". */
	label: string;
	/** The names the label stands for, listed in the popup. */
	names: string[];
	/** Quieter secondary text, for a count that sits under the primary names. */
	muted?: boolean;
};

/**
 * A count in a list cell that lists what it stands for.
 *
 * Built on `Popover`, like `InfoButton`, so it opens on hover, focus and tap, and
 * the names are part of the trigger's accessible name at all times. The popup
 * opens below the trigger so it never covers the cell's own names. Renders the
 * label alone when there are no names to list.
 */
export default function NamesTooltip( { label, names, muted }: NamesTooltipProps ) {
	const className = muted ? 'newspack-names-tooltip is-muted' : 'newspack-names-tooltip';
	if ( ! names.length ) {
		return <span className={ muted ? className : undefined }>{ label }</span>;
	}
	const list = names.join( ', ' );
	return (
		<Popover.Root>
			<Popover.Trigger
				openOnHover
				delay={ 200 }
				closeDelay={ 200 }
				className={ className }
				aria-label={ `${ label }: ${ list }` }
				onClick={ ( event: React.MouseEvent ) => event.stopPropagation() }
			>
				{ label }
			</Popover.Trigger>
			<Popover.Popup
				variant="unstyled"
				className="newspack-info-button__popup"
				portal={ <Popover.Portal className="newspack-info-button__portal" container={ getWpCompatOverlaySlot() } /> }
				positioner={ <Popover.Positioner className="newspack-info-button__positioner" side="bottom" sideOffset={ 4 } /> }
			>
				<VisuallyHidden render={ <Popover.Title /> }>{ label }</VisuallyHidden>
				<Popover.Description>{ list }</Popover.Description>
			</Popover.Popup>
		</Popover.Root>
	);
}
