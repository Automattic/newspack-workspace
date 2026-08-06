/**
 * WordPress dependencies
 */
import { __, isRTL } from '@wordpress/i18n';
import {
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Card } from '../../../../../packages/components/src';
import { pathOptions, pathSummary, type PricingPath } from './recipes';

interface GoalCardsProps {
	selected: PricingPath | null;
	onSelect: ( goal: PricingPath ) => void;
}

export default function GoalCards( { selected, onSelect }: GoalCardsProps ) {
	const options = pathOptions();
	const selectedIndex = options.findIndex( opt => opt.value === selected );
	// With nothing selected the group still needs one tab stop: the first card.
	const activeIndex = selectedIndex === -1 ? 0 : selectedIndex;

	const onKeyDown = ( event: React.KeyboardEvent< HTMLDivElement > ) => {
		// The cards lay out in a flex row, so horizontal arrows follow writing direction.
		const nextKey = isRTL() ? 'ArrowLeft' : 'ArrowRight';
		const previousKey = isRTL() ? 'ArrowRight' : 'ArrowLeft';
		const forward = 'ArrowDown' === event.key || nextKey === event.key;
		const back = 'ArrowUp' === event.key || previousKey === event.key;
		if ( ! forward && ! back ) {
			return;
		}
		event.preventDefault();
		const next = ( activeIndex + ( forward ? 1 : -1 ) + options.length ) % options.length;
		onSelect( options[ next ].value );
		event.currentTarget.querySelectorAll< HTMLElement >( '[role="radio"]' )[ next ]?.focus();
	};

	return (
		<HStack spacing={ 4 } alignment="stretch" role="radiogroup" aria-label={ __( 'Rule goal', 'newspack-plugin' ) } onKeyDown={ onKeyDown }>
			{ options.map( ( opt, index ) => (
				<Card
					key={ opt.value }
					isSmall
					__experimentalCoreCard
					__experimentalCoreProps={ {
						as: 'button',
						type: 'button',
						header: (
							<>
								<span className="newspack-pricing-rules__goal-title">{ opt.label }</span>
								<span>{ pathSummary( opt.value ) }</span>
							</>
						),
						icon: opt.icon,
						iconBackgroundColor: true,
						isVertical: true,
						onClick: () => onSelect( opt.value ),
						isActive: opt.value === selected,
						role: 'radio',
						'aria-checked': opt.value === selected ? 'true' : 'false',
						tabIndex: index === activeIndex ? 0 : -1,
					} }
				/>
			) ) }
		</HStack>
	);
}
