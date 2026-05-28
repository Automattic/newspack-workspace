/**
 * Institution editor — 2-column grid matching gate editor pattern.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
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
	Router,
	SectionHeader,
	TextControl,
	useConfirmDialog,
} from '../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';

const { useHistory } = Router;

const API_PATH = '/wp/v2/np_institution';

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

	useEffect( () => {
		if ( ! isNew ) {
			setIsLoading( true );
			apiFetch< Institution >( { path: `${ API_PATH }/${ id }?context=edit` } )
				.then( data => {
					setInstitution( data );
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

	const toggleRule = useCallback( ( key: string ) => {
		setIsDirty( true );
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
			sectionName: isNew ? __( 'Add institution', 'newspack-plugin' ) : __( 'Edit institution', 'newspack-plugin' ),
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
						description={ __( 'Match visitors by IP address or CIDR block', 'newspack-plugin' ) }
						icon={ globe }
						actionType="toggle"
						isActive={ enabledRules.np_institution_ip_range }
						onEnable={ () => toggleRule( 'np_institution_ip_range' ) }
					>
						<CardBody size="small">
							<TextControl
								label={ __( 'IPs / CIDR blocks (comma-separated)', 'newspack-plugin' ) }
								value={ ipRange }
								onChange={ ( val: string ) => updateMeta( 'np_institution_ip_range', val ) }
								placeholder="192.168.1.0/24, 10.0.0.5"
							/>
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
