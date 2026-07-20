/**
 * WordPress dependencies
 */
import { __experimentalVStack as VStack, PanelBody } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Children, Fragment, cloneElement } from '@wordpress/element';

/**
 * External dependencies
 */
import classNames from 'classnames';

/**
 * Internal dependencies
 */
import Divider from '../divider';
import './style.scss';

export const AccordionPanel = ( { children, className, title, defaultOpen = false } ) => (
	<PanelBody className={ className } title={ title } initialOpen={ defaultOpen }>
		{ children }
	</PanelBody>
);

const Accordion = ( { children, className, hideSingleTitle = false, spacing = 6 } ) => {
	const panels = Children.toArray( children );
	// With nothing to collapse against, a lone panel can render open and untitled.
	if ( hideSingleTitle && panels.length === 1 ) {
		return (
			<div className={ classNames( 'newspack-accordion', className ) }>
				{ cloneElement( panels[ 0 ], { defaultOpen: true, title: undefined } ) }
			</div>
		);
	}
	return (
		<VStack className={ classNames( 'newspack-accordion', className ) } spacing={ spacing }>
			{ panels.map( ( panel, index ) => (
				<Fragment key={ panel.key }>
					{ panel }
					{ index < panels.length - 1 && <Divider variant="secondary" marginBottom={ 0 } marginTop={ 0 } /> }
				</Fragment>
			) ) }
		</VStack>
	);
};

export default Accordion;
