<?php

/**
 * Get the WP_Filesystem_Direct instance.
 *
 * @return WP_Filesystem_Direct	The instance.
 */
function mp_get_filesystem() {
	static $filesystem = false;
	if ( $filesystem ) {
		return $filesystem;
	}
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
	if ( ! defined( 'FS_CHMOD_DIR' ) ) {
		define( 'FS_CHMOD_DIR', false );
	}
	if ( ! defined( 'FS_CHMOD_FILE' ) ) {
		define( 'FS_CHMOD_FILE', false );
	}
	$filesystem = new WP_Filesystem_Direct( [] );
	return $filesystem;
}

function mp_update_pomo( $po_file, $po_url ) {

	/**
	 * Download .po file.
	 */
	$response = wp_remote_get( $po_url, [
		'timeout' => 10,
	] );

	/**
	 * Exit if URL doesn't work.
	 */
	if ( ! $response || is_wp_error( $response ) || 200 !== $response[ 'response' ][ 'code' ] ) {
		return false;
	}

	/**
	 * Prepare filesystem.
	 */
	$filesystem = mp_get_filesystem();

	/**
	 * Handle downloaded file.
	 */
	if ( ! file_exists( $po_file ) ) {
		/**
		 * If file doesn't exist then save downloaded and continue.
		 */
		$filesystem->put_contents( $po_file , $response[ 'body' ], 0644 );
	} else {

		/**
		 * Helper file.
		 */
		$tmp_file = MP_LANG_DIR . '/tmp-' . random_int( PHP_INT_MIN, PHP_INT_MAX ) . '.po';

		/**
		 * Save original file temporarily.
		 */
		$filesystem->put_contents( $tmp_file , $response[ 'body' ], 0644 );

		/**
		 * Update existing file intelligently.
		 */
		$merge_cmd = sprintf( 'msgmerge --no-wrap -U --backup=none %1$s %2$s', $po_file, $tmp_file );
		shell_exec( $merge_cmd );

		/**
		 * Sort header to prevent useless code changes.
		 */
		$header_sorting_cmd = sprintf( '{ head -n 4 %1$s; head -n 12 %1$s | tail -n +5 | sort; tail -n +13 %1$s; }', $tmp_file );
		$filesystem->put_contents( $po_file , trim( shell_exec( $header_sorting_cmd ) ) . "\n" , 0644 );

		/**
		 * Delete helper file.
		 */
		$filesystem->delete( $tmp_file );
	}

	/**
	 * Re-generate mo file.
	 */
	$mo_cmd = sprintf( 'msgfmt -o %s %s', preg_replace( '/po$/', 'mo', $po_file ), $po_file );
	shell_exec( $mo_cmd );
}

function mp_update_core_pomo() {

	$core = [
		'/' => '',
		'/admin' => 'admin',
		'/admin/network' => 'admin-network',
		'/cc' => 'continents-cities',
	];

	/**
	 * Make sure languages folder exists.
	 */
	$filesystem = mp_get_filesystem();
	$filesystem->mkdir( MP_LANG_DIR, 0755 );

	/**
	 * Download files.
	 */
	foreach ( $core as $path => $name ) {
		$po_file = MP_LANG_DIR . ( $name ? "/$name-ar.po" : '/ar.po' );
		$po_url = "https://translate.wordpress.org/projects/wp/dev" . $path . '/ar/default/export-translations?format=po';
		mp_update_pomo( $po_file, $po_url );
	}
}

function mp_update_package_pomo( $slug, $type ) {

	/**
	 * Check type.
	 */
	if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) ) {
		return;
	}

	/**
	 * Make sure directories exist.
	 */
	$filesystem = mp_get_filesystem();
	$filesystem->mkdir( MP_LANG_DIR, 0755 );
	$filesystem->mkdir( MP_LANG_DIR . "/{$type}s", 0755 );

	/**
	 * Update package.
	 */
	$po_file = MP_LANG_DIR . "/{$type}s/$slug-ar.po";
	$po_url = "https://translate.wordpress.org/projects/wp-{$type}s/" . $slug . ( 'plugin' === $type ? '/stable' : '' ) . '/ar/default/export-translations?format=po';
	mp_update_pomo( $po_file, $po_url );
}

/**
 * Get most popular plugins and themes.
 */
function mp_get_popular_packages( $type, $number = 30 ) {

	$url_format = 'https://wordpress.org/' . $type . 's/browse/popular/page/{{page}}/';
	$regex = '/\"https\:\/\/wordpress\.org\/' . $type . 's\/([a-z0-9-]+)\/\"/';
	$packages = [];

	/**
	 * Loop through pages.
	 */
	$page = 1;
	while( true ) {

		/**
		 * Get page HTML.
		 */
		$page_url = str_replace( '{{page}}', $page, $url_format );
		$response = wp_remote_get( $page_url, [
			'timeout' => 10,
		] );
		if ( ! $response || is_wp_error( $response ) || 200 !== $response[ 'response' ][ 'code' ] ) {
			return false;
		}

		/**
		 * Get packages on page.
		 */
		preg_match_all( $regex, $response[ 'body' ], $matches );
		$matches = array_unique( $matches[1] );
		$matches = array_diff( $matches, [
			'commercial',
			'developers',
			'getting-started',
		] );

		/**
		 * Add packages up to `$number`
		 */
		while( $package = array_shift( $matches ) ) {
			$packages[] = $package;
			if ( count( $packages ) == $number ) {
				break 2;
			}
		}
		$page++;
	}

	/**
	 * Done.
	 */
	return $packages;
}
