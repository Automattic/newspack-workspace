/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { ActionCard, Button, Grid, TextControl } from '../../../../packages/components/src';
import { HANDOFF_KEY } from '../../../../packages/components/src/consts';

/**
 * Expandable ActionCard for RAS prerequisites checklist.
 */
export default function Prerequisite( { config, getSharedProps, inFlight, prerequisite, saveConfig }: PrequisiteProps ) {
	const { href } = prerequisite;
	const isValid = Boolean( prerequisite.active );

	// If the prerequisite is active but has empty fields, show a warning.
	const hasEmptyFields = () => {
		if ( isValid && prerequisite.fields && prerequisite.warning ) {
			const emptyValues = Object.keys( prerequisite.fields ).filter( fieldName => '' === config[ fieldName as keyof Config ] );
			if ( emptyValues.length ) {
				return prerequisite.warning;
			}
		}
		return null;
	};

	const fieldKeys = Object.keys( prerequisite.fields || {} ) as ConfigKey[];

	const renderInnerContent = () => (
		// Inner card content.
		<>
			{ prerequisite.description && (
				<p>
					{ prerequisite.description }
					{ prerequisite.help_url && (
						<>
							{ ' ' }
							<ExternalLink href={ prerequisite.help_url }>{ __( 'Learn more', 'newspack-plugin' ) }</ExternalLink>
						</>
					) }
				</p>
			) }
			{ ( prerequisite.fields || prerequisite.action_text ) && (
				<Grid columns={ 2 } gutter={ 16 }>
					<div className="button-group">
						{
							// Form fields.
							prerequisite.fields && (
								<>
									{ fieldKeys.map( fieldName => {
										if ( ! prerequisite.fields || ! prerequisite.fields[ fieldName ] ) {
											return undefined;
										}
										return (
											<TextControl
												key={ fieldName }
												label={ prerequisite.fields[ fieldName ].label }
												help={ prerequisite.fields[ fieldName ].description }
												{ ...getSharedProps( fieldName, 'text' ) }
											/>
										);
									} ) }

									<Button
										variant={ 'primary' }
										onClick={ () => {
											const dataToSave: Partial< Config > = {};
											fieldKeys.forEach( fieldName => {
												if ( config[ fieldName ] ) {
													// @ts-expect-error - not sure what's the issue here.
													dataToSave[ fieldName ] = config[ fieldName ];
												}
											} );
											saveConfig( dataToSave );
										} }
										disabled={ inFlight }
									>
										{ inFlight
											? __( 'Saving…', 'newspack-plugin' )
											: sprintf(
													// translators: %s: save or update settings.
													__( '%s settings', 'newspack-plugin' ),
													isValid ? __( 'Update', 'newspack-plugin' ) : __( 'Save', 'newspack-plugin' )
											  ) }
									</Button>
								</>
							)
						}
						{
							// Link to another settings page.
							href && prerequisite.action_text && (
								<Button
									variant={ 'primary' }
									onClick={ () => {
										// Set up a handoff to indicate that the user is coming from the RAS wizard page.
										if ( prerequisite.instructions ) {
											window.localStorage.setItem(
												HANDOFF_KEY,
												JSON.stringify( {
													message: sprintf(
														// translators: %1$s: specific instructions for satisfying the prerequisite. %2$s: opening <a> tag for the link to the Audience Configuration page. %3$s: closing </a> tag.
														__(
															'%1$s%2$sReturn to the Audience Configuration page to complete the settings%3$s.',
															'newspack-plugin'
														),
														prerequisite.instructions + ' ',
														window.newspackAudience?.reader_activation_url
															? `<a href="${ window.newspackAudience.reader_activation_url }">`
															: '',
														window.newspackAudience?.reader_activation_url ? '</a>' : ''
													),
													url: href,
												} )
											);
										}

										window.location.href = href;
									} }
								>
									{ /* eslint-disable no-nested-ternary */ }
									{ ( isValid
										? __( 'Update ', 'newspack-plugin' )
										: prerequisite.fields
										? __( 'Save ', 'newspack-plugin' )
										: __( 'Configure ', 'newspack-plugin' ) ) + prerequisite.action_text }
								</Button>
							)
						}
					</div>
				</Grid>
			) }
		</>
	);

	let status = __( 'Pending', 'newspack-plugin' );
	if ( isValid ) {
		status = __( 'Ready', 'newspack-plugin' );
	}

	return (
		<ActionCard
			className="newspack-ras-wizard__prerequisite"
			isMedium
			expandable
			collapse={ isValid }
			title={ prerequisite.label }
			description={ sprintf(
				/* translators: %s: Prerequisite status */
				__( 'Status: %s', 'newspack-plugin' ),
				status
			) }
			checkbox={ isValid ? 'checked' : 'unchecked' }
			notificationLevel="info"
			notification={ hasEmptyFields() }
		>
			{ renderInnerContent() }
		</ActionCard>
	);
}
