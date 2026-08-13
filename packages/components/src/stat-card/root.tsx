/**
 * WordPress dependencies.
 */
import { forwardRef, useMemo } from '@wordpress/element';
// Aliased: this package exports a different `Card` of its own.
import { Card as UICard } from '@wordpress/ui';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { StatCardContext } from './context';
import type { StatCardRootProps } from './types';
import './style.scss';

const Root = forwardRef< HTMLDivElement, StatCardRootProps >( function Root( { heading = 3, className, children, ...props }, ref ) {
	const context = useMemo( () => ( { heading } ), [ heading ] );

	return (
		<StatCardContext.Provider value={ context }>
			<UICard.Root ref={ ref } className={ classnames( 'newspack-stat-card', className ) } { ...props }>
				<UICard.Content className="newspack-stat-card__content">{ children }</UICard.Content>
			</UICard.Root>
		</StatCardContext.Provider>
	);
} );

export default Root;
