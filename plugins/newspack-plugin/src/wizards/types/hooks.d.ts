/**
 * API Method types
 */
type ApiMethods = 'GET' | 'POST' | 'PUT' | 'DELETE';

/**
 * useWizardApiFetch hook types
 */
interface ApiFetchOptions {
	path: string;
	method?: ApiMethods;
	/** Data to send along with request */
	data?: any;
	/** Display simplified loading status during request */
	isQuietFetch?: boolean;
	quiet?: boolean;
	/** Throw errors to be caught in hooks/components */
	isLocalError?: boolean;
	/** Should this request be cached. If omitted and `GET` method is used the request will cache automatically */
	isCached?: boolean;
	/** Update a specific cacheKey, requires `{ [path]: method }` format */
	updateCacheKey?: { [k: string]: ApiMethods };
	/** Will purge and replace cache keys matching method. Well suited for endpoints where only the `method` changes */
	updateCacheMethods?: ApiMethods[];
}

/**
 * API callback functions
 */
interface ApiFetchCallbacks<T> {
	onStart?: () => void;
	onSuccess?: (data: T) => void;
	onError?: (error: any) => void;
	onFinally?: () => void;
}

/**
 * WP API Fetch error
 */
type WpFetchError = Error & {
	code: string;
	data?: null | {
		status: number;
	};
};

/**
 * Wizard store schema
 */
type WizardData = {
	error: WizardApiError | null;
} & {
	[key: string]: { [k in ApiMethods]?: Record<string, any> | null };
};

// Define the type for the selector's return value
type WizardSelector = {
	getWizardData: (slug: string) => WizardData;
	isLoading: () => boolean;
};

/**
 * Reader Revenue Wizard Data
 */
type AudienceFieldConfig = {
	autocomplete: string;
	class: string[];
	label: string;
	priority: number;
	required: boolean;
	type: string;
	validate: string[];
};

type ProductValidation = {
	product_id: number;
	product_name?: string;
	frequency: string;
	issues: string[];
};
type AudienceDonationsWizardData = {
	donation_data:
	| { errors: { [key: string]: string[] } }
	| {
		amounts: {
			[Key in FrequencySlug as string]: [number, number, number, number];
		};
		disabledFrequencies: {
			[Key in FrequencySlug as string]: boolean;
		};
		currencySymbol: string;
		tiered: boolean;
		minimumDonation: string;
		billingFields: string[];
		trashed: string[];
	};
	platform_data: {
		platform: string;
	};
	donation_page: {
		editUrl: string;
		status: string;
	};
	available_billing_fields: {
		[key: string]: AudienceFieldConfig;
	};
	order_notes_field: AudienceFieldConfig;
	product_validation: {
		[key: string]: ProductValidation;
	};
};
