/**
 * WordPress dependencies.
 */
import { forwardRef, useMemo } from '@wordpress/element';
// Aliased: this package exports a different `Card` of its own.
import { Card as UICard, Stack } from '@wordpress/ui';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { resolveStatCardLabels, StatCardContext } from './context';
import type { StatCardRootProps } from './types';
import './style.scss';

const Root = forwardRef< HTMLDivElement, StatCardRootProps >( function Root( { heading = 3, labels, className, children, ...props }, ref ) {
	// Keyed on the strings rather than the object: a caller writing `labels` inline
	// hands over a fresh object each render, which would defeat the memo entirely.
	const { notApplicable, up, down } = labels ?? {};
	const context = useMemo(
		() => ( { heading, labels: resolveStatCardLabels( { notApplicable, up, down } ) } ),
		[ heading, notApplicable, up, down ]
	);

	return (
		<StatCardContext.Provider value={ context }>
			<UICard.Root ref={ ref } className={ classnames( 'newspack-stat-card', className ) } { ...props }>
				<UICard.Content render={ <Stack direction="column" gap="sm" /> } className="newspack-stat-card__content">
					{ children }
				</UICard.Content>
			</UICard.Root>
		</StatCardContext.Provider>
	);
} );

export default Root;
