export type Pattern = {
	type: string;
	name: string;
	title: string;
	description: string;
	content: string;
	fields: {
		name: string;
		type: string;
	}[];
};
