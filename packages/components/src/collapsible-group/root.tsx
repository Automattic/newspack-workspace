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
import type { CollapsibleGroupItemProps, CollapsibleGroupProps } from './types';
import './style.scss';

const Root = ( { children, className, gap = 'xl', hideSingleTitle = false, titleLevel }: CollapsibleGroupProps ) => {
	const inheritedTitleLevel = useTitleLevel();
	const items = Children.toArray( children ).filter( isValidElement ) as React.ReactElement< CollapsibleGroupItemProps >[];

	const content =
		hideSingleTitle && items.length === 1 ? (
			<div className={ classNames( 'newspack-collapsible-group', className ) }>{ cloneElement( items[ 0 ], { title: undefined } ) }</div>
		) : (
			<Stack className={ classNames( 'newspack-collapsible-group', className ) } direction="column" gap={ gap }>
				{ items.map( ( item, index ) => (
					<Fragment key={ item.key }>
						{ item }
						{ index < items.length - 1 && <Divider variant="tertiary" marginBottom={ 0 } marginTop={ 0 } /> }
					</Fragment>
				) ) }
			</Stack>
		);

	return <TitleLevelContext.Provider value={ titleLevel ?? inheritedTitleLevel }>{ content }</TitleLevelContext.Provider>;
};

export default Root;
