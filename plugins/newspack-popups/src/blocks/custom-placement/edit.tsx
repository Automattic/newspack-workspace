/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { useBlockProps } from '@wordpress/block-editor';
import { __, sprintf } from '@wordpress/i18n';
import { ExternalLink, Notice, Placeholder, SelectControl, Spinner } from '@wordpress/components';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';
import { megaphone } from '@wordpress/icons';

type CustomPlacementAttributes = {
	customPlacement: string;
	className: string;
};

type CustomPlacementEditorProps = {
	attributes: CustomPlacementAttributes;
	setAttributes: ( attributes: Partial< CustomPlacementAttributes > ) => void;
};

/** A segment assigned to a prompt, as returned by the custom-placement REST endpoint. */
type CustomPlacementSegment = {
	id?: string | number;
	name?: string;
	priority: number;
};

/** A prompt eligible for a given custom placement, as returned by the custom-placement REST endpoint. */
type CustomPlacementPrompt = {
	id: number;
	title: string;
	segments?: CustomPlacementSegment[];
};

export const CustomPlacementEditor = ( { attributes, setAttributes }: CustomPlacementEditorProps ) => {
	const [ loading, setLoading ] = useState( false );
	const [ prompts, setPrompts ] = useState< CustomPlacementPrompt[] | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const { customPlacement } = attributes;
	const customPlacements = window.newspack_popups_blocks_data?.custom_placements || {};
	// Spread (rather than the original `.concat()`) so the shared element type below
	// (`disabled` optional) applies to both parts of the array without a cast.
	const customPlacementOptions: { label: string; value: string; disabled?: boolean }[] = [
		{
			label: __( 'Choose a custom placement', 'newspack-popups' ),
			value: '',
			disabled: true,
		},
		...Object.keys( customPlacements ).map( key => ( {
			value: key,
			label: customPlacements[ key ],
		} ) ),
	];
	const blockProps = useBlockProps();

	const getPrompts = async () => {
		setError( null );
		setLoading( true );

		try {
			const response = await apiFetch< CustomPlacementPrompt[] >( {
				path: addQueryArgs( '/newspack-popups/v1/custom-placement/', {
					custom_placement: customPlacement,
				} ),
				method: 'GET',
			} );

			setPrompts( response );
			setLoading( false );
		} catch ( e ) {
			// A rejected apiFetch() call rejects with a parsed error object (or, rarely, a raw
			// Error); narrow at this boundary rather than assuming its shape.
			const err = e as { message?: string };
			setError( err?.message || __( 'There was an error fetching prompts for this custom placement.', 'newspack-popups' ) );
			setLoading( false );
		}
	};

	useEffect( () => {
		if ( customPlacement ) {
			getPrompts();
		}
	}, [ customPlacement ] );

	const segments: Record< string, { id: string | number | null; prompts: { id: number; title: string }[] } > = {};

	if ( prompts ) {
		prompts.forEach( prompt => {
			const assignedSegments = prompt.segments || [];

			assignedSegments.forEach( segment => {
				const segmentName = segment?.name || 'Everyone else';

				if ( ! segments[ segmentName ] ) {
					segments[ segmentName ] = {
						id: segment.id || null,
						prompts: [],
					};
				}

				segments[ segmentName ].prompts.push( { id: prompt.id, title: prompt.title } );
			} );
		} );
	}

	return (
		<div { ...blockProps }>
			<Placeholder
				className="newspack-popups__custom-placement-placeholder"
				label={ __( 'Custom Placement', 'newspack-popups' ) }
				icon={ megaphone }
			>
				<SelectControl
					id="newspack-popups__custom-placement-select"
					onChange={ _customPlacement => setAttributes( { customPlacement: _customPlacement } ) }
					value={ -1 < Object.keys( customPlacements ).indexOf( customPlacement ) ? customPlacement : '' }
					options={ customPlacementOptions }
				/>

				{ loading && <Spinner /> }

				{ error && (
					<div className="newspack-popups__custom-placement-prompts">
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					</div>
				) }

				{ ! loading && ! error && Array.isArray( prompts ) && (
					<div className="newspack-popups__custom-placement-prompts">
						{ 0 === prompts.length && (
							<Notice status="warning" isDismissible={ false }>
								{ __( 'No active prompts found for this custom placement.', 'newspack-popups' ) }
							</Notice>
						) }
						{ 0 < prompts.length && (
							<>
								<p>
									{ sprintf(
										// translators: %1$s: max number of popups displayed. %2$s: plural modifier.
										__(
											'This custom placement will display at most %1$sthe following active prompt%2$s, depending on the reader’s top-priority segment:',
											'newspack-popups'
										),
										1 < prompts.length ? 'one of ' : '',
										1 < prompts.length ? 's' : ''
									) }
								</p>
								{ Object.keys( segments ).map( segmentName => {
									const segmentId = segments[ segmentName ].id;
									return (
										<Fragment key={ segmentId }>
											<strong>
												{ segmentId ? (
													<ExternalLink
														href={ `/wp-admin/admin.php?page=newspack-audience-campaigns#/campaigns/${ segmentId }` }
													>
														{ sprintf(
															// translators: %s: segment name.
															__( 'Segment: %s', 'newspack-popups' ),
															segmentName
														) }
													</ExternalLink>
												) : (
													[
														'Everyone' !== segmentName ? __( 'Segment:', 'newspack-popups' ) : '',
														segmentName || '',
														'Everyone' === segmentName && 1 < Object.keys( segments ).length
															? __( 'else', 'newspack-popups' )
															: '',
													]
												) }
											</strong>
											<ul>
												{ segments[ segmentName ].prompts.map( prompt => (
													<li key={ prompt.id }>
														<ExternalLink href={ `/wp-admin/post.php?post=${ prompt.id }&action=edit` }>
															{ prompt.title }
														</ExternalLink>
													</li>
												) ) }
											</ul>
										</Fragment>
									);
								} ) }
							</>
						) }
					</div>
				) }
			</Placeholder>
		</div>
	);
};
