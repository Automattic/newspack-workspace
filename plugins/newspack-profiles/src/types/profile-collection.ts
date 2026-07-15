import type { DataSource } from './data-source';

export type Status = 'draft' | 'publish';

export type ProfileCollection = {
	status: Status;
	name: string;
	slug: string;
	slugFields: string[];
	titleFields: string[];
	seoFields: {
		title: string[];
		description: string[];
		image: string;
	};
	dataSource: DataSource;
	mappings: Record< string, TypeMapping >;
	pattern: {
		single: string;
		list: string;
	};
	pages: {
		single: number;
		list: number;
	};
	isImporting?: boolean;
};

export type ProfileCollectionPayload = {
	status: Status;
	name: string;
	slug: string;
	slugFields: string[];
	titleFields: string[];
	seoFields: {
		title: string[];
		description: string[];
		image: string;
	};
	dataSource: DataSource;
	mappings: Record< string, TypeMapping >;
	pattern: {
		single: string;
		list: string;
	};
};

export type TypeMapping = {
	label?: string;
	type?: string;
	social_platform?: string;
	visible?: boolean;
	order?: number;
};
