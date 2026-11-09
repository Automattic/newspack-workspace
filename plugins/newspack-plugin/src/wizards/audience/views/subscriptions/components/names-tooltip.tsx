/**
 * WordPress dependencies.
 */
import { Tooltip } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import './names-tooltip.scss';

type NamesTooltipProps = {
	/** The visible text, e.g. "+2 more products". */
	label: string;
	/** The names the label stands for, listed in the tooltip. */
	names: string[];
	/** Quieter secondary text, for a count that sits under the primary names. */
	muted?: boolean;
};

/**
 * A count in a list cell that lists what it stands for in a tooltip.
 *
 * The trigger is a plain button, so it takes keyboard focus and the
 * tooltip opens on focus as well as hover. Renders the label alone when there
 * are no names to list.
 */
export default function NamesTooltip( { label, names, muted }: NamesTooltipProps ) {
	if ( ! names.length ) {
		return <span className={ muted ? 'newspack-names-tooltip is-muted' : undefined }>{ label }</span>;
	}
	return (
		<Tooltip text={ names.join( ', ' ) }>
			<button
				type="button"
				className={ muted ? 'newspack-names-tooltip is-muted' : 'newspack-names-tooltip' }
				onClick={ ( event: React.MouseEvent ) => event.stopPropagation() }
			>
				{ label }
			</button>
		</Tooltip>
	);
}
