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
	/** An existing rule's goal cannot change, so the set becomes a record of the choice. */
	disabled?: boolean;
}

export default function GoalCards( { selected, onSelect, disabled = false }: GoalCardsProps ) {
	const options = pathOptions();
	const selectedIndex = options.findIndex( opt => opt.value === selected );
	// With nothing selected the group still needs one tab stop: the first card.
	const activeIndex = selectedIndex === -1 ? 0 : selectedIndex;

	const onKeyDown = ( event: React.KeyboardEvent< HTMLDivElement > ) => {
		if ( disabled ) {
			return;
		}
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
		<HStack
			spacing={ 4 }
			alignment="stretch"
			className="newspack-pricing-rules__goals"
			role="radiogroup"
			aria-label={ __( 'Rule goal', 'newspack-plugin' ) }
			onKeyDown={ onKeyDown }
		>
			{ options.map( ( opt, index ) => (
				<Card
					key={ opt.value }
					isSmall
					__experimentalCoreCard
					__experimentalCoreProps={ {
						as: 'button',
						type: 'button',
						// A heading inherits CoreCard's header typography instead of restating it.
						header: (
							<>
								<h3>{ opt.label }</h3>
								<span>{ pathSummary( opt.value ) }</span>
							</>
						),
						icon: opt.icon,
						iconBackgroundColor: true,
						isSelectable: true,
						isVertical: true,
						onClick: () => {
							if ( ! disabled ) {
								onSelect( opt.value );
							}
						},
						isActive: opt.value === selected,
						role: 'radio',
						'aria-checked': opt.value === selected ? 'true' : 'false',
						// Not `disabled`, so the chosen goal stays readable and focusable.
						'aria-disabled': disabled ? 'true' : undefined,
						tabIndex: index === activeIndex ? 0 : -1,
					} }
				/>
			) ) }
		</HStack>
	);
}
