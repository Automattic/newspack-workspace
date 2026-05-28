#!/bin/bash
cd $(dirname "$(dirname "$0")")
wp i18n make-pot . languages/newspack-blocks.pot --domain=newspack-blocks --package-name='Newspack Blocks'

cd languages
for po in newspack-blocks-*.po; do
	# Update translations according to the new POT file
	msgmerge $po newspack-blocks.pot -o $po.out
	mv $po.out $po
	msgfmt $po -o $(basename $po .po).mo
	# no-purge since we need the JS translations for the next run
	wp i18n make-json --no-purge $po .
done
