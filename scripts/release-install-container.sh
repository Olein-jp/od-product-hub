#!/usr/bin/env bash
set -euo pipefail

VERSION="${1:?Release version is required.}"
SOURCE_ROOT="/var/www/html"
WP_PATH="/tmp/odph-release-wp"
ZIP_PATH="$SOURCE_ROOT/wp-content/plugins/od-product-hub/dist/od-product-hub-$VERSION.zip"
PREFIX="odph_release_"

if [[ ! -f "$ZIP_PATH" ]]; then
	echo "Release ZIP was not mounted into the test container." >&2
	exit 1
fi

wp eval "global \$wpdb; foreach ( \$wpdb->get_col( \"SHOW TABLES LIKE 'odph_release_%'\" ) as \$table ) { \$wpdb->query( \$wpdb->prepare( 'DROP TABLE %i', \$table ) ); }" --path="$SOURCE_ROOT"

rm -rf "$WP_PATH"
mkdir -p "$WP_PATH/wp-content/plugins" "$WP_PATH/wp-content/themes"
cp -R "$SOURCE_ROOT/wp-admin" "$WP_PATH/wp-admin"
cp -R "$SOURCE_ROOT/wp-includes" "$WP_PATH/wp-includes"
find "$SOURCE_ROOT" -maxdepth 1 -type f -name '*.php' -exec cp {} "$WP_PATH/" \;
cp "$SOURCE_ROOT/wp-config.php" "$WP_PATH/wp-config.php"
sed -i.bak -E "s/^\\\$table_prefix = .*;/\\\$table_prefix = '$PREFIX';/" "$WP_PATH/wp-config.php"
rm -f "$WP_PATH/wp-config.php.bak"

wp core install \
	--path="$WP_PATH" \
	--url="http://odph-release.test" \
	--title="ODPH Release Test" \
	--admin_user="release-admin" \
	--admin_password="release-test-password" \
	--admin_email="release@example.test" \
	--skip-email

wp plugin install "$ZIP_PATH" --activate --path="$WP_PATH"
wp plugin is-active od-product-hub --path="$WP_PATH"
wp eval "if ( '$VERSION' !== OD_PRODUCT_HUB_VERSION || '7.0' !== get_bloginfo( 'version' ) || version_compare( PHP_VERSION, '8.3', '<' ) ) { WP_CLI::error( 'Unexpected release runtime or plugin version.' ); } WP_CLI::success( 'WordPress 7.0 / PHP ' . PHP_VERSION . ' / plugin $VERSION activation passed.' );" --path="$WP_PATH"

if [[ -s "$WP_PATH/wp-content/debug.log" ]]; then
	cat "$WP_PATH/wp-content/debug.log" >&2
	exit 1
fi
