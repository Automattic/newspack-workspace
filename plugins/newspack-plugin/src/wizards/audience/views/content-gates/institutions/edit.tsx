/**
 * Institution editor — 2-column grid matching gate editor pattern.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { __experimentalVStack as VStack, TextareaControl, CardBody, Spinner } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { useDispatch } from '@wordpress/data';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { envelope, globe, customPostType } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import {
	CardSettingsGroup,
	Divider,
	Grid,
	ImageUpload,
	Notice,
	Router,
	SectionHeader,
	TextControl,
	useConfirmDialog,
} from '../../../../../../packages/components/src';
import { analyzeIpRangeEntries, EMPTY_IP_RANGE_ANALYSIS, OVER_BROAD_RANGE_SIZE } from './utils';
import type { ConfusableCharacterKey, IpRangeAnalysis } from './utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';

const { useHistory } = Router;

const API_PATH = '/wp/v2/np_institution';

const IP_RANGE_MESSAGES_ID = 'newspack-institution-ip-range-messages';

// Entries are echoed back so the admin can find them, but a pasted list can be
// hundreds of items long: show enough to identify the problem, then a count.
const MAX_LISTED_ENTRIES = 3;

/**
 * Render a list of offending entries, capped so a long paste stays readable.
 */
function formatEntryList( entries: string[] ): string {
	if ( entries.length <= MAX_LISTED_ENTRIES ) {
		return entries.join( ', ' );
	}
	const remaining = entries.length - MAX_LISTED_ENTRIES;
	return sprintf(
		/* translators: %1$s: comma-separated list of the first few entries. %2$d: number of remaining entries. */
		_n( '%1$s and %2$d more', '%1$s and %2$d more', remaining, 'newspack-plugin' ),
		entries.slice( 0, MAX_LISTED_ENTRIES ).join( ', ' ),
		remaining
	);
}

/**
 * Human-readable name for a character that looks like a hyphen or a space but isn't.
 *
 * Copy-pasted ranges routinely carry these, and on screen they are indistinguishable
 * from the correct character — so the warning has to name them.
 */
function getConfusableCharacterLabel( key: ConfusableCharacterKey ): string {
	switch ( key ) {
		case 'hyphen':
			return __( 'Unicode hyphen (‐)', 'newspack-plugin' );
		case 'non-breaking-hyphen':
			return __( 'non-breaking hyphen (‑)', 'newspack-plugin' );
		case 'figure-dash':
			return __( 'figure dash (‒)', 'newspack-plugin' );
		case 'en-dash':
			return __( 'en dash (–)', 'newspack-plugin' );
		case 'em-dash':
			return __( 'em dash (—)', 'newspack-plugin' );
		case 'minus-sign':
			return __( 'minus sign (−)', 'newspack-plugin' );
		case 'fullwidth-hyphen-minus':
			return __( 'fullwidth hyphen (－)', 'newspack-plugin' );
		case 'non-breaking-space':
			return __( 'non-breaking space', 'newspack-plugin' );
		case 'narrow-no-break-space':
			return __( 'narrow non-breaking space', 'newspack-plugin' );
		case 'ideographic-space':
			return __( 'ideographic space', 'newspack-plugin' );
		case 'zero-width-space':
			return __( 'zero-width space', 'newspack-plugin' );
		case 'byte-order-mark':
			return __( 'byte order mark', 'newspack-plugin' );
	}
}

/**
 * Warnings to show for an analyzed IP range value, in order of severity.
 *
 * Shared by the inline notices under the field and by the snackbar raised on
 * save, so an admin who pastes a list and clicks Save without ever leaving the
 * field still gets the same message.
 *
 * @param analysis The result of analyzing the field value.
 * @return One sentence per warning class that applies; empty when the value is clean.
 */
function getIpRangeWarnings( analysis: IpRangeAnalysis ): string[] {
	const { invalid, confusableCharacters, overBroad } = analysis;
	const warnings: string[] = [];
	if ( invalid.length > 0 ) {
		warnings.push(
			sprintf(
				/* translators: %1$s: comma-separated list of invalid IP entries. %2$s: single IP example. %3$s: CIDR block example. %4$s: IP range example. */
				_n(
					'This entry is invalid and will never grant access: %1$s. Use a single IPv4 address (e.g. %2$s), a CIDR block (e.g. %3$s), or an IP range from lowest to highest (e.g. %4$s).',
					'These entries are invalid and will never grant access: %1$s. Use a single IPv4 address (e.g. %2$s), a CIDR block (e.g. %3$s), or an IP range from lowest to highest (e.g. %4$s).',
					invalid.length,
					'newspack-plugin'
				),
				formatEntryList( invalid ),
				'203.0.113.5',
				'198.51.100.0/24',
				'203.0.113.0-203.0.113.255'
			)
		);
	}
	if ( confusableCharacters.length > 0 ) {
		// Phrased without a count: the list names characters while the sentence is
		// about entries, and one entry can carry several of them.
		warnings.push(
			sprintf(
				/* translators: %s: comma-separated list of character names, e.g. "en dash (–)". */
				__(
					'One or more entries contain characters that look standard but are not: %s. Retype them using a plain hyphen (-) and regular spaces.',
					'newspack-plugin'
				),
				confusableCharacters.map( getConfusableCharacterLabel ).join( ', ' )
			)
		);
	}
	if ( overBroad.length > 0 ) {
		warnings.push(
			sprintf(
				/* translators: %1$s: comma-separated list of unusually wide IP entries. %2$s: formatted number of addresses. */
				_n(
					'This entry covers more than %2$s addresses: %1$s. Check the end address or CIDR mask — an over-broad entry grants access far beyond the institution.',
					'These entries each cover more than %2$s addresses: %1$s. Check the end addresses or CIDR masks — an over-broad entry grants access far beyond the institution.',
					overBroad.length,
					'newspack-plugin'
				),
				formatEntryList( overBroad ),
				// The number sits inside a translated sentence, so format it in the
				// admin's locale rather than whatever the browser happens to be set to.
				new Intl.NumberFormat( document.documentElement.lang || undefined ).format( OVER_BROAD_RANGE_SIZE )
			)
		);
	}
	return warnings;
}

const EMPTY_INSTITUTION: Omit< Institution, 'id' > = {
	title: { raw: '', rendered: '' },
	excerpt: { raw: '', rendered: '' },
	featured_media: 0,
	slug: '',
	status: 'publish',
	meta: {
		np_institution_email_domain: '',
		np_institution_ip_range: '',
		np_institution_reader_data: '',
	},
};

export default function InstitutionEdit( { match }: { match: { params: { id?: string } } } ) {
	const history = useHistory();
	const id = match.params.id;
	const isNew = ! id || id === 'new';

	const { setHeaderData, startLoadingData, finishLoadingData, addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

	const [ institution, setInstitution ] = useState( EMPTY_INSTITUTION );
	const [ enabledRules, setEnabledRules ] = useState< Record< string, boolean > >( {
		np_institution_email_domain: false,
		np_institution_ip_range: false,
		np_institution_reader_data: false,
	} );
	const [ isLoading, setIsLoading ] = useState( ! isNew );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isDirty, setIsDirty ] = useState( false );
	const [ imageData, setImageData ] = useState< { id: number; url: string } | null >( null );
	const [ isLoadingImage, setIsLoadingImage ] = useState( false );
	// Evaluated when the IP range field is committed (on blur, and on load for
	// stored values) rather than on every keystroke: a half-typed range is not
	// yet wrong, and warning about it teaches admins to ignore the warning.
	const [ ipRangeAnalysis, setIpRangeAnalysis ] = useState( EMPTY_IP_RANGE_ANALYSIS );

	useEffect( () => {
		if ( ! isNew ) {
			setIsLoading( true );
			apiFetch< Institution >( { path: `${ API_PATH }/${ id }?context=edit` } )
				.then( data => {
					setInstitution( data );
					setIpRangeAnalysis( analyzeIpRangeEntries( data.meta?.np_institution_ip_range || '' ) );
					setEnabledRules( {
						np_institution_email_domain: !! data.meta?.np_institution_email_domain,
						np_institution_ip_range: !! data.meta?.np_institution_ip_range,
						np_institution_reader_data: !! data.meta?.np_institution_reader_data,
					} );
				} )
				.finally( () => setIsLoading( false ) );
		}
	}, [ id, isNew ] );

	// Resolve featured_media ID to an image object for the ImageUpload component.
	useEffect( () => {
		const mediaId = institution.featured_media;
		if ( mediaId ) {
			setIsLoadingImage( true );
			apiFetch< { id: number; source_url: string } >( { path: `/wp/v2/media/${ mediaId }` } )
				.then( media => setImageData( { id: media.id, url: media.source_url } ) )
				.catch( () => setImageData( null ) )
				.finally( () => setIsLoadingImage( false ) );
		} else {
			setImageData( null );
		}
	}, [ institution.featured_media ] );

	const updateField = useCallback( ( field: string, value: string ) => {
		setIsDirty( true );
		setInstitution( prev => ( {
			...prev,
			[ field ]:
				typeof prev[ field as keyof typeof prev ] === 'object' ? { ...( prev[ field as keyof typeof prev ] as object ), raw: value } : value,
		} ) );
	}, [] );

	const updateMeta = useCallback( ( key: string, value: string ) => {
		setIsDirty( true );
		setInstitution( prev => ( {
			...prev,
			meta: { ...prev.meta, [ key ]: value },
		} ) );
	}, [] );

	const updateIpRange = useCallback(
		( value: string ) => {
			updateMeta( 'np_institution_ip_range', value );
			// Drop stale warnings while editing; they are recomputed on blur.
			setIpRangeAnalysis( EMPTY_IP_RANGE_ANALYSIS );
		},
		[ updateMeta ]
	);

	const toggleRule = useCallback( ( key: string ) => {
		setIsDirty( true );
		if ( 'np_institution_ip_range' === key ) {
			setIpRangeAnalysis( EMPTY_IP_RANGE_ANALYSIS );
		}
		setEnabledRules( prev => {
			const nowEnabled = ! prev[ key ];
			if ( ! nowEnabled ) {
				// Clear the meta value when disabling.
				setInstitution( inst => ( {
					...inst,
					meta: { ...inst.meta, [ key ]: '' },
				} ) );
			}
			return { ...prev, [ key ]: nowEnabled };
		} );
	}, [] );

	const handleSave = useCallback( () => {
		setIsSaving( true );
		startLoadingData( { isQuietLoading: true } );
		// Saving unmounts this view, taking the inline notices with it — and the
		// common path is a paste followed straight by Save. Re-analyze the value
		// being saved and hand any warnings to the wizard snackbar, which outlives
		// the navigation. The save itself is never blocked.
		const warningsOnSave = getIpRangeWarnings( analyzeIpRangeEntries( institution.meta?.np_institution_ip_range || '' ) );
		const payload = {
			title: institution.title.raw,
			excerpt: institution.excerpt.raw,
			featured_media: institution.featured_media,
			status: 'publish',
			meta: institution.meta,
		};
		const request = isNew
			? apiFetch( { path: API_PATH, method: 'POST', data: payload } )
			: apiFetch( { path: `${ API_PATH }/${ id }`, method: 'POST', data: payload } );

		request
			.then( () => {
				setIsDirty( false );
				if ( warningsOnSave.length > 0 ) {
					addNotice( {
						message: sprintf(
							/* translators: %s: one or more sentences describing what is wrong with the saved IP range. */
							__( 'Institution saved, but its IP range needs attention. %s', 'newspack-plugin' ),
							warningsOnSave.join( ' ' )
						),
						type: 'warning',
						id: 'institution-ip-range-warning',
					} );
				}
				history.push( '/institutions' );
			} )
			.catch( () => {
				addNotice( {
					message: __( 'Failed to save institution. Please try again.', 'newspack-plugin' ),
					type: 'error',
					id: 'institution-save-error',
				} );
			} )
			.finally( () => {
				setIsSaving( false );
				finishLoadingData();
			} );
	}, [ institution, isNew, id, history, startLoadingData, finishLoadingData, addNotice ] );

	const handleDelete = useCallback( () => {
		startLoadingData( { isQuietLoading: true } );
		apiFetch( { path: `${ API_PATH }/${ id }?force=true`, method: 'DELETE' } )
			.then( () => {
				setIsDirty( false );
				history.push( '/institutions' );
			} )
			.catch( () => {
				addNotice( {
					message: __( 'Failed to delete institution. Please try again.', 'newspack-plugin' ),
					type: 'error',
					id: 'institution-delete-error',
				} );
			} )
			.finally( () => finishLoadingData() );
	}, [ id, history, startLoadingData, finishLoadingData, addNotice ] );

	const { confirmDialog: navBlockDialog } = useConfirmDialog( {
		when: isDirty && ! isSaving,
		message: __( 'You have unsaved changes that will be lost. Discard changes?', 'newspack-plugin' ),
		confirmButtonText: __( 'Discard changes', 'newspack-plugin' ),
		hideTitle: true,
	} );

	const { confirmDialog: deleteDialog, requestConfirm: requestDelete } = useConfirmDialog( {
		title: __( 'Are you sure?', 'newspack-plugin' ),
		confirmButtonText: __( 'Delete', 'newspack-plugin' ),
		isDestructive: true,
		message: __( 'This will permanently delete this institution. This action cannot be undone.', 'newspack-plugin' ),
	} );

	// Set header navigation and actions.
	useEffect( () => {
		setHeaderData( {
			backNav: '#/institutions',
			sectionName: isNew ? __( 'Add Institution', 'newspack-plugin' ) : __( 'Edit Institution', 'newspack-plugin' ),
		} );
	}, [ isNew, setHeaderData ] );

	// Set header save/delete actions once handlers are ready.
	useEffect( () => {
		const actions: HeaderAction[] = [
			{
				type: 'primary',
				label: __( 'Save', 'newspack-plugin' ),
				icon: null,
				action: handleSave,
				disabled: isSaving || ! institution.title.raw,
			},
		];
		if ( ! isNew ) {
			actions.push( {
				type: 'more',
				label: __( 'Delete', 'newspack-plugin' ),
				icon: null,
				action: () => requestDelete( handleDelete ),
				disabled: isSaving,
				destructive: true,
			} );
		}
		setHeaderData( { actions } );
	}, [ handleSave, handleDelete, requestDelete, institution.title.raw, isNew, isSaving, setHeaderData ] );

	if ( isLoading ) {
		return (
			<div style={ { display: 'flex', justifyContent: 'center', alignItems: 'center' } }>
				<Spinner />
			</div>
		);
	}

	const name = institution.title.raw;
	const description = institution.excerpt.raw;
	const meta = institution.meta || EMPTY_INSTITUTION.meta;
	const { np_institution_email_domain: emailDomain, np_institution_ip_range: ipRange, np_institution_reader_data: readerData } = meta;
	const ipRangeWarnings = getIpRangeWarnings( ipRangeAnalysis );

	return (
		<div className="newspack-institution__edit">
			{ navBlockDialog }
			{ deleteDialog }

			{ /* Section 1: Name and description */ }
			<Grid columns={ 2 } gutter={ 32 }>
				<SectionHeader
					title={ __( 'Name and description', 'newspack-plugin' ) }
					description={ __(
						'Identify this institution. The name and image are shown on the access verification page.',
						'newspack-plugin'
					) }
				/>
				<VStack spacing={ 4 }>
					<TextControl
						label={ __( 'Name', 'newspack-plugin' ) }
						value={ name }
						onChange={ ( val: string ) => updateField( 'title', val ) }
						withMargin={ false }
					/>
					<TextareaControl
						label={ __( 'Description', 'newspack-plugin' ) }
						value={ description }
						onChange={ ( val: string ) => updateField( 'excerpt', val ) }
					/>
					{ isLoadingImage ? (
						<Spinner />
					) : (
						<ImageUpload
							label={ __( 'Logo', 'newspack-plugin' ) }
							image={ imageData }
							onChange={ ( attachment: { id: number; url: string } | null ) => {
								setIsDirty( true );
								setImageData( attachment ? { id: attachment.id, url: attachment.url } : null );
								setInstitution( prev => ( { ...prev, featured_media: attachment?.id || 0 } ) );
							} }
						/>
					) }
				</VStack>
			</Grid>

			<Divider alignment="full-width" variant="tertiary" />

			{ /* Section 2: Access Rules */ }
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					title={ __( 'Access rules', 'newspack-plugin' ) }
					description={ __(
						'Define how readers from this institution are identified. Rules use OR logic — matching any rule grants access.',
						'newspack-plugin'
					) }
				/>
				<VStack spacing={ 4 }>
					<CardSettingsGroup
						title={ __( 'Email domain', 'newspack-plugin' ) }
						description={ __( 'Match readers by verified email domain', 'newspack-plugin' ) }
						icon={ envelope }
						actionType="toggle"
						isActive={ enabledRules.np_institution_email_domain }
						onEnable={ () => toggleRule( 'np_institution_email_domain' ) }
					>
						<CardBody size="small">
							<TextControl
								label={ __( 'Domains (comma-separated)', 'newspack-plugin' ) }
								value={ emailDomain }
								onChange={ ( val: string ) => updateMeta( 'np_institution_email_domain', val ) }
								placeholder="university.edu, school.org"
							/>
						</CardBody>
					</CardSettingsGroup>

					<CardSettingsGroup
						title={ __( 'IP range', 'newspack-plugin' ) }
						description={ __( 'Match visitors by IP address, CIDR block, or IP range', 'newspack-plugin' ) }
						icon={ globe }
						actionType="toggle"
						isActive={ enabledRules.np_institution_ip_range }
						onEnable={ () => toggleRule( 'np_institution_ip_range' ) }
					>
						<CardBody size="small">
							<TextControl
								label={ __( 'IPs, CIDR blocks, or IP ranges (comma-separated)', 'newspack-plugin' ) }
								value={ ipRange }
								onChange={ updateIpRange }
								onBlur={ ( event: React.FocusEvent< HTMLInputElement > ) =>
									setIpRangeAnalysis( analyzeIpRangeEntries( event.target.value ) )
								}
								placeholder="198.51.100.0/24, 203.0.113.0-203.0.113.255, 203.0.113.5"
								aria-describedby={ IP_RANGE_MESSAGES_ID }
								aria-invalid={ ipRangeAnalysis.invalid.length > 0 }
							/>
							{ /* Always rendered so it is an established live region by the time a warning appears. */ }
							<div id={ IP_RANGE_MESSAGES_ID } role="status">
								{ ipRangeWarnings.map( warning => (
									<Notice key={ warning } isWarning noticeText={ warning } />
								) ) }
							</div>
						</CardBody>
					</CardSettingsGroup>

					<CardSettingsGroup
						title={ __( 'Reader data', 'newspack-plugin' ) }
						description={ __( 'Match readers by custom metadata', 'newspack-plugin' ) }
						icon={ customPostType }
						actionType="toggle"
						isActive={ enabledRules.np_institution_reader_data }
						onEnable={ () => toggleRule( 'np_institution_reader_data' ) }
					>
						<CardBody size="small">
							<TextControl
								label={ __( 'Key=value pairs (semicolon-delimited)', 'newspack-plugin' ) }
								value={ readerData }
								onChange={ ( val: string ) => updateMeta( 'np_institution_reader_data', val ) }
								placeholder="org=university;role=staff"
							/>
						</CardBody>
					</CardSettingsGroup>
				</VStack>
			</Grid>
		</div>
	);
}
