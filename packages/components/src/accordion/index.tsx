/**
 * WordPress dependencies
 */
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalVStack as VStack, PanelBody } from '@wordpress/components';
import { Children, Fragment, cloneElement } from '@wordpress/element';

/**
 * External dependencies
 */
import classNames from 'classnames';
import type { ReactElement, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import Divider from '../divider';
import './style.scss';

type AccordionPanelProps = {
	children?: ReactNode;
	className?: string;
	title?: string;
	defaultOpen?: boolean;
};

export const AccordionPanel = ( { children, className, title, defaultOpen = false }: AccordionPanelProps ) => (
	<PanelBody className={ className } title={ title } initialOpen={ defaultOpen }>
		{ children }
	</PanelBody>
);

type AccordionProps = {
	children?: ReactNode;
	className?: string;
	/** Render a lone panel open and untitled, with nothing to collapse against. */
	hideSingleTitle?: boolean;
	/** Vertical gap between panels, in 4px units. */
	spacing?: number;
};

const Accordion = ( { children, className, hideSingleTitle = false, spacing = 6 }: AccordionProps ) => {
	// Children are AccordionPanel elements; toArray drops nullish entries and keys the rest.
	const panels = Children.toArray( children ) as ReactElement[];
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
