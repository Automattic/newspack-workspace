/**
 * WordPress dependencies.
 */
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import NamesTooltip from './names-tooltip';

type NamesSummaryProps = {
	/** The names shown outright, one per line. */
	shown: string[];
	/** The "+N more …" label for the names left out. */
	more?: string;
	/** The names the "+N more …" label stands for. */
	hidden?: string[];
};

/**
 * Names one per line with a "+N more …" tooltip trigger under them, so every
 * list that truncates names reads and spaces the same way.
 */
export default function NamesSummary( { shown, more, hidden = [] }: NamesSummaryProps ) {
	return (
		<Stack direction="column" gap="sm" align="flex-start" className="newspack-dataviews__stack">
			{ shown.map( ( name, index ) => (
				<div key={ `${ name }-${ index }` }>{ name }</div>
			) ) }
			{ more && <NamesTooltip muted label={ more } names={ hidden } /> }
		</Stack>
	);
}
