/**
 * WordPress dependencies
 */
import { useState, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Card, Grid, Notice, SectionHeader } from '../../../../../packages/components/src';
import { handleJSONFile } from '../../components/utils';

const ServiceAccountConnection = ( { updateWithAPI, isConnected } ) => {
	const credentialsInputFile = useRef( null );
	const [ fileError, setFileError ] = useState( '' );

	const updateGAMCredentials = credentials =>
		updateWithAPI( {
			path: '/newspack/v1/wizard/billboard/credentials',
			method: 'post',
			data: { credentials },
			quiet: true,
		} );
	const removeGAMCredentials = () =>
		updateWithAPI( {
			path: '/newspack/v1/wizard/billboard/credentials',
			method: 'delete',
			quiet: true,
		} );

	const handleCredentialsFile = event => {
		if ( event.target.files.length && event.target.files[ 0 ] ) {
			handleJSONFile( event.target.files[ 0 ] )
				.then( credentials => updateGAMCredentials( credentials ) )
				.catch( err => setFileError( err ) );
		}
	};

	return (
		<>
			<SectionHeader title={ __( 'Service Account connection', 'newspack' ) } />
			{ isConnected ? (
				<Grid>
					<Card
						__experimentalCoreCard
						isSmall
						__experimentalCoreProps={ {
							header: <h3>{ __( 'Update Service Account credentials', 'newspack' ) }</h3>,
							actionType: 'chevron',
							onHeaderClick: () => credentialsInputFile.current.click(),
						} }
					/>
					<Card
						__experimentalCoreCard
						isSmall
						className="is-destructive"
						__experimentalCoreProps={ {
							header: <h3>{ __( 'Remove Service Account credentials', 'newspack' ) }</h3>,
							actionType: 'chevron',
							onHeaderClick: removeGAMCredentials,
						} }
					/>
				</Grid>
			) : (
				<Card
					__experimentalCoreCard
					__experimentalCoreProps={ {
						header: (
							<>
								<h3>{ __( 'Connect your Google Ad Manager account', 'newspack' ) }</h3>
								<p>
									{ __(
										'Upload your Service Account credentials file to connect your GAM account with Newspack Ads.',
										'newspack'
									) }
								</p>
								{ fileError && <Notice noticeText={ fileError } isError /> }
							</>
						),
						actionType: 'chevron',
						onHeaderClick: () => credentialsInputFile.current.click(),
					} }
				/>
			) }
			<input type="file" accept=".json" ref={ credentialsInputFile } style={ { display: 'none' } } onChange={ handleCredentialsFile } />
		</>
	);
};

export default ServiceAccountConnection;
