export type DataSource = {
	type: string;
	name: string;
	base?: string;
	spreadsheet?: string;
	table?: string;
	sheet?: string;
};

export type DataSourceConfig = DataSource & {
	fields: string[];
};
