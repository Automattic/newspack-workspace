/**
 * WordPress dependencies.
 */
import { Button, Tooltip } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import './names-tooltip.scss';

type NamesTooltipProps = {
	/** The visible text, e.g. "+2 more products". */
	label: string;
	/** The names the label stands for, listed in the tooltip. */
	names: string[];
};

/**
 * A count in a list cell that lists what it stands for in a tooltip.
 *
 * The trigger is a link-styled button, so it takes keyboard focus and the
 * tooltip opens on focus as well as hover. Renders the label alone when there
 * are no names to list.
 */
export default function NamesTooltip( { label, names }: NamesTooltipProps ) {
	if ( ! names.length ) {
		return <span>{ label }</span>;
	}
	return (
		<Tooltip text={ names.join( ', ' ) }>
			<Button variant="link" className="newspack-names-tooltip" onClick={ ( event: React.MouseEvent ) => event.stopPropagation() }>
				{ label }
			</Button>
		</Tooltip>
	);
}
