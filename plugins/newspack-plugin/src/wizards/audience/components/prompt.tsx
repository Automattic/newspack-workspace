/* eslint-disable no-nested-ternary */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { BaseControl, CheckboxControl, ExternalLink, TextareaControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { seen } from '@wordpress/icons';

/**
 * External dependencies
 */
import { stringify } from 'qs';

/**
 * Internal dependencies
 */
import { ActionCard, Button, Grid, ImageUpload, Notice, TextControl, WebPreview, hooks } from '../../../../packages/components/src';

type Attachment = {
	id?: number;
	source_url?: string;
	url: string;
};

// Note: Schema and types for the `prompt` prop is defined in Newspack Campaigns: https://github.com/Automattic/newspack-popups/blob/trunk/includes/schemas/class-prompts.php
export default function Prompt( { inFlight, prompt, setInFlight, setPrompts }: PromptProps ) {
	const [ values, setValues ] = useState< InputValues | Record< string, never > >( {} );
	const [ error, setError ] = useState< false | { message: string } >( false );
	const [ isDirty, setIsDirty ] = useState< boolean >( false );
	const [ success, setSuccess ] = useState< false | string >( false );
	const [ image, setImage ] = useState< null | Attachment >( null );
	const [ isSavingFromPreview, setIsSavingFromPreview ] = useState( false );

	useEffect( () => {
		if ( Array.isArray( prompt?.user_input_fields ) ) {
			const fields = { ...values };
			prompt.user_input_fields.forEach( ( field: InputField ) => {
				fields[ field.name ] = field.value || field.default;
			} );
			setValues( fields );
		}

		if ( prompt.featured_image_id ) {
			setInFlight( true );
			apiFetch< Attachment >( {
				path: `/wp/v2/media/${ prompt.featured_image_id }`,
			} )
				.then( ( attachment: Attachment ) => {
					if ( attachment?.source_url || attachment?.url ) {
						setImage( {
							url: attachment.source_url || attachment.url,
						} );
					}
				} )
				.catch( setError )
				.finally( () => {
					setInFlight( false );
				} );
		}
	}, [ prompt ] );

	// Clear success message after a few seconds.
	useEffect( () => {
		setTimeout( () => setSuccess( false ), 5000 );
	}, [ success ] );

	const getPreviewUrl = ( { options, slug }: { options: PromptOptions; slug: string } ) => {
		const { placement, trigger_type: triggerType } = options;
		const previewQueryKeys = window.newspackAudience.preview_query_keys;
		const abbreviatedKeys = { preset: slug, values };
		const optionsKeys = Object.keys( options ) as Array< PromptOptionsBaseKey >;
		optionsKeys.forEach( key => {
			if ( previewQueryKeys.hasOwnProperty( key ) ) {
				// @ts-expect-error To be fixed in the future perhaps.
				abbreviatedKeys[ previewQueryKeys[ key ] ] = options[ key ];
			}
		} );

		let previewURL = '/';
		if ( 'archives' === placement && window.newspackAudience?.preview_archive ) {
			previewURL = window.newspackAudience.preview_archive;
		} else if ( ( 'inline' === placement || 'scroll' === triggerType ) && window && window.newspackAudience?.preview_post ) {
			previewURL = window.newspackAudience?.preview_post;
		}

		return `${ previewURL }?${ stringify( { ...abbreviatedKeys } ) }`;
	};

	const unblock = hooks.usePrompt( isDirty, __( 'You have unsaved changes that will be lost. Discard changes?', 'newspack-plugin' ) );

	const savePrompt = ( slug: string, data: InputValues ) => {
		return new Promise< void >( ( res, rej ) => {
			if ( unblock ) {
				unblock();
			}
			setError( false );
			setSuccess( false );
			setInFlight( true );
			apiFetch< [ PromptType ] >( {
				path: '/newspack-popups/v1/audience-management/campaign',
				method: 'post',
				data: {
					slug,
					data,
				},
			} )
				.then( ( fetchedPrompts: Array< PromptType > ) => {
					setPrompts( fetchedPrompts );
					setSuccess( __( 'Prompt saved.', 'newspack-plugin' ) );
					setIsDirty( false );
					res();
				} )
				.catch( err => {
					setError( err );
					rej( err );
				} )
				.finally( () => {
					setInFlight( false );
				} );
		} );
	};

	const helpInfo = prompt.help_info || null;

	return (
		<ActionCard
			isMedium
			expandable
			collapse={ prompt.ready && ! isSavingFromPreview }
			title={ prompt.title }
			description={ sprintf(
				// translators: %s: status of the prompt.
				__( 'Status: %s', 'newspack-plugin' ),
				isDirty
					? __( 'Unsaved changes', 'newspack-plugin' )
					: prompt.ready
					? __( 'Ready', 'newspack-plugin' )
					: __( 'Pending', 'newspack-plugin' )
			) }
			checkbox={ prompt.ready && ! isDirty ? 'checked' : 'unchecked' }
		>
			{
				<Grid columns={ 2 } gutter={ 64 } className="newspack-ras-campaign__grid">
					<div className="newspack-ras-campaign__fields">
						{ prompt.user_input_fields.map( ( field: InputField ) => (
							<Fragment key={ field.name }>
								{ 'array' === field.type && Array.isArray( field.options ) && (
									<BaseControl id={ `newspack-engagement-wizard__${ field.name }` } label={ field.label }>
										{ field.options.map( option => (
											<BaseControl
												key={ option.id }
												id={ `newspack-engagement-wizard__${ option.id }` }
												className="newspack-checkbox-control"
												help={ option.description }
											>
												<CheckboxControl
													disabled={ inFlight }
													label={ option.label }
													value={ option.id }
													checked={
														values[
															field.name
															// @ts-expect-error To be fixed in the future perhaps.
														]?.indexOf( option.id ) > -1
													}
													onChange={ ( value: boolean ) => {
														const toUpdate = {
															...values,
														};
														if (
															! value &&
															toUpdate[
																field.name
																// @ts-expect-error To be fixed in the future perhaps.
															].indexOf( option.id ) > -1
														) {
															toUpdate[
																field.name
																// @ts-expect-error To be fixed in the future perhaps.
															].value = toUpdate[
																field.name
																// @ts-expect-error To be fixed in the future perhaps.
															].splice(
																toUpdate[
																	field.name
																	// @ts-expect-error To be fixed in the future perhaps.
																].indexOf( option.id ),
																1
															);
														}
														if (
															value &&
															toUpdate[
																field.name
																// @ts-expect-error To be fixed in the future perhaps.
															].indexOf( option.id ) === -1
														) {
															toUpdate[
																field.name
																// @ts-expect-error To be fixed in the future perhaps.
															].push( option.id );
														}
														setValues( toUpdate );
														setIsDirty( true );
													} }
												/>
											</BaseControl>
										) ) }
									</BaseControl>
								) }
								{ 'string' === field.type && field.max_length && Array.isArray( values ) && 150 < field.max_length && (
									<TextareaControl
										className="newspack-textarea-control"
										label={ field.label }
										disabled={ inFlight }
										help={ `${ ( values[ field.name ] as string | undefined )?.length || 0 } / ${ field.max_length }` }
										onChange={ ( value: string ) => {
											if (
												value.length >
												// @ts-expect-error There's a check for max_length above.
												field.max_length
											) {
												return;
											}

											const toUpdate = {
												...values,
											};
											toUpdate[ field.name ] = value;
											setValues( toUpdate );
											setIsDirty( true );
										} }
										placeholder={ typeof field.default === 'string' ? field.default : '' }
										rows={ 10 }
										// @ts-expect-error TS still does not see it as a string.
										value={ typeof values[ field.name ] === 'string' ? values[ field.name ] : '' }
									/>
								) }
								{ 'string' === field.type && field.max_length && 150 >= field.max_length && (
									<TextControl
										label={ field.label }
										disabled={ inFlight }
										help={ `${
											// @ts-expect-error To be fixed in the future perhaps.
											values[ field.name ]?.length || 0
										} / ${ field.max_length }` }
										onChange={ ( value: string ) => {
											if (
												value.length >
												// @ts-expect-error There's a check for max_length above.
												field.max_length
											) {
												return;
											}

											const toUpdate = {
												...values,
											};
											toUpdate[ field.name ] = value;
											setValues( toUpdate );
											setIsDirty( true );
										} }
										placeholder={ field.default }
										value={ values[ field.name ] || '' }
									/>
								) }
								{ 'int' === field.type && 'featured_image_id' === field.name && (
									<BaseControl id={ `newspack-engagement-wizard__${ field.name }` } label={ field.label }>
										<ImageUpload
											buttonLabel={ __( 'Select file', 'newspack-plugin' ) }
											disabled={ inFlight }
											image={ image }
											onChange={ ( attachment: Attachment ) => {
												const toUpdate = {
													...values,
												};
												toUpdate[ field.name ] = attachment?.id || 0;
												if ( toUpdate[ field.name ] !== values[ field.name ] ) {
												}
												setValues( toUpdate );
												setIsDirty( true );
												if ( attachment?.url ) {
													setImage( attachment );
												} else {
													setImage( null );
												}
											} }
										/>
									</BaseControl>
								) }
							</Fragment>
						) ) }
						{ error && <Notice noticeText={ error?.message || __( 'Something went wrong.', 'newspack-plugin' ) } isError /> }
						{ success && <Notice noticeText={ success } isSuccess /> }
						<div className="newspack-buttons-card">
							<Button
								isPrimary
								onClick={ () => {
									setIsSavingFromPreview( false );
									savePrompt( prompt.slug, values );
								} }
								disabled={ inFlight }
							>
								{ inFlight
									? __( 'Saving…', 'newspack-plugin' )
									: sprintf(
											// translators: %s: save or update settings.
											__( '%s prompt settings', 'newspack-plugin' ),
											prompt.ready ? __( 'Update', 'newspack-plugin' ) : __( 'Save', 'newspack-plugin' )
									  ) }
							</Button>
							<WebPreview
								url={ getPreviewUrl( prompt ) }
								renderButton={ ( { showPreview }: { showPreview: () => void } ) => (
									<Button disabled={ inFlight } icon={ seen } isSecondary onClick={ async () => showPreview() }>
										{ __( 'Preview prompt', 'newspack-plugin' ) }
									</Button>
								) }
							/>
						</div>
					</div>
					{ helpInfo && (
						<div className="newspack-ras-campaign__help">
							{ helpInfo.screenshot && <img src={ helpInfo.screenshot } alt={ prompt.title } /> }
							{ helpInfo.description && (
								<p>
									<span
										dangerouslySetInnerHTML={ {
											__html: helpInfo.description,
										} }
									/>{ ' ' }
									{ helpInfo.url && (
										<ExternalLink href={ 'https://none.com' }>{ __( 'Learn more', 'newspack-plugin' ) }</ExternalLink>
									) }
								</p>
							) }
							{ helpInfo.recommendations && (
								<>
									<h4 className="newspack-ras-campaign__recommendation-heading">{ __( 'We recommend', 'newspack-plugin' ) }</h4>
									<ul>
										{ helpInfo.recommendations.map( ( recommendation, index ) => (
											<li key={ index }>
												<span
													dangerouslySetInnerHTML={ {
														__html: recommendation,
													} }
												/>
											</li>
										) ) }
									</ul>
								</>
							) }
						</div>
					) }
				</Grid>
			}
		</ActionCard>
	);
}
