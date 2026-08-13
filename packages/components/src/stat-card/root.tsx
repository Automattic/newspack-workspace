/**
 * WordPress dependencies.
 */
import { useMemo } from '@wordpress/element';
import { Card } from '@wordpress/ui';

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

const Root = ( { heading = 3, className, children }: StatCardRootProps ) => {
	const context = useMemo( () => ( { heading } ), [ heading ] );

	return (
		<StatCardContext.Provider value={ context }>
			<Card.Root className={ classnames( 'newspack-stat-card', className ) }>
				<Card.Content className="newspack-stat-card__content">{ children }</Card.Content>
			</Card.Root>
		</StatCardContext.Provider>
	);
};

export default Root;
