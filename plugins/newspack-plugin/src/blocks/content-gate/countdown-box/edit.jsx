/* globals newspack_blocks */

/**
 * WordPress dependencies
 */
import { useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';
import { Placeholder } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { caution } from '@wordpress/icons';

/**
 * Edit function for the Content Gate Countdown Box block.
 *
 * @return {JSX.Element} The Content Gate Countdown Box block.
 */
export default function Edit() {
	const {
		loggedin_metered_views: loggedinViews,
		anonymous_metered_views: anonymousViews,
		metering_period: period,
	} = newspack_blocks.content_gate_data || {};
	const blockProps = useBlockProps( {
		className: 'newspack-content-gate-countdown-box__wrapper',
	} );
	const { children, ...innerBlockProps } = useInnerBlocksProps(
		{ className: 'newspack-content-gate-countdown-box__content' },
		{
			template: [
				[
					'core/group',
					{
						layout: {
							type: 'flex',
							orientation: 'horizontal',
							flexWrap: 'nowrap',
							justifyContent: 'space-between',
						},
					},
					[
						[
							'core/group',
							{
								layout: {
									type: 'flex',
									orientation: 'vertical',
									flexWrap: 'nowrap',
								},
								style: {
									spacing: {
										blockGap: 'var:preset|spacing|20',
									},
								},
							},
							[
								[
									'core/group',
									{
										layout: {
											type: 'flex',
											orientation: 'horizontal',
											flexWrap: 'nowrap',
										},
										style: {
											spacing: {
												blockGap: '0.25em',
											},
										},
									},
									[
										[
											'newspack/content-gate-countdown',
											{
												fontSize: 'small',
												lock: {
													move: false,
													remove: true,
												},
												style: {
													typography: {
														fontStyle: 'normal',
														fontWeight: '700',
													},
												},
											},
										],
										[
											'core/paragraph',
											{
												content: sprintf(
													/* translators: %s is the metered period, e.g. "month" or "week". */
													__( 'free articles this %s', 'newspack-plugin' ),
													period
												),
												fontSize: 'small',
												style: {
													typography: {
														fontStyle: 'normal',
														fontWeight: '700',
														textTransform: 'uppercase',
													},
												},
											},
										],
									],
								],
								[
									'core/paragraph',
									{
										content: sprintf(
											/* translators: %1$s is subscribe message, %2$s is sign in link */
											__( '%1$s %2$s', 'newspack-plugin' ),
											__( 'Subscribe now and get unlimited access.', 'newspack-plugin' ),
											'<a href="#signin_modal">' + __( 'Sign in to an existing account.', 'newspack-plugin' ) + '</a>'
										),
										fontSize: 'small',
										style: {
											elements: {
												link: {
													color: {
														text: 'var:preset|color|medium-gray',
													},
												},
											},
										},
										textColor: 'medium-gray',
									},
								],
							],
						],
						[
							'newspack-blocks/checkout-button',
							{
								text: __( 'Subscribe\u00A0now', 'newspack-plugin' ),
							},
						],
					],
				],
			],
		}
	);

	if ( ! loggedinViews && ! anonymousViews ) {
		return (
			<div { ...blockProps }>
				<Placeholder
					icon={ caution }
					label={ __( 'Content Gate Countdown', 'newspack-plugin' ) }
					className="newspack-content-gate-countdown-box__placeholder"
				>
					{ __( 'The content gate countdown block will only display in restricted content when metering is enabled.', 'newspack-plugin' ) }
				</Placeholder>
			</div>
		);
	}

	return (
		<div { ...blockProps }>
			<div { ...innerBlockProps }>{ children }</div>
		</div>
	);
}
