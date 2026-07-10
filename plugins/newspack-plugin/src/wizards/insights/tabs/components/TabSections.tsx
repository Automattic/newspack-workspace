/**
 * TabSections — renders a tab's sections with a full-width Divider between each
 * one (never before the first). Falsy children (from conditionally-rendered
 * sections) are dropped first, so a divider only ever appears between two sections
 * that actually render — matching the old `&__section + &__section` CSS rule this
 * replaces, but with the shared Newspack Divider (as used in Access Control).
 */

/**
 * WordPress dependencies
 */
import { Children, Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Divider } from '../../../../../packages/components/src';

const TabSections = ( { children }: { children: React.ReactNode } ) => {
	const sections = Children.toArray( children ).filter( Boolean );
	// A gap-free flex column so the Divider's own 64px margins are the sole source
	// of inter-section spacing — uniform across tabs regardless of their container
	// gap (which varies), unlike the old adjacent-sibling CSS rule.
	return (
		<div className="newspack-insights__tab-sections">
			{ sections.map( ( section, index ) => (
				<Fragment key={ index }>
					{ index > 0 && <Divider alignment="full-width" variant="tertiary" /> }
					{ section }
				</Fragment>
			) ) }
		</div>
	);
};

export default TabSections;
