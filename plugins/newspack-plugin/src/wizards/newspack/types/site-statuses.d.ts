type Dependencies = Record< string, { isActive: boolean; label: string } >;

type StatusLabels =
	| 'success'
	| 'error'
	| 'error-dependencies'
	| 'error-preflight'
	| 'error-request' // 404, 500
	| 'pending'
	| 'pending-install'
	| 'idle';

type Status = {
	label: string;
	statuses?: Partial< StatusLabels, string >;
	isPreflightValid?: boolean;
	configLink: string;
	endpoint: string;
	dependencies?: Dependencies;
	then: ( args: any ) => boolean;
};

type Statuses = {
	[ k: string ]: Status;
};

type SiteActionModal = {
	onRequestClose: ( a: boolean ) => void;
	onSuccess: ( a: Record< string, any > ) => void;
	plugins: string[];
};
