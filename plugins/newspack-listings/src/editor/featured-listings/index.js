/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { BaseControl, Button, DatePicker, PanelRow, RangeControl, ToggleControl } from '@wordpress/components';
import { compose } from '@wordpress/compose';
import { withDispatch, withSelect } from '@wordpress/data';
import { dateI18n } from '@wordpress/date';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useEffect, useState } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';

// Priority can be an integer between 0 and 9.
const validateResponse = ( response = 0 ) => {
	if ( isNaN( response ) || 0 > response || 9 < response ) {
		return false;
	}

	return parseInt( response );
};

const FeaturedListingsComponent = ( { createNotice, isSavingPost, meta, postId, setIsDirty, updateMetaValue } ) => {
	const [ error, setError ] = useState( null );
	const [ priority, setPriority ] = useState( null );
	const { newspack_listings_featured, newspack_listings_featured_expires } = meta;

	// Show error messages thrown by API requests.
	useEffect( () => {
		if ( error ) {
			createNotice( 'error', error, {
				id: 'newspack-listings__featured-error',
				isDismissible: true,
			} );
		}
	}, [ error ] );

	// On post save, also update the listing's priority level.
	useEffect( () => {
		if ( isSavingPost ) {
			const priorityToSet = newspack_listings_featured ? priority : 0;
			apiFetch( {
				path: addQueryArgs( '/newspack-listings/v1/priority', {
					post_id: postId,
					priority: priorityToSet,
				} ),
				method: 'POST',
			} )
				.then( response => {
					if ( false === response ) {
						throw new Error(
							__( 'There was an error updating the feature priority for this post. Please try saving again.', 'newspack-listings' )
						);
					}
				} )
				.catch( e => {
					setError(
						e?.message ||
							__( 'There was an error updating the feature priority for this post. Please try saving again.', 'newspack-listings' )
					);
				} );
		}
	}, [ isSavingPost ] );

	// If the item is featured, get priority level.
	useEffect( () => {
		setError( null );

		if ( newspack_listings_featured && ! priority ) {
			apiFetch( {
				path: addQueryArgs( '/newspack-listings/v1/priority', {
					post_id: postId,
				} ),
			} )
				.then( response => {
					const validatedResponse = validateResponse( response );
					if ( false !== validatedResponse ) {
						setPriority( response );
					}
				} )
				.catch( e => {
					setError(
						e?.message || __( 'There was an error fetching the priority for this post. Please refresh the editor.', 'newspack-listings' )
					);
				} );
		}
	}, [ newspack_listings_featured ] );

	return (
		<PluginDocumentSettingPanel
			className="newspack-listings__editor-sidebar-featured"
			name="newspack-listings-featured"
			title={ __( 'Featured Listing Settings', 'newspack-listings' ) }
		>
			<PanelRow>
				<ToggleControl
					className={ 'newspack-listings__toggle-control' }
					label={ __( 'Featured Listing', 'newspack-listings' ) }
					help={ sprintf(
						// translators: %s: feature status.
						__( 'This listing is %sfeatured.', 'newspack-listings' ),
						newspack_listings_featured ? __( '', 'newspack-listings' ) : __( 'not', 'newspack-listings' )
					) }
					checked={ newspack_listings_featured }
					onChange={ value => updateMetaValue( 'newspack_listings_featured', value ) }
				/>
			</PanelRow>
			{ newspack_listings_featured && (
				<>
					<PanelRow>
						<RangeControl
							disabled={ null === priority && null === error }
							label={ __( 'Priority Level', 'newspack-listings' ) }
							help={ __( 'Relative importance of the featured item. Higher numbers mean higher priority.', 'newspack-listings' ) }
							value={ priority || 5 }
							onChange={ value => {
								setIsDirty();
								setPriority( value );
							} }
							min={ 1 }
							max={ 9 }
							required
						/>
					</PanelRow>
					<PanelRow>
						<BaseControl id="newspack-listings__featured-listing-expiration" label={ __( 'Expiration Date', 'newspack-listings' ) }>
							<DatePicker
								currentDate={ newspack_listings_featured_expires ? new Date( newspack_listings_featured_expires ) : null }
								onMonthPreviewed={ () => {} }
								onChange={ value => {
									// Convert value to midnight in the local timezone.
									const date = new Date( value );
									const midnight = new Date( date.getFullYear(), date.getMonth(), date.getDate() );
									updateMetaValue( 'newspack_listings_featured_expires', dateI18n( 'Y-m-d\\TH:i:s', midnight ) );
								} }
							/>
							{ newspack_listings_featured_expires && (
								<Button isLink onClick={ () => updateMetaValue( 'newspack_listings_featured_expires', '' ) }>
									{ __( 'Reset', 'newspack-listings' ) }
								</Button>
							) }
						</BaseControl>
					</PanelRow>
				</>
			) }
		</PluginDocumentSettingPanel>
	);
};

const mapStateToProps = select => {
	const { getCurrentPostId, getEditedPostAttribute, isAutosavingPost, isSavingPost } = select( 'core/editor' );

	return {
		isSavingPost: isSavingPost() && ! isAutosavingPost(),
		meta: getEditedPostAttribute( 'meta' ),
		postId: getCurrentPostId(),
	};
};

const mapDispatchToProps = dispatch => {
	const { editPost } = dispatch( 'core/editor' );
	const { createNotice } = dispatch( 'core/notices' );

	return {
		updateMetaValue: ( key, value ) => editPost( { meta: { [ key ]: value } } ),
		setIsDirty: () => editPost( { editorShouldAllowSave: true } ),
		createNotice,
	};
};

export const FeaturedListings = compose( [ withSelect( mapStateToProps ), withDispatch( mapDispatchToProps ) ] )( FeaturedListingsComponent );
