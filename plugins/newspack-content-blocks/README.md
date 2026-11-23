# Newspack Content Blocks

The Content Loop (`newspack-blocks/homepage-articles`) and Carousel (`newspack-blocks/carousel`) blocks, as a standalone plugin. They keep the block names, markup, hooks and REST routes they had in Newspack Blocks, so existing content and customizations keep working.

The plugin stays dormant while the Newspack Blocks plugin is active, so the two never register the same blocks.

## Development

Run `n build newspack-content-blocks` to build, and `n test-php` from this directory to run the PHP tests. See `AGENTS.md` for conventions.
