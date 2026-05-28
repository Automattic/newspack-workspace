/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Notice, SectionHeader, SelectControl } from '../../../../packages/components/src';

export default function Settings( { title, value, onChange } ) {
	const [ inFlight, setInFlight ] = useState( false );
	const [ lists, setLists ] = useState( [] );
	const [ error, setError ] = useState( false );
	const fetchLists = () => {
		setError( false );
		setInFlight( true );
		apiFetch( {
			path: '/newspack-newsletters/v1/lists',
		} )
			.then( res => {
				// For Mailchimp, filter out groups and tags, and only include remote lists.
				const filteredLists = isMailchimp
					? res.filter( list => 'remote' === list.type && ! [ 'group-', 'tag-' ].some( subtype => list.id.includes( subtype ) ) )
					: res;
				setLists( filteredLists );
			} )
			.catch( setError )
			.finally( () => setInFlight( false ) );
	};
	useEffect( fetchLists, [] );
	const isMailchimp = title === 'Mailchimp';
	const listsLabel = isMailchimp ? __( 'Audience ID', 'newspack-plugin' ) : __( 'Master List', 'newspack-plugin' );
	const helpText = isMailchimp
		? __( 'Choose an audience to receive reader activity data.', 'newspack-plugin' )
		: __( 'Choose a master list to which all registered readers will be added.', 'newspack-plugin' );
	const handleChange = key => val => onChange && onChange( key, val );
	return (
		<>
			{ error && <Notice noticeText={ error?.message || __( 'Something went wrong.', 'newspack-plugin' ) } isError /> }
			<SectionHeader
				title={ sprintf( /** Translators: %s is the email service provider title */ __( '%s settings', 'newspack-plugin' ), title ) }
				description={ sprintf(
					/** Translators: %s is the email service provider title */ __( 'Settings for the %s integration.', 'newspack-plugin' ),
					title
				) }
			/>
			{ value.masterList === '' && (
				<Notice
					noticeText={ sprintf(
						// Translators: 1 is the term used to refer to lists for a given email service provider and 2 is the email service provider title
						__( 'No %1$s selected. You will not be able to send reader activity data to %2$s.', 'newspack-plugin' ),
						listsLabel,
						title
					) }
					isError
				/>
			) }
			<SelectControl
				label={ listsLabel }
				help={ helpText }
				disabled={ inFlight }
				value={ isMailchimp ? value.audienceId : value.masterList }
				onChange={ handleChange( isMailchimp ? 'audienceId' : 'masterList' ) }
				options={ [
					{ value: '', label: __( 'None', 'newspack-plugin' ) },
					...lists.map( list => ( { label: list.name, value: list.id } ) ),
				] }
			/>
			{ 'readerDefaultStatus' in value && value.audienceId && (
				<SelectControl
					label={ __( 'Default reader status', 'newspack-plugin' ) }
					help={ sprintf(
						// Translators: %s is the email service provider title.
						__(
							'Choose which %s status readers should have by default if they are not subscribed to any newsletters',
							'newspack-plugin'
						),
						title
					) }
					disabled={ inFlight }
					value={ value.readerDefaultStatus }
					onChange={ handleChange( 'readerDefaultStatus' ) }
					options={ [
						{
							value: 'transactional',
							label: __( 'Transactional/Non-Subscribed', 'newspack-plugin' ),
						},
						{ value: 'subscribed', label: __( 'Subscribed', 'newspack-plugin' ) },
					] }
				/>
			) }
		</>
	);
}
