/**
 * WordPress dependencies
 */
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Children, Fragment, cloneElement } from '@wordpress/element';

/**
 * External dependencies
 */
import classNames from 'classnames';

/**
 * Internal dependencies
 */
import Divider from '../divider';
import { TitleLevelContext } from './context';
import type { CollapsibleGroupProps } from './types';
import './style.scss';

const Root = ( { children, className, hideSingleTitle = false, spacing = 6, titleLevel = 2 }: CollapsibleGroupProps ) => {
	const items = Children.toArray( children ) as React.ReactElement[];

	// With nothing to collapse against, a lone item can render open and untitled.
	if ( hideSingleTitle && items.length === 1 ) {
		return (
			<div className={ classNames( 'newspack-collapsible-group', className ) }>
				{ cloneElement( items[ 0 ], { defaultOpen: true, title: undefined } ) }
			</div>
		);
	}

	return (
		<TitleLevelContext.Provider value={ titleLevel }>
			<VStack className={ classNames( 'newspack-collapsible-group', className ) } spacing={ spacing }>
				{ items.map( ( item, index ) => (
					<Fragment key={ item.key }>
						{ item }
						{ index < items.length - 1 && <Divider variant="secondary" marginBottom={ 0 } marginTop={ 0 } /> }
					</Fragment>
				) ) }
			</VStack>
		</TitleLevelContext.Provider>
	);
};

export default Root;
