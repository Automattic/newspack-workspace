/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import { Fragment, useState } from '@wordpress/element';
import { PluginDocumentSettingPanel, PluginPrePublishPanel, store as editPostStore } from '@wordpress/edit-post';
import { registerPlugin } from '@wordpress/plugins';
import { ToggleControl, TextControl, DatePicker, Notice, RangeControl, Button, Modal } from '@wordpress/components';
import { format, isInTheFuture } from '@wordpress/date';
import { SelectControl } from 'newspack-components';
import AdPlacements from '../../components/ad-placements';

function AdEdit() {
	const { price, startDate, expiryDate, insertionStrategy, positionInContent, positionBlockCount } = useSelect( select => {
		const { getEditedPostAttribute } = select( 'core/editor' );
		const meta = getEditedPostAttribute( 'meta' );
		return {
			price: meta.price,
			startDate: meta.start_date,
			expiryDate: meta.expiry_date,
			insertionStrategy: meta.insertion_strategy,
			positionInContent: meta.position_in_content,
			positionBlockCount: meta.position_block_count,
		};
	} );

	const { placements, placement } = useSelect( select => {
		return {
			placements: select( 'core' ).getEntityRecords( 'taxonomy', 'newspack_nl_ad_placement', {
				per_page: -1,
				hide_empty: false,
			} ),
			placement: select( 'core/editor' ).getEditedPostAttribute( 'ad_placement' ) || '',
		};
	} );
	const [ isNewPlacement, setIsNewPlacement ] = useState( false );
	const [ newPlacementName, setNewPlacementName ] = useState( '' );
	const [ isSavingPlacement, setIsSavingPlacement ] = useState( false );
	const [ isManagingPlacements, setIsManagingPlacements ] = useState( false );

	const { editPost } = useDispatch( 'core/editor' );
	const { saveEntityRecord } = useDispatch( 'core' );
	const { removeEditorPanel } = useDispatch( editPostStore );
	const messages = [];
	if ( expiryDate && ! isInTheFuture( expiryDate ) ) {
		messages.push( __( 'The expiration date is set in the past. This ad will not be displayed.', 'newspack-newsletters' ) );
	}
	if ( startDate && startDate > expiryDate ) {
		messages.push(
			sprintf(
				// translators: %s: date.
				__( 'The expiration date is set before the start date (%s). This ad will not be displayed.', 'newspack-newsletters' ),
				format( 'M j Y', startDate )
			)
		);
	}
	let defaultMessage;
	if ( startDate && expiryDate ) {
		defaultMessage = sprintf(
			// translators: %1$s: date, %2$s: date.
			__( 'This ad will be displayed between %1$s and %2$s.', 'newspack-newsletters' ),
			format( 'M j Y', startDate ),
			format( 'M j Y', expiryDate )
		);
	} else if ( startDate ) {
		defaultMessage = sprintf(
			// translators: %s: date.
			__( 'This ad will be displayed starting %s.', 'newspack-newsletters' ),
			format( 'M j Y', startDate )
		);
	} else if ( expiryDate ) {
		defaultMessage = sprintf(
			// translators: %s: date.
			__( 'This ad will be displayed until %s.', 'newspack-newsletters' ),
			format( 'M j Y', expiryDate )
		);
	}

	let noticeProps;
	if ( defaultMessage || messages.length ) {
		noticeProps = {
			children: messages.length ? messages.map( ( message, index ) => <p key={ index }>{ message }</p> ) : defaultMessage,
			status: messages.length ? 'warning' : 'info',
		};
	}

	// Remove the "post-status" (Summary) panel.
	removeEditorPanel( 'post-status' );

	return (
		<Fragment>
			<PluginDocumentSettingPanel name="newsletters-ads-settings-panel" title={ __( 'Ad settings', 'newspack-newsletters' ) }>
				<TextControl
					type="number"
					label={ __( 'Price', 'newspack-newsletters' ) }
					value={ price }
					onChange={ val => editPost( { meta: { price: val } } ) }
					min={ 0 }
					step={ 0.01 }
				/>
				<hr />
				<SelectControl
					label={ __( 'Insertion strategy', 'newspack-newsletters' ) }
					help={ __(
						'Whether to insert the ad at a percentage or block count of the newsletter content or assigned to a placement.',
						'newspack-newsletters'
					) }
					value={ insertionStrategy }
					onChange={ insertion_strategy => editPost( { meta: { insertion_strategy } } ) }
					options={ [
						{
							value: 'percentage',
							label: __( 'Percentage', 'newspack-newsletters' ),
						},
						{
							value: 'block_count',
							label: __( 'Block count', 'newspack-newsletters' ),
						},
						{
							value: 'placement',
							label: __( 'Placement', 'newspack-newsletters' ),
						},
					] }
				/>
				{ insertionStrategy === 'placement' && (
					<>
						<SelectControl
							label={ __( 'Placement', 'newspack-newsletters' ) }
							value={ isNewPlacement ? 'new' : placement }
							onChange={ value => {
								if ( ! value ) {
									editPost( { ad_placement: null } );
									setIsNewPlacement( false );
								} else if ( value === 'new' ) {
									setIsNewPlacement( true );
								} else {
									editPost( { ad_placement: [ value ] } );
									setIsNewPlacement( false );
								}
							} }
							options={ [
								{
									value: '',
									label: __( 'Select a placement', 'newspack-newsletters' ),
								},
								{
									value: 'new',
									label: __( 'New Placement', 'newspack-newsletters' ),
								},
								...( placements || [] ).map( p => ( {
									value: p.id,
									label: p.name,
								} ) ),
							] }
						/>
						{ isNewPlacement && (
							<TextControl
								label={ __( 'New Placement Name', 'newspack-newsletters' ) }
								help={ __( 'Press Enter to save the new placement.', 'newspack-newsletters' ) }
								value={ newPlacementName }
								disabled={ isSavingPlacement }
								onChange={ setNewPlacementName }
								onKeyDown={ async ev => {
									if ( ev.key === 'Enter' ) {
										// If it matches an existing placement, use that.
										const existingPlacement = placements.find(
											p => p.name.toLowerCase().trim() === newPlacementName.toLowerCase().trim()
										);
										if ( existingPlacement ) {
											editPost( {
												ad_placement: [ existingPlacement.id ],
											} );
										} else {
											setIsSavingPlacement( true );
											const newPlacement = await saveEntityRecord( 'taxonomy', 'newspack_nl_ad_placement', {
												name: newPlacementName.trim(),
											} );
											if ( newPlacement ) {
												await editPost( {
													ad_placement: [ newPlacement.id ],
												} );
											}
										}
										setNewPlacementName( '' );
										setIsNewPlacement( false );
										setIsSavingPlacement( false );
									}
								} }
							/>
						) }
						<Button variant="secondary" onClick={ () => setIsManagingPlacements( true ) }>
							{ __( 'Manage placements', 'newspack-newsletters' ) }
						</Button>
						{ isManagingPlacements && (
							<Modal
								title={ __( 'Manage Ad Placements', 'newspack-newsletters' ) }
								size="small"
								onRequestClose={ () => setIsManagingPlacements( false ) }
							>
								<AdPlacements />
							</Modal>
						) }
					</>
				) }
				{ insertionStrategy === 'percentage' && (
					<Fragment>
						<RangeControl
							label={ __( 'Approximate position (in percent)' ) }
							value={ positionInContent }
							onChange={ position_in_content => editPost( { meta: { position_in_content } } ) }
							min={ 0 }
							max={ 100 }
						/>
						<p>
							{ sprintf(
								// translators: %s: percentage.
								__( 'The ad will be automatically inserted about %s into the newsletter content.', 'newspack-newsletters' ),
								positionInContent + '%'
							) }
						</p>
					</Fragment>
				) }
				{ insertionStrategy === 'block_count' && (
					<Fragment>
						<RangeControl
							label={ __( 'Approximate position (in blocks)' ) }
							value={ positionBlockCount }
							onChange={ position_block_count => editPost( { meta: { position_block_count } } ) }
							min={ 0 }
							max={ 30 }
						/>
						<p>
							{ sprintf(
								// translators: %d: number.
								__( 'The ad will be automatically inserted after %d blocks of newsletter content.', 'newspack-newsletters' ),
								positionBlockCount
							) }
						</p>
					</Fragment>
				) }
				<hr />
				<ToggleControl
					label={ __( 'Custom Start Date', 'newspack-newsletters' ) }
					checked={ !! startDate }
					onChange={ () => {
						if ( startDate ) {
							editPost( { meta: { start_date: null } } );
						} else {
							editPost( { meta: { start_date: new Date() } } );
						}
					} }
				/>
				{ startDate ? (
					<DatePicker
						currentDate={ startDate }
						onChange={ start_date => editPost( { meta: { start_date } } ) }
						isInvalidDate={ date => ! isInTheFuture( date ) }
					/>
				) : null }
				<hr />
				<ToggleControl
					label={ __( 'Expiration Date', 'newspack-newsletters' ) }
					checked={ !! expiryDate }
					onChange={ () => {
						if ( expiryDate ) {
							editPost( { meta: { expiry_date: null } } );
						} else {
							editPost( {
								meta: {
									expiry_date: startDate ? new Date( startDate ) : new Date(),
								},
							} );
						}
					} }
				/>
				{ expiryDate ? (
					<DatePicker
						currentDate={ expiryDate }
						onChange={ expiry_date => editPost( { meta: { expiry_date } } ) }
						isInvalidDate={ date => {
							return startDate ? date < new Date( startDate ) : ! isInTheFuture( date );
						} }
					/>
				) : null }
			</PluginDocumentSettingPanel>
			{ noticeProps ? (
				<PluginPrePublishPanel>
					<Notice isDismissible={ false } { ...noticeProps } />
				</PluginPrePublishPanel>
			) : null }
		</Fragment>
	);
}

registerPlugin( 'newspack-newsletters-sidebar', {
	render: AdEdit,
	icon: null,
} );
