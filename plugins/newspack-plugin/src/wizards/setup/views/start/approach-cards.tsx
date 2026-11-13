import type { KeyboardEvent } from 'react';

import { useInstanceId } from '@wordpress/compose';
import { __, isRTL } from '@wordpress/i18n';
import { addCard, layout } from '@wordpress/icons';
import { Stack } from '@wordpress/ui';

import { Card } from '../../../../../packages/components/src';
import type { Approach } from './use-install';

type ApproachCardsProps = {
	value: Approach;
	onChange: ( value: Approach ) => void;
};

export default function ApproachCards( { value, onChange }: ApproachCardsProps ) {
	const instanceId = useInstanceId( ApproachCards, 'setup-approach' );
	const options: { value: Approach; label: string; description: string; icon: JSX.Element }[] = [
		{
			value: 'generated',
			label: __( 'Start a new site', 'newspack-plugin' ),
			description: __( 'Start fresh, with no content to bring over.', 'newspack-plugin' ),
			icon: addCard,
		},
		{
			value: 'import',
			label: __( 'Move an existing WordPress site', 'newspack-plugin' ),
			description: __( 'Bring over your latest 40 articles and branding.', 'newspack-plugin' ),
			icon: layout,
		},
	];
	const selectedIndex = options.findIndex( option => option.value === value );

	const onKeyDown = ( event: KeyboardEvent< HTMLDivElement > ) => {
		if ( event.altKey || event.ctrlKey || event.metaKey ) {
			return;
		}
		const nextKey = isRTL() ? 'ArrowLeft' : 'ArrowRight';
		const previousKey = isRTL() ? 'ArrowRight' : 'ArrowLeft';
		let next: number;
		if ( 'Home' === event.key ) {
			next = 0;
		} else if ( 'End' === event.key ) {
			next = options.length - 1;
		} else if ( 'ArrowDown' === event.key || nextKey === event.key ) {
			next = ( selectedIndex + 1 ) % options.length;
		} else if ( 'ArrowUp' === event.key || previousKey === event.key ) {
			next = ( selectedIndex - 1 + options.length ) % options.length;
		} else {
			return;
		}
		event.preventDefault();
		onChange( options[ next ].value );
		event.currentTarget.querySelectorAll< HTMLElement >( '[role="radio"]' )[ next ]?.focus();
	};

	return (
		<Stack
			direction="column"
			gap="lg"
			role="radiogroup"
			aria-label={ __( 'How do you want to start?', 'newspack-plugin' ) }
			onKeyDown={ onKeyDown }
		>
			{ options.map( ( option, index ) => (
				<Card
					key={ option.value }
					isSmall
					__experimentalCoreCard
					__experimentalCoreProps={ {
						as: 'button',
						type: 'button',
						header: (
							<>
								<h3 id={ `${ instanceId }-${ option.value }-label` }>{ option.label }</h3>
								<span id={ `${ instanceId }-${ option.value }-description` }>{ option.description }</span>
							</>
						),
						icon: option.icon,
						iconBackgroundColor: true,
						isSelectable: true,
						isActive: option.value === value,
						onClick: () => onChange( option.value ),
						role: 'radio',
						'aria-labelledby': `${ instanceId }-${ option.value }-label`,
						'aria-describedby': `${ instanceId }-${ option.value }-description`,
						'aria-checked': option.value === value ? 'true' : 'false',
						tabIndex: index === selectedIndex ? 0 : -1,
					} }
				/>
			) ) }
		</Stack>
	);
}
