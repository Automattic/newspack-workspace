const { registerBlockType } = wp.blocks;

type RegisterableBlock = {
	name: string;
	settings: Record< string, unknown >;
};

export const registerBlock = ( block?: RegisterableBlock | null ) => {
	if ( ! block ) {
		return;
	}

	const { name, settings } = block;
	registerBlockType( name, settings );
};
