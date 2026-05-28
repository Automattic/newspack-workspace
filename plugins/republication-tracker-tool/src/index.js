import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { ToggleControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';

const META_KEY = 'republication-tracker-tool-hide-widget';

const RepublicationTrackerPanel = () => {
	const { postType, meta, filterHides, shareData } = useSelect(
		( select ) => {
			const editor = select( 'core/editor' );
			return {
				postType: editor.getCurrentPostType(),
				meta: editor.getEditedPostAttribute( 'meta' ),
				filterHides: editor.getEditedPostAttribute(
					'republication_tracker_tool_filter_hides'
				),
				shareData: editor.getEditedPostAttribute(
					'republication_tracker_tool_share_data'
				),
			};
		},
		[]
	);

	const { editPost } = useDispatch( 'core/editor' );

	if ( 'post' !== postType ) {
		return null;
	}

	const hideWidget = !! meta?.[ META_KEY ];
	const total = shareData?.total ?? 0;
	const entries = shareData?.entries ?? [];

	return (
		<PluginDocumentSettingPanel
			name="republication-tracker-tool"
			title={ __(
				'Republication Widget Settings',
				'republication-tracker-tool'
			) }
		>
			<ToggleControl
				label={ __(
					'Hide Republication widget',
					'republication-tracker-tool'
				) }
				checked={ filterHides ? true : hideWidget }
				disabled={ filterHides }
				onChange={ ( value ) =>
					editPost( {
						meta: { ...( meta ?? {} ), [ META_KEY ]: value },
					} )
				}
				help={
					filterHides
						? createInterpolateElement(
								// translators: <a> and </a> tags represent a link to the Republication Tracker README file on GitHub.
								__(
									'The Republication sharing widget on this post is programmatically disabled through the <code>hide_republication_widget</code> filter. <a>Read more about this filter</a>.',
									'republication-tracker-tool'
								),
								{
									code: <code />,
									a: (
										// eslint-disable-next-line jsx-a11y/anchor-has-content
										<a
											href="https://github.com/Automattic/republication-tracker-tool/blob/trunk/docs/removing-republish-button-from-categories.md"
											target="_blank"
											rel="noopener noreferrer"
										/>
									),
								}
						  )
						: undefined
				}
			/>
			<p style={ { marginTop: '16px' } }>
				{ __( 'Total number of views:', 'republication-tracker-tool' ) }{ ' ' }
				{ total }
			</p>
			{ entries.length > 0 ? (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>
								{ __(
									'Republished URL',
									'republication-tracker-tool'
								) }
							</th>
							<th>
								{ __( 'Views', 'republication-tracker-tool' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ entries.map( ( entry ) => (
							<tr key={ entry.url }>
								<td>
									<a
										href={ entry.url }
										target="_blank"
										rel="noopener noreferrer"
									>
										{ entry.url }
									</a>
								</td>
								<td>{ entry.count }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) : (
				<p>
					{ __(
						'There are no shares to display.',
						'republication-tracker-tool'
					) }
				</p>
			) }
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'republication-tracker-tool', {
	render: RepublicationTrackerPanel,
} );
