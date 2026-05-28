/* eslint @wordpress/no-unsafe-wp-apis: 0 */

/**
 * WordPress dependencies
 */
import {
	Button,
	Dropdown,
	__experimentalVStack as VStack,
	RadioControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useState, useMemo } from '@wordpress/element';
import { __experimentalInspectorPopoverHeader as InspectorPopoverHeader } from '@wordpress/block-editor';
import { drafts, published, pending } from '../icons';

/**
 * Internal dependencies
 */
import PostPanelRow from './post-panel-row';

const postStatusesInfo = {
	draft: { label: __( 'Draft' ), icon: drafts },
	pending: { label: __( 'Pending' ), icon: pending },
	publish: { label: __( 'Published' ), icon: published },
};

const STATUS_OPTIONS = [
	{
		label: __( 'Draft' ),
		value: 'draft',
		description: __( 'Not ready to publish.' ),
	},
	{
		label: __( 'Pending' ),
		value: 'pending',
		description: __( 'Waiting for review before publishing.' ),
	},
	{
		label: __( 'Published' ),
		value: 'publish',
		description: __( 'Visible to everyone.' ),
	},
];

export default function PostStatus( {
	label,
	description,
	status,
	onChange,
	disabled,
} ) {
	const [ popoverAnchor, setPopoverAnchor ] = useState( null );
	// Memoize popoverProps to avoid returning a new object every time.
	const popoverProps = useMemo(
		() => ( {
			// Anchor the popover to the middle of the entire row so that it doesn't
			// move around when the label changes.
			anchor: popoverAnchor,
			'aria-label': label ? label : __( 'Status & visibility' ),
			headerTitle: label ? label : __( 'Status & visibility' ),
			placement: 'left-start',
			offset: 36,
			shift: true,
		} ),
		[ popoverAnchor ]
	);

	return (
		<PostPanelRow
			label={ __( 'Status' ) }
			description={ description }
			ref={ setPopoverAnchor }
		>
			<Dropdown
				className="editor-post-status"
				contentClassName="editor-change-status__content"
				popoverProps={ popoverProps }
				focusOnMount
				renderToggle={ ( { onToggle, isOpen } ) => (
					<Button
						className="editor-post-status__toggle"
						variant="tertiary"
						size="compact"
						onClick={ onToggle }
						disabled={ disabled }
						icon={ postStatusesInfo[ status ]?.icon }
						aria-label={ sprintf(
							// translators: %s: Current post status.
							__( 'Change status: %s' ),
							postStatusesInfo[ status ]?.label
						) }
						aria-expanded={ isOpen }
					>
						{ postStatusesInfo[ status ]?.label }
					</Button>
				) }
				renderContent={ ( { onClose } ) => (
					<>
						<InspectorPopoverHeader
							title={
								label ? label : __( 'Status & visibility' )
							}
							onClose={ onClose }
						/>
						{ description && <p>{ description }</p> }
						<form>
							<VStack spacing={ 4 }>
								<RadioControl
									className="editor-change-status__options"
									hideLabelFromVision
									label={ __( 'Status' ) }
									options={ STATUS_OPTIONS }
									onChange={ onChange } // Handle change
									selected={ status }
								/>
							</VStack>
						</form>
					</>
				) }
			/>
		</PostPanelRow>
	);
}
