/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	Button,
	Dropdown,
	RadioControl,
	__experimentalHeading as Heading, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { compose } from '@wordpress/compose';
import { withDispatch, withSelect } from '@wordpress/data';
import { useMemo, useState } from '@wordpress/element';
import { closeSmall, envelope, globe } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { getNewsletterVisibilityDescriptions } from '../../utils/service-provider';

const PublicSettingsComponent = ( { meta, updateIsPublic } ) => {
	const isPublic = !! meta.is_public;
	const currentLabel = isPublic ? __( 'Email and web', 'newspack-newsletters' ) : __( 'Email only', 'newspack-newsletters' );
	const currentIcon = isPublic ? globe : envelope;
	const visibilityDescriptions = getNewsletterVisibilityDescriptions();

	const [ popoverAnchor, setPopoverAnchor ] = useState( null );
	const popoverProps = useMemo(
		() => ( {
			anchor: popoverAnchor,
			placement: 'left-start',
			offset: 36,
			shift: true,
		} ),
		[ popoverAnchor ]
	);

	return (
		<HStack ref={ setPopoverAnchor } className="newspack-newsletters__visibility-row editor-post-panel__row">
			<div className="editor-post-panel__row-label">{ __( 'Visibility', 'newspack-newsletters' ) }</div>
			<div className="editor-post-panel__row-control">
				<Dropdown
					className="newspack-newsletters__visibility-dropdown"
					contentClassName="newspack-newsletters__visibility-dropdown-content"
					popoverProps={ popoverProps }
					focusOnMount
					renderToggle={ ( { isOpen, onToggle } ) => (
						<Button icon={ currentIcon } variant="tertiary" size="compact" aria-expanded={ isOpen } onClick={ onToggle }>
							{ currentLabel }
						</Button>
					) }
					renderContent={ ( { onClose } ) => (
						<VStack spacing={ 4 }>
							<HStack>
								<Heading level={ 2 } size={ 13 }>
									{ __( 'Newsletter visibility', 'newspack-newsletters' ) }
								</Heading>
								<Button size="small" icon={ closeSmall } label={ __( 'Close', 'newspack-newsletters' ) } onClick={ onClose } />
							</HStack>
							<RadioControl
								label={ __( 'Newsletter visibility', 'newspack-newsletters' ) }
								hideLabelFromVision
								selected={ isPublic ? 'public' : 'private' }
								options={ [
									{
										label: __( 'Email and web', 'newspack-newsletters' ),
										value: 'public',
										description: visibilityDescriptions.public,
									},
									{
										label: __( 'Email only', 'newspack-newsletters' ),
										value: 'private',
										description: visibilityDescriptions.private,
									},
								] }
								onChange={ value => updateIsPublic( value === 'public' ) }
							/>
						</VStack>
					) }
				/>
			</div>
		</HStack>
	);
};

const mapStateToProps = select => {
	const { getEditedPostAttribute } = select( 'core/editor' );

	return {
		meta: getEditedPostAttribute( 'meta' ),
	};
};

const mapDispatchToProps = dispatch => {
	const { editPost } = dispatch( 'core/editor' );

	return {
		updateIsPublic: value => editPost( { meta: { is_public: value } } ),
	};
};

export const PublicSettings = compose( [ withSelect( mapStateToProps ), withDispatch( mapDispatchToProps ) ] )( PublicSettingsComponent );
