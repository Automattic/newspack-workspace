/**
 * WordPress dependencies
 */
import { Children, Fragment, cloneElement, isValidElement } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

/**
 * External dependencies
 */
import classNames from 'classnames';

/**
 * Internal dependencies
 */
import Divider from '../divider';
import { TitleLevelContext, useTitleLevel } from './context';
import type { CollapsibleGroupItemProps, CollapsibleGroupProps, HeadingLevel } from './types';
import './style.scss';

const clampTitleLevel = ( level: unknown ): HeadingLevel | undefined =>
	Number.isFinite( level ) ? ( Math.min( 6, Math.max( 1, Math.round( level as number ) ) ) as HeadingLevel ) : undefined;

const Root = ( { children, className, gap = 'xl', hideSingleTitle = false, titleLevel }: CollapsibleGroupProps ) => {
	const inheritedTitleLevel = useTitleLevel();
	const items = Children.toArray( children ).filter( isValidElement ) as React.ReactElement< CollapsibleGroupItemProps >[];
	const hideTitle = hideSingleTitle && items.length === 1;

	return (
		<TitleLevelContext.Provider value={ clampTitleLevel( titleLevel ) ?? inheritedTitleLevel }>
			<Stack className={ classNames( 'newspack-collapsible-group', className ) } direction="column" gap={ gap }>
				{ items.map( ( item, index ) => (
					<Fragment key={ item.key }>
						{ hideTitle ? cloneElement( item, { title: undefined } ) : item }
						{ index < items.length - 1 && <Divider variant="tertiary" marginBottom={ 0 } marginTop={ 0 } /> }
					</Fragment>
				) ) }
			</Stack>
		</TitleLevelContext.Provider>
	);
};

export default Root;
