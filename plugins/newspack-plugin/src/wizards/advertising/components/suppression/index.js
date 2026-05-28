/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ToggleControl, CheckboxControl } from '@wordpress/components';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { ActionCard, Grid, Notice, Button, CategoryAutocomplete, SectionHeader, Waiting } from '../../../../../packages/components/src';

const Suppression = () => {
	const [ error, setError ] = useState( false );
	const [ inFlight, setInFlight ] = useState( false );
	const [ initialConfig, setInitialConfig ] = useState( false );
	const [ isDirty, setIsDirty ] = useState( false );
	const [ config, setConfig ] = useState( false );
	const [ postTypes, setPostTypes ] = useState( [] );
	const fetchConfig = () => {
		apiFetch( { path: '/newspack-ads/v1/suppression' } ).then( response => {
			setConfig( response );
			setInitialConfig( response );
		} );
	};
	const fetchPostTypes = () => {
		apiFetch( {
			path: addQueryArgs( '/wp/v2/types', { context: 'edit' } ),
		} )
			.then( result => {
				setPostTypes(
					Object.values( result )
						.filter( postType => postType.viewable === true && postType.visibility?.show_ui === true )
						.map( postType => ( {
							value: postType.slug,
							label: postType.name,
						} ) )
				);
			} )
			.catch( setError );
	};
	const updateConfig = () => {
		setInFlight( true );
		apiFetch( {
			path: '/newspack-ads/v1/suppression',
			method: 'POST',
			data: { config },
		} )
			.then( setConfig )
			.catch( setError )
			.finally( () => {
				setInFlight( false );
			} );
	};
	useEffect( fetchConfig, [] );
	useEffect( fetchPostTypes, [] );
	useEffect( () => {
		setIsDirty( config && initialConfig && JSON.stringify( config ) !== JSON.stringify( initialConfig ) );
	}, [ config, initialConfig ] );
	if ( config === false ) {
		return <Waiting />;
	}
	return (
		<ActionCard
			actionContent={
				<Button isPrimary disabled={ inFlight || ! isDirty } onClick={ updateConfig }>
					{ __( 'Save Settings', 'newspack-plugin' ) }
				</Button>
			}
			hasGreyHeader
			title={ __( 'Suppression settings', 'newspack-plugin' ) }
			description={ __( 'Configure where ads are suppressed.', 'newspack-plugin' ) }
		>
			{ error && <Notice isError noticeText={ error.message } /> }
			<SectionHeader
				title={ __( 'Post types', 'newspack-plugin' ) }
				heading={ 3 }
				description={ __( 'Suppress ads on specific post types.', 'newspack-plugin' ) }
			/>
			<Grid columns={ 3 } gutter={ 16 }>
				{ postTypes.map( postType => (
					<CheckboxControl
						key={ postType.value }
						label={ postType.label }
						checked={ config?.post_types?.includes( postType.value ) }
						onChange={ selected => {
							let newPostTypes = [ ...( config?.post_types || [] ) ];
							if ( selected && ! newPostTypes.includes( postType.value ) ) {
								newPostTypes.push( postType.value );
							} else {
								newPostTypes = newPostTypes.filter( type => type !== postType.value );
							}
							setConfig( { ...config, post_types: newPostTypes } );
						} }
					/>
				) ) }
			</Grid>
			<SectionHeader
				title={ __( 'Tags', 'newspack-plugin' ) }
				heading={ 3 }
				description={ __( 'Suppress ads on specific tags and their archive pages.', 'newspack-plugin' ) }
			/>
			<CategoryAutocomplete
				disabled={ config?.tag_archive_pages }
				value={ config.tags?.map( v => parseInt( v ) ) || [] }
				onChange={ selected => {
					setConfig( {
						...config,
						tags: selected.map( item => item.id ),
					} );
				} }
				label={ __( 'Tags to suppress ads on (archives and posts)', 'newspack-plugin' ) }
				taxonomy="tags"
			/>
			<ToggleControl
				disabled={ config === false }
				checked={ config?.tag_archive_pages }
				onChange={ tag_archive_pages => {
					setConfig( { ...config, tag_archive_pages } );
				} }
				label={ __( 'All tag archive pages', 'newspack-plugin' ) }
			/>
			<SectionHeader
				title={ __( 'Categories', 'newspack-plugin' ) }
				heading={ 3 }
				description={ __( 'Suppress ads on specific categories and their archive pages.', 'newspack-plugin' ) }
			/>
			<CategoryAutocomplete
				disabled={ config?.category_archive_pages }
				value={ config.categories?.map( v => parseInt( v ) ) || [] }
				onChange={ selected => {
					setConfig( {
						...config,
						categories: selected.map( item => item.id ),
					} );
				} }
				label={ __( 'Categories to suppress ads on (archives and posts)', 'newspack-plugin' ) }
			/>
			<ToggleControl
				disabled={ config === false }
				checked={ config?.category_archive_pages }
				onChange={ category_archive_pages => {
					setConfig( { ...config, category_archive_pages } );
				} }
				label={ __( 'All category archive pages', 'newspack-plugin' ) }
			/>
			<SectionHeader
				title={ __( 'Author Archive Pages', 'newspack-plugin' ) }
				heading={ 3 }
				description={ __( 'Suppress ads on automatically generated pages displaying a list of posts by an author.', 'newspack-plugin' ) }
			/>
			<ToggleControl
				disabled={ config === false }
				checked={ config?.author_archive_pages }
				onChange={ author_archive_pages => {
					setConfig( { ...config, author_archive_pages } );
				} }
				label={ __( 'Suppress ads on author archive pages', 'newspack-plugin' ) }
			/>{ ' ' }
		</ActionCard>
	);
};

export default Suppression;
