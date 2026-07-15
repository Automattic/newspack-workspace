import { createReduxStore, register } from '@wordpress/data';
import type { DataSourceConfig } from '../types/data-source';
import type {
	ProfileCollectionPayload,
	Status,
	TypeMapping,
} from '../types/profile-collection';
import { sanitizeDataSource, sanitizeSlugFields } from '../utils';

const STORE_KEY = 'newspack-profiles/onboarding';

export type OnboardingState = {
	status: Status;
	currentStep: number;
	profileName: string;
	profileSlug: string;
	slugFields: string[];
	titleFields: string[];
	seoFields: {
		title: string[];
		description: string[];
		image: string;
	};
	dataSource: DataSourceConfig;
	mappings: Record< string, TypeMapping >;
	blockPattern: {
		single: string;
		list: string;
	};
	pageTemplates: {
		single: number;
		list: number;
	};
};

const DEFAULT_STATE: OnboardingState = {
	status: 'draft',
	currentStep: 1,
	profileName: '',
	profileSlug: '',
	slugFields: [],
	titleFields: [],
	seoFields: {
		title: [],
		description: [],
		image: '',
	},
	dataSource: {
		type: '',
		name: '',
		fields: [],
	},
	mappings: {},
	blockPattern: {
		single: '',
		list: '',
	},
	pageTemplates: {
		single: 0,
		list: 0,
	},
};

const MAX_STEPS: number = 4;

const reducer = (
	state: OnboardingState = DEFAULT_STATE,
	action: any
): OnboardingState => {
	switch ( action.type ) {
		case 'RESET_ONBOARDING':
			return action.state;
		case 'NEXT_STEP':
			return {
				...state,
				currentStep: Math.min( state.currentStep + 1, MAX_STEPS ),
			};
		case 'PREVIOUS_STEP':
			return {
				...state,
				currentStep: Math.max( state.currentStep - 1, 1 ),
			};
		case 'GO_TO_STEP':
			return {
				...state,
				currentStep: Math.min( Math.max( action.step, 1 ), MAX_STEPS ),
			};
		case 'SET_PROFILE_NAME':
			return {
				...state,
				profileName: action.profileName ?? '',
			};
		case 'SET_PROFILE_SLUG':
			return {
				...state,
				profileSlug: action.profileSlug ?? '',
			};
		case 'SET_SLUG_FIELDS':
			return {
				...state,
				slugFields: action.slugFields ?? [],
			};
		case 'SET_TITLE_FIELDS':
			return {
				...state,
				titleFields: action.titleFields ?? [],
			};
		case 'SET_SEO_FIELDS':
			return {
				...state,
				seoFields: {
					...state.seoFields,
					...action.seoFields,
				},
			};
		case 'SET_DATA_SOURCE':
			return {
				...state,
				dataSource: action.dataSource ?? {
					type: '',
					name: '',
					fields: [],
				},
				slugFields: sanitizeSlugFields(
					state.slugFields,
					action.dataSource.fields
				),
				titleFields: [],
				mappings: {
					...( action?.dataSource?.fields ?? [] ).reduce(
						(
							acc: Record< string, TypeMapping >,
							field: string,
							index: number
						) => {
							acc[ field ] = state.mappings[ field ] || {
								type: 'string',
								visible: true,
								order: index,
							};

							return acc;
						},
						{} as Record< string, TypeMapping >
					),
				},
				seoFields: {
					title: [],
					description: [],
					image: '',
				},
			};
		case 'SET_MAPPINGS':
			return {
				...state,
				mappings: action.mappings ?? {},
			};
		case 'SET_BLOCK_PATTERN':
			return {
				...state,
				blockPattern: {
					...state.blockPattern,
					...action.blockPattern,
				},
			};
		default:
			return state;
	}
};

const actions = {
	resetOnboarding( state?: OnboardingState ) {
		return {
			type: 'RESET_ONBOARDING',
			state: state || DEFAULT_STATE,
		};
	},
	nextStep() {
		return {
			type: 'NEXT_STEP',
		};
	},
	previousStep() {
		return {
			type: 'PREVIOUS_STEP',
		};
	},
	goToStep( step: number ) {
		return {
			type: 'GO_TO_STEP',
			step,
		};
	},
	setProfileName( profileName: string ) {
		return {
			type: 'SET_PROFILE_NAME',
			profileName,
		};
	},
	setProfileSlug( profileSlug: string ) {
		return {
			type: 'SET_PROFILE_SLUG',
			profileSlug,
		};
	},
	setSlugFields( slugFields: string[] ) {
		return {
			type: 'SET_SLUG_FIELDS',
			slugFields,
		};
	},
	setTitleFields( titleFields: string[] ) {
		return {
			type: 'SET_TITLE_FIELDS',
			titleFields,
		};
	},
	setSeoFields( seoFields: {
		title?: string[];
		description?: string[];
		image?: string;
	} ) {
		return {
			type: 'SET_SEO_FIELDS',
			seoFields,
		};
	},
	setDataSource( dataSource: DataSourceConfig ) {
		return {
			type: 'SET_DATA_SOURCE',
			dataSource,
		};
	},
	setMappings( mappings: Record< string, TypeMapping > ) {
		return {
			type: 'SET_MAPPINGS',
			mappings,
		};
	},
	setBlockPattern( blockPattern: { single?: string; list?: string } ) {
		return {
			type: 'SET_BLOCK_PATTERN',
			blockPattern,
		};
	},
};

const selectors = {
	getCurrentStep( state: OnboardingState ) {
		return state.currentStep;
	},
	getProfileName( state: OnboardingState ) {
		return state.profileName;
	},
	getProfileSlug( state: OnboardingState ) {
		return state.profileSlug;
	},
	getSlugFields( state: OnboardingState ) {
		return state.slugFields;
	},
	getTitleFields( state: OnboardingState ) {
		return state.titleFields;
	},
	getSEOFields( state: OnboardingState ) {
		return state.seoFields;
	},
	getDataSource( state: OnboardingState ) {
		return state.dataSource;
	},
	getMappings( state: OnboardingState ) {
		return state.mappings;
	},
	getBlockPattern( state: OnboardingState ) {
		return state.blockPattern;
	},
	getProfileCollectionPayload(
		state: OnboardingState
	): ProfileCollectionPayload {
		return {
			status: state.status,
			name: state.profileName,
			slug: state.profileSlug,
			slugFields: state.slugFields,
			titleFields: state.titleFields,
			seoFields: state.seoFields,
			dataSource: sanitizeDataSource( state.dataSource ),
			mappings: state.mappings,
			pattern: state.blockPattern,
		};
	},
	hasNextStep( state: OnboardingState ) {
		return state.currentStep < MAX_STEPS;
	},
	hasPreviousStep( state: OnboardingState ) {
		return state.currentStep > 1;
	},
	canProceedToNextStep( state: OnboardingState ) {
		switch ( state.currentStep ) {
			case 1:
				return state.dataSource?.type !== '';
			case 2:
				return Object.keys( state.mappings ?? {} ).length > 0;
			case 3:
				return (
					state.blockPattern.single !== '' &&
					state.blockPattern.list !== ''
				);
			case 4:
				return (
					state.profileName !== '' &&
					state.profileSlug !== '' &&
					state.slugFields.length > 0
				);
			default:
				return true;
		}
	},
};

/**
 * Redux store for onboarding state management.
 */
export const store = createReduxStore( STORE_KEY, {
	reducer,
	actions,
	selectors,
} );

register( store );
