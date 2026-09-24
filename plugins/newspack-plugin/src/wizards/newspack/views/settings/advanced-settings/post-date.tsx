/**
 * Newspack > Settings > Advanced Settings > Post Date
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { TextControl } from '../../../../../../packages/components/src';
import ChoiceToggle from './choice-toggle';

export default function PostDate( { data, isFetching, update }: ThemeModComponentProps< AdvancedSettings > ) {
	return (
		<>
			<ChoiceToggle
				label={ __( 'Date format', 'newspack-plugin' ) }
				help={ __( 'Display post dates in "time ago" format (e.g. "2 hours ago"), or as the full date.', 'newspack-plugin' ) }
				onLabel={ __( 'Relative', 'newspack-plugin' ) }
				offLabel={ __( 'Full date', 'newspack-plugin' ) }
				checked={ data.post_time_ago }
				disabled={ isFetching }
				onChange={ ( post_time_ago: boolean ) => update( { post_time_ago } ) }
			/>
			{ data.post_time_ago && (
				<TextControl
					withMargin={ false }
					label={ __( 'Maximum post age (days)', 'newspack-plugin' ) }
					help={ __( 'Posts older than this will show the full date instead.', 'newspack-plugin' ) }
					type="number"
					min={ 1 }
					disabled={ isFetching }
					value={ data.post_time_ago_cut_off }
					onChange={ ( post_time_ago_cut_off: number ) => update( { post_time_ago_cut_off } ) }
				/>
			) }
			<ChoiceToggle
				label={ __( 'Last updated date', 'newspack-plugin' ) }
				help={ __( 'Display when a post was last modified.', 'newspack-plugin' ) }
				checked={ data.post_updated_date }
				disabled={ isFetching }
				onChange={ ( post_updated_date: boolean ) => update( { post_updated_date } ) }
			/>
			{ data.post_updated_date && (
				<TextControl
					withMargin={ false }
					label={ __( 'Minimum hours after publish', 'newspack-plugin' ) }
					help={ __( 'Only show the updated date for posts modified at least this many hours after publication.', 'newspack-plugin' ) }
					type="number"
					min={ 0 }
					disabled={ isFetching }
					value={ data.post_updated_date_threshold }
					onChange={ ( post_updated_date_threshold: number ) => update( { post_updated_date_threshold } ) }
				/>
			) }
		</>
	);
}
