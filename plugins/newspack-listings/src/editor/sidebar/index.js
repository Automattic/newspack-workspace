/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { BaseControl, DateTimePicker, ExternalLink, PanelRow, ToggleControl } from '@wordpress/components';
import { compose } from '@wordpress/compose';
import { withDispatch, withSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { isListing } from '../utils';
import './style.scss';

const SidebarComponent = ( { createNotice, meta, publishDate, updateMetaValue } ) => {
	const {
		is_listing_customer: isListingCustomer = false,
		post_type_label: postTypeLabel,
		post_types: postTypes,
		self_serve_listing_expiration: expirationPeriod,
	} = window.newspack_listings_data;
	const {
		newspack_listings_hide_author: hideAuthor,
		newspack_listings_hide_publish_date: hidePublishDate,
		newspack_listings_expiration_date: expirationDate,
	} = meta;
	const [ initialExpirationDate ] = useState( expirationDate );

	if ( ! postTypes ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			className="newspack-listings__editor-sidebar"
			name="newspack-listings"
			title={ sprintf(
				// translators: %s: listing post type.
				__( '%s Settings', 'newspack-listings' ),
				isListing() ? postTypeLabel : __( 'Newspack Listings', 'newspack-listings' )
			) }
		>
			<p>
				<em>
					{ __( 'Overrides', 'newspack-listings' ) }
					<ExternalLink href="/wp-admin/admin.php?page=newspack-listings-settings-admin">
						{ __( 'global settings', 'newspack-listings' ) }
					</ExternalLink>
				</em>
			</p>
			<PanelRow>
				<ToggleControl
					className={ 'newspack-listings__toggle-control' }
					label={ __( 'Hide listing author', 'newspack-listings' ) }
					help={ sprintf(
						// translators: %s: show or hide author byline toggle label.
						__( '%s the author byline for this listing.', 'newspack-listings' ),
						hideAuthor ? __( 'Hide', 'newspack-listings' ) : __( 'Show', 'newspack-listings' )
					) }
					checked={ hideAuthor }
					onChange={ value => updateMetaValue( 'newspack_listings_hide_author', value ) }
				/>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					className={ 'newspack-listings__toggle-control' }
					label={ __( 'Hide publish date', 'newspack-listings' ) }
					help={ sprintf(
						// translators: %s: show or hide publish date toggle label.
						__( '%s the publish and updated dates for this listing.', 'newspack-listings' ),
						hidePublishDate ? __( 'Hide', 'newspack-listings' ) : __( 'Show', 'newspack-listings' )
					) }
					checked={ hidePublishDate }
					onChange={ value => updateMetaValue( 'newspack_listings_hide_publish_date', value ) }
				/>
			</PanelRow>
			<PanelRow>
				<div className="hide-time">
					<BaseControl
						id="newspack-listings-expiration-date"
						help={ __( 'If set, the listing will be automatically unpublished after this date.', 'newspack-listings' ) }
						label={ __( 'Expiration Date', 'newspack-listings' ) }
					>
						<DateTimePicker
							currentDate={ expirationDate ? new Date( expirationDate ) : null }
							onChange={ value => {
								/**
								 * If the current user is a listings customer, don't allow them to set the expiraiton date beyond the
								 * last saved expiration date or `expirationPeriod` days from the publish date, whichever is later.
								 */
								if ( isListingCustomer ) {
									const fromExpirationDate = initialExpirationDate ? new Date( initialExpirationDate ) : null;
									const publishDateDate = new Date( publishDate );
									const fromPublishDate = new Date(
										publishDateDate.setDate( publishDateDate.getDate() + parseInt( expirationPeriod ) )
									);
									const laterDate = fromExpirationDate
										? new Date( Math.max( fromPublishDate, fromExpirationDate ) )
										: fromPublishDate;

									if ( 0 < new Date( value ) - laterDate ) {
										return createNotice(
											'warning',
											sprintf(
												// translators: %s: warning when listings customer tries to extend expiration beyond allowed range.
												__( 'Cannot set expiration date beyond %s.', 'newspack-listings' ),
												laterDate.toLocaleDateString( undefined, {
													weekday: 'long',
													year: 'numeric',
													month: 'long',
													day: 'numeric',
												} )
											),
											{
												id: 'newspack-listings__date-error',
												isDismissible: true,
												type: 'default',
											}
										);
									}
								}

								if (
									value &&
									publishDate &&
									0 <= new Date( value ) - new Date( publishDate ) // Expiration date must come after publish date.
								) {
									return updateMetaValue( 'newspack_listings_expiration_date', value );
								}

								// If clearing the value.
								if ( ! value ) {
									return updateMetaValue( 'newspack_listings_expiration_date', '' );
								}

								createNotice( 'warning', __( 'Expiration date must be after publish date.', 'newspack-listings' ), {
									id: 'newspack-listings__date-error',
									isDismissible: true,
									type: 'default',
								} );
							} }
						/>
					</BaseControl>
				</div>
			</PanelRow>
		</PluginDocumentSettingPanel>
	);
};

const mapStateToProps = select => {
	const { getEditedPostAttribute } = select( 'core/editor' );

	return {
		meta: getEditedPostAttribute( 'meta' ),
		publishDate: getEditedPostAttribute( 'date' ),
	};
};

const mapDispatchToProps = dispatch => {
	const { editPost } = dispatch( 'core/editor' );
	const { createNotice } = dispatch( 'core/notices' );

	return {
		createNotice,
		updateMetaValue: ( key, value ) => editPost( { meta: { [ key ]: value } } ),
	};
};

export const Sidebar = compose( [ withSelect( mapStateToProps ), withDispatch( mapDispatchToProps ) ] )( SidebarComponent );
