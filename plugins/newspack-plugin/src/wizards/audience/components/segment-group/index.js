/**
 * Segment group component.
 */

/**
 * WordPress dependencies.
 */
import { sprintf, __ } from '@wordpress/i18n';
import { CardBody, CardDivider } from '@wordpress/components';
import { useState, Fragment } from '@wordpress/element';
import { blockTable, header, layout, postList } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Button, Card, Grid, Modal } from '../../../../../packages/components/src';
import SegmentationPreview from '../segmentation-preview';
import PromptActionCard from '../prompt-action-card';
import { promptDescription, segmentDescription, getCardClassName, warningForPopup } from '../../views/campaigns/utils';
import { overlayBottom, overlayInline, overlayCenter, overlayTop } from '../../../../../packages/icons';
import './style.scss';

const addNewURL = ( placement, campaignId, segmentId ) => {
	const base = '/wp-admin/post-new.php?post_type=newspack_popups_cpt&';
	const params = [];
	if ( placement ) {
		params.push( `placement=${ placement }` );
	}
	if ( +campaignId > 0 ) {
		params.push( `group=${ campaignId }` );
	}
	if ( segmentId ) {
		params.push( `segment=${ segmentId }` );
	}
	return base + params.join( '&' );
};

const SegmentGroup = props => {
	const { campaignData, campaignId, segment } = props;
	const [ modalVisible, setModalVisible ] = useState( false );
	const { label, id, prompts } = segment;
	const campaignToPreview = 'unassigned' !== campaignId ? parseInt( campaignId ) : -1;

	let emptySegmentText;
	if ( 'unassigned' === campaignId ) {
		emptySegmentText = __( 'No unassigned prompts in this segment.', 'newspack-plugin' );
	} else if ( campaignData ) {
		emptySegmentText = __( 'No prompts in this segment for', 'newspack-plugin' ) + ' ' + campaignData.name + '.';
	} else {
		emptySegmentText = __( 'No active prompts in this segment.', 'newspack-plugin' );
	}

	return (
		<Card
			__experimentalCoreCard
			__experimentalCoreProps={ {
				hasGreyHeader: true,
				header: (
					<div className="newspack-campaigns__segment-group__card__segment">
						<div className="newspack-campaigns__segment-group__card__segment-title">
							<h3>
								{ id ? (
									<Button
										href={ `#/segments/${ id }` }
										label={ __( 'Edit Segment', 'newspack-plugin' ) }
										isLink
										showTooltip
										tooltipPosition="bottom center"
									>
										{
											/* translators: %s: segment label */
											sprintf( __( 'Segment: %s', 'newspack-plugin' ), label )
										}
									</Button>
								) : (
									label
								) }
							</h3>
							<span className="newspack-campaigns__segment-group__description">
								{ id ? segmentDescription( segment ) : __( 'All readers, regardless of segment', 'newspack-plugin' ) }
							</span>
						</div>
						<div className="newspack-campaigns__segment-group__card__segment-actions">
							<SegmentationPreview
								campaign={ campaignId ? campaignToPreview : false }
								segment={ id }
								showUnpublished={ !! campaignId } // Only if previewing a specific campaign/group.
								renderButton={ ( { showPreview } ) => (
									<Button isSmall variant="tertiary" onClick={ () => showPreview() }>
										{ __( 'Preview Segment', 'newspack-plugin' ) }
									</Button>
								) }
								title={
									/* translators: %s: segment label */
									sprintf( __( 'Segment: %s', 'newspack-plugin' ), label )
								}
							/>
							{ 'unassigned' !== campaignId && (
								<Fragment>
									<Button isSmall variant="secondary" onClick={ () => setModalVisible( ! modalVisible ) }>
										{ __( 'Add New Prompt', 'newspack-plugin' ) }
									</Button>
									{ modalVisible && (
										<Modal
											title={ __( 'Add New Prompt', 'newspack-plugin' ) }
											onRequestClose={ () => setModalVisible( false ) }
											shouldCloseOnEsc={ false }
											shouldCloseOnClickOutside={ false }
											size="large"
										>
											<Grid columns={ 2 } gutter={ 16 } noMargin>
												<Card
													__experimentalCoreCard
													isSmall
													buttonsCard
													href={ addNewURL( 'overlay-center', campaignId, id ) }
													__experimentalCoreProps={ {
														header: (
															<>
																<h3>{ __( 'Center Overlay', 'newspack-plugin' ) }</h3>
																<p>{ __( 'Fixed at the center of the screen', 'newspack-plugin' ) }</p>
															</>
														),
														icon: overlayCenter,
														actionType: 'chevron',
														iconBackgroundColor: true,
													} }
												/>
												<Card
													__experimentalCoreCard
													isSmall
													buttonsCard
													href={ addNewURL( 'overlay-top', campaignId, id ) }
													__experimentalCoreProps={ {
														header: (
															<>
																<h3>{ __( 'Top Overlay', 'newspack-plugin' ) }</h3>
																<p>{ __( 'Fixed at the top of the screen', 'newspack-plugin' ) }</p>
															</>
														),
														icon: overlayTop,
														actionType: 'chevron',
														iconBackgroundColor: true,
													} }
												/>
												<Card
													__experimentalCoreCard
													isSmall
													buttonsCard
													href={ addNewURL( 'overlay-bottom', campaignId, id ) }
													__experimentalCoreProps={ {
														header: (
															<>
																<h3>{ __( 'Bottom Overlay', 'newspack-plugin' ) }</h3>
																<p>{ __( 'Fixed at the bottom of the screen', 'newspack-plugin' ) }</p>
															</>
														),
														icon: overlayBottom,
														actionType: 'chevron',
														iconBackgroundColor: true,
													} }
												/>
												<Card
													__experimentalCoreCard
													isSmall
													buttonsCard
													href={ addNewURL( null, campaignId, id ) }
													__experimentalCoreProps={ {
														header: (
															<>
																<h3>{ __( 'Inline', 'newspack-plugin' ) }</h3>
																<p>{ __( 'Embedded in content', 'newspack-plugin' ) }</p>
															</>
														),
														icon: overlayInline,
														actionType: 'chevron',
														iconBackgroundColor: true,
													} }
												/>
												<Card
													__experimentalCoreCard
													isSmall
													buttonsCard
													href={ addNewURL( 'above-header', campaignId, id ) }
													__experimentalCoreProps={ {
														header: (
															<>
																<h3>{ __( 'Above Header', 'newspack-plugin' ) }</h3>
																<p>{ __( 'Embedded at the very top of the page', 'newspack-plugin' ) }</p>
															</>
														),
														icon: header,
														actionType: 'chevron',
														iconBackgroundColor: true,
													} }
												/>
												<Card
													__experimentalCoreCard
													isSmall
													buttonsCard
													href={ addNewURL( 'custom', campaignId, id ) }
													__experimentalCoreProps={ {
														header: (
															<>
																<h3>{ __( 'Custom Placement', 'newspack-plugin' ) }</h3>
																<p>{ __( 'Only appears when placed in content', 'newspack-plugin' ) }</p>
															</>
														),
														icon: layout,
														actionType: 'chevron',
														iconBackgroundColor: true,
													} }
												/>
												<Card
													__experimentalCoreCard
													isSmall
													buttonsCard
													href={ addNewURL( 'archives', campaignId, id ) }
													__experimentalCoreProps={ {
														header: (
															<>
																<h3>{ __( 'In Archive Pages', 'newspack-plugin' ) }</h3>
																<p>{ __( 'Embedded once or many times in archive pages', 'newspack-plugin' ) }</p>
															</>
														),
														icon: postList,
														actionType: 'chevron',
														iconBackgroundColor: true,
													} }
												/>
												<Card
													__experimentalCoreCard
													isSmall
													buttonsCard
													href={ addNewURL( 'manual', campaignId, id ) }
													__experimentalCoreProps={ {
														header: (
															<>
																<h3>{ __( 'Manual Only', 'newspack-plugin' ) }</h3>
																<p>
																	{ __( 'Only appears where Single Prompt block is inserted', 'newspack-plugin' ) }
																</p>
															</>
														),
														icon: blockTable,
														actionType: 'chevron',
														iconBackgroundColor: true,
													} }
												/>
											</Grid>
										</Modal>
									) }
								</Fragment>
							) }
						</div>
					</div>
				),
			} }
			isSmall
			className="newspack-campaigns__segment-group__card"
		>
			{ prompts.map( ( item, index ) => (
				<Fragment key={ item.id }>
					<PromptActionCard
						className={ getCardClassName( item.status, segment.configuration.is_disabled ) }
						description={ promptDescription( item ) }
						warning={ warningForPopup( prompts, item ) }
						prompt={ item }
						{ ...props }
					/>
					{ index < prompts.length - 1 && <CardDivider /> }
				</Fragment>
			) ) }
			{ prompts.length < 1 ? (
				<CardBody>
					<p className="newspack-campaigns__segment-group__empty-segment-text">{ emptySegmentText }</p>
				</CardBody>
			) : (
				''
			) }
		</Card>
	);
};
export default SegmentGroup;
