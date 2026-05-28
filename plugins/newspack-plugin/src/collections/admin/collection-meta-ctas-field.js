/**
 * Collection Meta CTAs Field component for handling Call-to-Action buttons.
 */

import { __ } from '@wordpress/i18n';
import { TextControl, Button, BaseControl, useBaseControlProps, SelectControl, Draggable } from '@wordpress/components';
import { useState, useCallback, useMemo } from '@wordpress/element';
import { MediaUpload } from '@wordpress/block-editor';
import { dragHandle } from '@wordpress/icons';
import PropTypes from 'prop-types';

import CollectionMetaAttachmentInfo, { attachmentCache } from './collection-meta-attachment-info';
import { isValidUrl } from './utils';

const CollectionMetaCtasField = ( { metaKey, meta, updateMeta, ...baseProps } ) => {
	const { baseControlProps, controlProps } = useBaseControlProps( baseProps );
	const [ fieldErrors, setFieldErrors ] = useState( {} );
	const [ draggedIndex, setDraggedIndex ] = useState( null );
	const [ dragOverIndex, setDragOverIndex ] = useState( null );
	const currentCtas = meta[ metaKey ] || [];

	const addCta = useCallback( () => {
		const newCtas = [ ...currentCtas, { type: 'link', label: '', url: '' } ];
		updateMeta( metaKey, newCtas );
	}, [ currentCtas, updateMeta, metaKey ] );

	const removeCta = useCallback(
		index => {
			const newCtas = currentCtas.filter( ( _, i ) => i !== index );
			updateMeta( metaKey, newCtas.length > 0 ? newCtas : null );
			setFieldErrors( prev => {
				const newErrors = { ...prev };
				delete newErrors[ `${ metaKey }_${ index }` ];
				return newErrors;
			} );
		},
		[ currentCtas, updateMeta, metaKey ]
	);

	const updateCta = useCallback(
		( index, field, value ) => {
			const newCtas = [ ...currentCtas ];
			newCtas[ index ] = { ...newCtas[ index ], [ field ]: value };
			updateMeta( metaKey, newCtas );
		},
		[ currentCtas, updateMeta, metaKey ]
	);

	const validateUrl = useCallback(
		( index, value ) => {
			setFieldErrors( prev => {
				const fieldKey = `${ metaKey }_${ index }`;
				if ( ! value || isValidUrl( value ) ) {
					const newErrors = { ...prev };
					delete newErrors[ fieldKey ];
					return newErrors;
				}
				return {
					...prev,
					[ fieldKey ]: __( 'Please enter a valid URL.', 'newspack-plugin' ),
				};
			} );
		},
		[ metaKey ]
	);

	const handleAttachmentSelect = useCallback(
		( index, media ) => {
			if ( media && media.mime === 'application/pdf' ) {
				const attachmentInfo = {
					url: media.source_url || media.url || '',
					title: media.title || 'file',
				};
				attachmentCache.set( media.id, attachmentInfo );

				const newCtas = [ ...currentCtas ];
				newCtas[ index ] = {
					...newCtas[ index ],
					type: 'attachment',
					id: media.id,
				};
				updateMeta( metaKey, newCtas );

				// Clear any existing error for this field
				setFieldErrors( prev => {
					const newErrors = { ...prev };
					delete newErrors[ `${ metaKey }_${ index }` ];
					return newErrors;
				} );
			} else {
				setFieldErrors( prev => ( {
					...prev,
					[ `${ metaKey }_${ index }` ]: __( 'Please upload a PDF file.', 'newspack-plugin' ),
				} ) );
			}
		},
		[ currentCtas, updateMeta, metaKey ]
	);

	const attachmentSelectHandlers = useMemo( () => {
		return currentCtas.map( ( _, index ) => media => handleAttachmentSelect( index, media ) );
	}, [ handleAttachmentSelect, currentCtas.length ] );

	// Drag and drop handlers.
	const handleDragStart = useCallback( index => {
		setDraggedIndex( index );
	}, [] );

	const handleDragEnd = useCallback(
		event => {
			const dropTarget = event.target.closest( '.cta-input-row' );
			if ( ! dropTarget ) {
				setDraggedIndex( null );
				setDragOverIndex( null );
				return;
			}

			const dropIndex = parseInt( dropTarget.id.replace( 'cta-', '' ), 10 );

			if ( draggedIndex !== null && ! isNaN( dropIndex ) && draggedIndex !== dropIndex ) {
				const newCtas = [ ...currentCtas ];
				const [ draggedItem ] = newCtas.splice( draggedIndex, 1 );
				newCtas.splice( dropIndex, 0, draggedItem );

				// Remap field errors to new indices.
				setFieldErrors( prev => {
					const newErrors = {};
					Object.entries( prev ).forEach( ( [ key, error ] ) => {
						const match = key.match( new RegExp( `^${ metaKey }_(\\d+)$` ) );
						if ( match ) {
							const oldIndex = parseInt( match[ 1 ], 10 );
							// Calculate new index after reordering.
							let newIndex;
							if ( oldIndex === draggedIndex ) {
								newIndex = dropIndex;
							} else if ( oldIndex < draggedIndex && oldIndex >= dropIndex ) {
								newIndex = oldIndex + 1;
							} else if ( oldIndex > draggedIndex && oldIndex <= dropIndex ) {
								newIndex = oldIndex - 1;
							} else {
								newIndex = oldIndex;
							}
							newErrors[ `${ metaKey }_${ newIndex }` ] = error;
						} else {
							newErrors[ key ] = error;
						}
					} );
					return newErrors;
				} );

				updateMeta( metaKey, newCtas );
			}
			setDraggedIndex( null );
			setDragOverIndex( null );
		},
		[ currentCtas, draggedIndex, updateMeta, metaKey ]
	);

	const handleDragOver = useCallback( index => {
		setDragOverIndex( index );
	}, [] );

	return (
		<BaseControl { ...baseControlProps }>
			{ currentCtas.map( ( cta, index ) => {
				const fieldErrorKey = `${ metaKey }_${ index }`;
				const hasFieldError = !! fieldErrors[ fieldErrorKey ];
				const isDragging = draggedIndex === index;
				const isDragOver = dragOverIndex === index;

				return (
					<Draggable
						key={ index }
						elementId={ `cta-${ index }` }
						transferData={ {} }
						onDragStart={ () => handleDragStart( index ) }
						onDragEnd={ handleDragEnd }
						onDragOver={ () => handleDragOver( index ) }
					>
						{ ( { onDraggableStart, onDraggableEnd } ) => (
							<div
								className={ `cta-input-row ${ isDragging ? 'cta-dragging' : '' } ${ isDragOver ? 'cta-drag-over' : '' }` }
								id={ `cta-${ index }` }
								onDragOver={ e => {
									e.preventDefault();
									setDragOverIndex( index );
								} }
								onDragLeave={ () => setDragOverIndex( null ) }
								onDrop={ e => {
									e.preventDefault();
									handleDragEnd( e );
								} }
							>
								<Button
									className="cta-drag-handle"
									icon={ dragHandle }
									draggable
									onDragStart={ onDraggableStart }
									onDragEnd={ onDraggableEnd }
									label={ __( 'Drag to reorder', 'newspack-plugin' ) }
								/>
								<TextControl
									value={ cta.label || '' }
									onChange={ value => updateCta( index, 'label', value ) }
									placeholder={ __( 'Enter a label…', 'newspack-plugin' ) }
									label={ __( 'Label', 'newspack-plugin' ) }
								/>
								<SelectControl
									value={ cta.type || 'link' }
									onChange={ value => updateCta( index, 'type', value ) }
									options={ [
										{ label: __( 'External Link', 'newspack-plugin' ), value: 'link' },
										{ label: __( 'File Upload', 'newspack-plugin' ), value: 'attachment' },
									] }
									label={ __( 'Type', 'newspack-plugin' ) }
								/>
								{ cta.type === 'link' ? (
									<TextControl
										value={ cta.url || '' }
										onChange={ value => updateCta( index, 'url', value ) }
										onBlur={ event => validateUrl( index, event.target.value ) }
										className={ hasFieldError ? 'meta-field-error' : '' }
										help={ fieldErrors[ fieldErrorKey ] }
										placeholder={ __( 'Enter the URL…', 'newspack-plugin' ) }
										label={ __( 'URL', 'newspack-plugin' ) }
									/>
								) : (
									<BaseControl
										label={ __( 'File', 'newspack-plugin' ) }
										className={ hasFieldError ? 'meta-field-error' : '' }
										help={ fieldErrors[ fieldErrorKey ] }
										id={ `${ controlProps.id }-attachment-${ index }` }
									>
										{ cta.id ? (
											<CollectionMetaAttachmentInfo attachmentId={ cta.id } onRemove={ () => updateCta( index, 'id', null ) } />
										) : (
											<MediaUpload
												onSelect={ attachmentSelectHandlers[ index ] }
												allowedTypes={ [ 'application/pdf' ] }
												render={ ( { open } ) => (
													<Button isSecondary isSmall onClick={ open } className="upload-button">
														{ __( 'Upload PDF', 'newspack-plugin' ) }
													</Button>
												) }
											/>
										) }
									</BaseControl>
								) }
								<Button isSecondary isSmall isDestructive onClick={ () => removeCta( index ) } className="remove-cta-button">
									{ __( 'Remove CTA', 'newspack-plugin' ) }
								</Button>
							</div>
						) }
					</Draggable>
				);
			} ) }
			<Button isSecondary onClick={ addCta } className="add-cta-button">
				{ __( 'Add CTA', 'newspack-plugin' ) }
			</Button>
		</BaseControl>
	);
};

CollectionMetaCtasField.propTypes = {
	metaKey: PropTypes.string.isRequired,
	meta: PropTypes.object.isRequired,
	updateMeta: PropTypes.func.isRequired,
};

export default CollectionMetaCtasField;
