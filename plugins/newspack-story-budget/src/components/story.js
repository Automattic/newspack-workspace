/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import {
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalSpacer as Spacer,
	Icon,
	Spinner,
	Button,
	Notice,
} from '@wordpress/components';
import { notAllowed } from '@wordpress/icons';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useState, useCallback, useMemo } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import utils from '../utils';
import { NAMESPACE as storeNamespace } from '../store/constants';
import StoryFieldPanel from './story-field-panel';
import { useFields, useStory } from '../hooks';

export default ( { storyId, onCancel } ) => {
	const { isLoadingStory, canEditStory, storyError } = useSelect( select => ( {
		isLoadingStory: select( storeNamespace ).isLoadingStory( storyId ),
		canEditStory: select( storeNamespace ).canEditStory( storyId ),
		storyError: select( storeNamespace ).getStoryError( storyId ),
	} ) );
	const story = useStory( storyId );
	const fields = useFields();

	const [ editedStory, setEditedStory ] = useState( story );
	const [ isIframeLoading, setIsIframeLoading ] = useState( true );

	const { saveStory, clearErrors } = useDispatch( storeNamespace );

	useEffect( () => {
		clearErrors( storyId );
	}, [ storyId ] );

	const handleSave = useCallback( async () => {
		clearErrors( storyId );
		await saveStory( storyId, editedStory );
	}, [ clearErrors, storyId, editedStory, saveStory ] );

	const handleCancel = useCallback( () => {
		clearErrors( storyId );
		onCancel?.();
	}, [ clearErrors, storyId, onCancel ] );

	const handleFieldChange = useCallback(
		newStory => {
			clearErrors( storyId );
			setEditedStory( newStory );
		},
		[ clearErrors, storyId ]
	);

	const canPreview = useMemo( () => {
		return story?.metadata?.can_preview && ! utils.sites.isRemoteSite();
	}, [ story?.metadata?.can_preview, utils.sites.isRemoteSite ] );

	if ( ! story ) {
		if ( isLoadingStory ) {
			return (
				<VStack expanded style={ { height: '100%' } } alignment="center" justify="center">
					<HStack expanded alignment="center" justify="center">
						<Spinner />
					</HStack>
				</VStack>
			);
		}
		return null;
	}

	return (
		<HStack alignment="stretch" spacing="0" className="newspack-story-budget__story">
			<VStack style={ { flexGrow: 1, position: 'relative' } }>
				{ ! canPreview && (
					<VStack
						style={ {
							position: 'absolute',
							top: 0,
							left: 0,
							right: 0,
							bottom: 0,
							background: '#fff',
							zIndex: 2,
						} }
						alignment="center"
						justify="center"
					>
						<Icon icon={ notAllowed } size={ 32 } />
						{ utils.sites.isRemoteSite() ? (
							<>
								<p>{ __( 'Preview is unavailable when accessing remotely', 'newspack-story-budget' ) }</p>
								{ story.metadata?.preview_url && (
									<Button variant="secondary" href={ story.metadata?.preview_url } target="_blank">
										{ __( 'Preview in a new tab', 'newspack-story-budget' ) }
									</Button>
								) }
							</>
						) : (
							<p>{ __( 'Preview is unavailable', 'newspack-story-budget' ) }</p>
						) }
					</VStack>
				) }
				{ canPreview && isIframeLoading && (
					<VStack
						style={ {
							position: 'absolute',
							top: 0,
							left: 0,
							right: 0,
							bottom: 0,
							background: '#fff',
							zIndex: 2,
						} }
						alignment="center"
						justify="center"
					>
						<Spinner />
					</VStack>
				) }
				{ story.metadata?.can_preview && story?.metadata?.preview_url && (
					<iframe
						title={ story.title }
						src={ story.metadata.preview_url }
						style={ {
							width: '100%',
							height: '100%',
							minHeight: '500px',
						} }
						onLoad={ () => setIsIframeLoading( false ) }
					/>
				) }
			</VStack>
			<VStack justify="top" className="newspack-story-budget__sidebar">
				{ ! isIframeLoading && storyError && (
					<Notice className="newspack-story-budget__error" isDismissible={ false } status="error">
						{ storyError }
					</Notice>
				) }
				<div
					style={ {
						flexGrow: 1,
						justifyContent: 'flex-start',
						overflow: 'auto',
						padding: '16px',
					} }
				>
					<StoryFieldPanel fields={ fields } story={ story } onChange={ handleFieldChange } />
				</div>
				{ ( canEditStory || onCancel ) && (
					<HStack expanded direction="row-reverse" justify="end" style={ { padding: '16px', boxSizing: 'border-box' } }>
						{ canEditStory && (
							<Button variant="primary" disabled={ isLoadingStory } onClick={ handleSave }>
								{ __( 'Save', 'newspack-story-budget' ) }
							</Button>
						) }
						<Button variant="secondary" disabled={ isLoadingStory } onClick={ handleCancel }>
							{ canEditStory ? __( 'Cancel', 'newspack-story-budget' ) : __( 'Close', 'newspack-story-budget' ) }
						</Button>
						<Spacer />
						{ ! isLoadingStory && canEditStory && story.metadata.edit_url && (
							<Button variant="link" href={ story.metadata.edit_url } target="_blank">
								{ __( 'Edit post', 'newspack-story-budget' ) }
							</Button>
						) }
						{ isLoadingStory && <Spinner /> }
					</HStack>
				) }
			</VStack>
		</HStack>
	);
};
