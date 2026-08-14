/**
 * WordPress dependencies
 */
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Children, Fragment, cloneElement, isValidElement } from '@wordpress/element';

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

const Root = ( { children, className, hideSingleTitle = false, spacing = 6, titleLevel }: CollapsibleGroupProps ) => {
	const inheritedTitleLevel = useTitleLevel();
	const items = Children.toArray( children ).filter( isValidElement ) as React.ReactElement< CollapsibleGroupItemProps >[];

	const content =
		hideSingleTitle && items.length === 1 ? (
			<div className={ classNames( 'newspack-collapsible-group', className ) }>{ cloneElement( items[ 0 ], { title: undefined } ) }</div>
		) : (
			<VStack className={ classNames( 'newspack-collapsible-group', className ) } spacing={ spacing }>
				{ items.map( ( item, index ) => (
					<Fragment key={ item.key }>
						{ item }
						{ index < items.length - 1 && <Divider variant="tertiary" marginBottom={ 0 } marginTop={ 0 } /> }
					</Fragment>
				) ) }
			</VStack>
		);

	return <TitleLevelContext.Provider value={ titleLevel ?? inheritedTitleLevel }>{ content }</TitleLevelContext.Provider>;
};

export default Root;
