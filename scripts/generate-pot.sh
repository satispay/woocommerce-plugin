mkdir tmp
curl https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o tmp/wp-cli.phar
php tmp/wp-cli.phar i18n make-pot . tmp/woo-satispay.pot --slug=woo-satispay --exclude=tmp-svn
