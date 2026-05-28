import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

import WizardsActionCard from '../../../../wizards-action-card';
import { Button, Card, Grid, TextControl, Waiting } from '../../../../../../packages/components/src';

export default function Recirculation( { data, update, isFetching }: ThemeModComponentProps< Recirculation > ) {
	return (
		<>
			<WizardsActionCard
				title={ __( 'Related Posts', 'newspack-plugin' ) }
				badge="Jetpack"
				description={ () => (
					<Fragment>
						{ isFetching
							? __( 'Loading…', 'newspack-plugin' )
							: __( 'Automatically add related content at the bottom of each post.', 'newspack-plugin' ) }
					</Fragment>
				) }
				editLink="admin.php?page=jetpack#/traffic"
				actionText={
					<Fragment>
						{ isFetching ? (
							<Waiting />
						) : (
							<Button variant="link" href="admin.php?page=jetpack#/traffic">
								{ __( 'Configure', 'newspack-plugin' ) }
							</Button>
						) }
					</Fragment>
				}
			/>

			{ data.relatedPostsEnabled && (
				<Grid>
					<Card noBorder>
						<TextControl
							help={ __(
								'If set, posts will be shown as related content only if published within the past number of months. If 0, any published post can be shown, regardless of publish date.',
								'newspack-plugin'
							) }
							label={ __( 'Maximum age of related content, in months', 'newspack-plugin' ) }
							onChange={ ( relatedPostsMaxAge: number ) => update( { relatedPostsMaxAge } ) }
							placeholder={ __( 'Maximum age of related content, in months', 'newspack-plugin' ) }
							type="number"
							value={ data.relatedPostsMaxAge || 0 }
						/>
					</Card>
				</Grid>
			) }
		</>
	);
}
