/* eslint @wordpress/no-unsafe-wp-apis: 0 */
import { __, sprintf } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect, useRef } from '@wordpress/element';
import {
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	__experimentalHeading as Heading,
	__experimentalText as Text,
	Panel,
	PanelBody,
	PanelRow,
	Button,
	Notice,
	Icon,
} from '@wordpress/components';
import { caution } from '@wordpress/icons';

import StoryFieldControl from './story-field-control';
import { NAMESPACE as storeNamespace } from '../store/constants';
import { getDisplayValue } from '../utils/fields';
import { useFields, useStoryField } from '../hooks';

const EMPTY_STRING = '';

const EditFieldPanel = ( { field, value, disabled = false, onChange, onToggle, initialOpen = false } ) => {
	const [ isOpen, setIsOpen ] = useState( initialOpen );

	useEffect( () => {
		onToggle( isOpen );
	}, [ isOpen ] );

	return (
		<PanelBody key={ field.slug } title={ field.name } opened={ isOpen } onToggle={ setIsOpen }>
			<PanelRow>
				<VStack style={ { width: '100%' } } spacing={ 2 }>
					{ field.description && <p className="newspack-story-budget__field-description">{ field.description }</p> }
					<StoryFieldControl field={ field } value={ value || EMPTY_STRING } onChange={ onChange } disabled={ disabled } />
					<HStack expanded direction="row-reverse" justify="end">
						<Button variant="secondary" disabled={ value === null || value === '' || disabled } onClick={ () => onChange( null ) }>
							{ __( 'Clear field', 'newspack-story-budget' ) }
						</Button>
						<Button variant="tertiary" disabled={ disabled } onClick={ () => setIsOpen( false ) }>
							{ __( 'Cancel changes', 'newspack-story-budget' ) }
						</Button>
					</HStack>
				</VStack>
			</PanelRow>
		</PanelBody>
	);
};

const FieldControl = ( { field, story, onChange, disabled } ) => {
	const fieldProps = useStoryField( story.id, field.slug );
	if ( ! fieldProps?.is_editable ) {
		return null;
	}
	return (
		<VStack spacing={ 2 }>
			<Heading level={ 5 }>{ fieldProps.name }</Heading>
			{ fieldProps.description && <p className="newspack-story-budget__field-description">{ fieldProps.description }</p> }
			<StoryFieldControl field={ fieldProps } value={ story[ fieldProps.slug ] || EMPTY_STRING } onChange={ onChange } disabled={ disabled } />
		</VStack>
	);
};

const getItemsTitle = items => {
	if ( ! items || ! items.length ) {
		return '';
	}

	if ( items.length === 1 ) {
		return items[ 0 ].name || items[ 0 ].title || items[ 0 ].id;
	}

	if ( items.length > 5 ) {
		return sprintf(
			/* translators: %d: number of stories */
			__( '%d stories', 'newspack-story-budget' ),
			items.length
		);
	}

	const titles = items.map( item => item.name || item.title || item.id );

	if ( titles.length === 2 ) {
		return sprintf(
			/* translators: %1$s: first item title, %2$s: second item title */
			__( '%1$s and %2$s', 'newspack-story-budget' ),
			titles[ 0 ],
			titles[ 1 ]
		);
	}

	const lastTitle = titles.pop();
	return sprintf(
		/* translators: %1$s: comma-separated list of item titles, %2$s: last item title */
		__( '%1$s, and %2$s', 'newspack-story-budget' ),
		titles.join( ', ' ),
		lastTitle
	);
};

export default ( { items, closeModal, onActionPerformed } ) => {
	const isBulk = items.length > 1;

	const { saveError, isSavingStories } = useSelect( select => ( {
		saveError: select( storeNamespace ).getSaveStoriesError(),
		isSavingStories: select( storeNamespace ).isSavingStories(),
	} ) );

	const fields = useFields();

	const [ previousSavingStories, setPreviousSavingStories ] = useState( false );

	const [ editedStory, setEditedStory ] = useState( isBulk ? {} : items[ 0 ] );

	const [ editedFields, setEditedFields ] = useState( isBulk ? [] : fields.filter( field => field.is_editable ).map( field => field.slug ) );

	const errorNoticeRef = useRef( null );

	const { saveStories, clearSaveStoriesErrors } = useDispatch( storeNamespace );

	if ( ! items || ! items.length ) {
		return null;
	}

	useEffect( () => {
		if ( ! isSavingStories && ! saveError && previousSavingStories ) {
			closeModal();
			onActionPerformed?.( items );
		}
		setPreviousSavingStories( isSavingStories );
	}, [ isSavingStories ] );

	useEffect( () => {
		if ( saveError ) {
			errorNoticeRef.current?.scrollIntoView( {
				behavior: 'smooth',
				block: 'center',
			} );
		}
	}, [ saveError ] );

	const nonBulkTypes = [ 'date', 'datetime', 'longtext', 'number' ];
	const bulkFields = fields.filter( field => ! nonBulkTypes.includes( field.type ) && field.is_editable && field.slug !== 'status' );

	const handleFieldToggle = field => open => {
		setEditedFields( open ? [ ...editedFields, field.slug ] : editedFields.filter( f => f !== field.slug ) );
		// If the field is being closed, clear the value.
		if ( ! open ) {
			setEditedStory( {
				...editedStory,
				[ field.slug ]: null,
			} );
		}
	};

	const handleSave = ev => {
		ev.preventDefault();
		clearSaveStoriesErrors();
		saveStories(
			items.map( item => item.id ),
			editedFields.map( field => ( {
				slug: field,
				value: editedStory[ field ] === null ? '' : editedStory[ field ], // API will skip null values, let's send empty strings instead.
			} ) )
		);
	};

	const itemsTitle = getItemsTitle( items );

	return (
		<form onSubmit={ handleSave }>
			<VStack spacing={ 4 }>
				<Heading level={ 3 }>
					{ items.length === 1
						? sprintf(
								// translators: %s is the title of the story.
								__( 'Editing %s', 'newspack-story-budget' ),
								itemsTitle
						  )
						: sprintf(
								// translators: %s is the title for the selected stories.
								__( 'Editing %s', 'newspack-story-budget' ),
								itemsTitle
						  ) }
				</Heading>
				<div ref={ errorNoticeRef }>
					{ saveError && (
						<Notice status="error" isDismissible={ false }>
							{ saveError }
						</Notice>
					) }
				</div>
				{ isBulk ? (
					<Panel>
						{ bulkFields.map( field => (
							<EditFieldPanel
								key={ field.slug }
								field={ field }
								value={ editedStory[ field.slug ] || EMPTY_STRING }
								onToggle={ handleFieldToggle( field ) }
								initialOpen={ editedFields.includes( field.slug ) }
								onChange={ data =>
									setEditedStory( {
										...editedStory,
										[ field.slug ]: data,
									} )
								}
								disabled={ isSavingStories }
							/>
						) ) }
					</Panel>
				) : (
					<VStack spacing={ 6 }>
						{ fields
							.filter( field => field.is_editable )
							.map( field => (
								<FieldControl
									key={ field.slug }
									field={ field }
									story={ editedStory }
									onChange={ data =>
										setEditedStory( {
											...editedStory,
											[ field.slug ]: data,
										} )
									}
									disabled={ isSavingStories }
								/>
							) ) }
					</VStack>
				) }
				{ editedFields.length > 0 && isBulk && (
					<VStack>
						<p>{ __( 'The following fields will be updated:', 'newspack-story-budget' ) }</p>
						{ editedFields.map( field => (
							<HStack key={ field } expanded className="newspack-story-budget__field-row">
								<Text>{ fields.find( f => f.slug === field ).name }</Text>
								<Text className="newspack-story-budget__field">
									<Text className="newspack-story-budget__field__value">
										{ getDisplayValue(
											fields.find( f => f.slug === field ),
											editedStory[ field ]
										) || (
											<Text className="newspack-story-budget__field__empty-value">
												<Icon icon={ caution } size={ 20 } />
												{ __( 'Will be empty', 'newspack-story-budget' ) }
											</Text>
										) }
									</Text>
								</Text>
							</HStack>
						) ) }
					</VStack>
				) }
				<HStack expanded direction="row-reverse" justify="end">
					<Button variant="primary" disabled={ ! editedFields.length || isSavingStories } isBusy={ isSavingStories } type="submit">
						{ items.length === 1
							? __( 'Save story', 'newspack-story-budget' )
							: sprintf(
									// translators: %d is the number of stories.
									__( 'Save %d stories', 'newspack-story-budget' ),
									items.length
							  ) }
					</Button>
					<Button variant="tertiary" onClick={ closeModal } disabled={ isSavingStories }>
						{ __( 'Cancel', 'newspack-story-budget' ) }
					</Button>
				</HStack>
			</VStack>
		</form>
	);
};
