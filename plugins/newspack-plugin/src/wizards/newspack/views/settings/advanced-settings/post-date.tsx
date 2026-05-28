/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { ToggleControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Grid, TextControl } from '../../../../../../packages/components/src';

export default function PostDate( { data, isFetching, update }: ThemeModComponentProps< AdvancedSettings > ) {
	return (
		<Grid gutter={ 32 }>
			<Grid columns={ 1 } gutter={ 16 }>
				<ToggleControl
					label={ __( 'Show relative dates', 'newspack-plugin' ) }
					help={ __( 'Display post dates in "time ago" format (e.g. "2 hours ago").', 'newspack-plugin' ) }
					disabled={ isFetching }
					checked={ data.post_time_ago }
					onChange={ ( post_time_ago: boolean ) => update( { post_time_ago } ) }
				/>
				{ data.post_time_ago && (
					<TextControl
						label={ __( 'Maximum post age (days)', 'newspack-plugin' ) }
						help={ __( 'Posts older than this will show the full date instead.', 'newspack-plugin' ) }
						type="number"
						min={ 1 }
						disabled={ isFetching }
						value={ data.post_time_ago_cut_off }
						onChange={ ( post_time_ago_cut_off: number ) => update( { post_time_ago_cut_off } ) }
					/>
				) }
			</Grid>
			<Grid columns={ 1 } gutter={ 16 }>
				<ToggleControl
					label={ __( 'Show last updated date', 'newspack-plugin' ) }
					help={ __( 'Display when a post was last modified.', 'newspack-plugin' ) }
					disabled={ isFetching }
					checked={ data.post_updated_date }
					onChange={ ( post_updated_date: boolean ) => update( { post_updated_date } ) }
				/>
				{ data.post_updated_date && (
					<TextControl
						label={ __( 'Minimum hours after publish', 'newspack-plugin' ) }
						help={ __( 'Only show the updated date for posts modified at least this many hours after publication.', 'newspack-plugin' ) }
						type="number"
						min={ 0 }
						disabled={ isFetching }
						value={ data.post_updated_date_threshold }
						onChange={ ( post_updated_date_threshold: number ) => update( { post_updated_date_threshold } ) }
					/>
				) }
			</Grid>
		</Grid>
	);
}
