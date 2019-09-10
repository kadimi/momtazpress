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
	 * Helper file.
	 */
	$tmp_file = MP_LANG_DIR . '/tmp-' . random_int( PHP_INT_MIN, PHP_INT_MAX ) . '.po';

	/**
	 * Save original file temporarily.
	 */
	$filesystem->put_contents( $tmp_file , $response[ 'body' ], 0644 );

	/**
	 * Handle downloaded file.
	 */
	if ( file_exists( $po_file ) ) {

		/**
		 * Update existing file intelligently.
		 */
		$merge_cmd = sprintf( 'msgmerge --no-wrap -U --backup=none %1$s %2$s', $po_file, $tmp_file );
		shell_exec( $merge_cmd );
	}

	/**
	 * Sort header to prevent useless code changes.
	 */
	$header_sorting_cmd = sprintf( '{ head -n 4 %1$s; head -n 12 %1$s | tail -n +5 | sort; tail -n +13 %1$s; }', $tmp_file );
	$po_file_contents = trim( shell_exec( $header_sorting_cmd ) ) . "\n";

	/**
	 * Sort file paths in po file to prevent useless code changes.
	 */
	preg_match_all('/(?<unsorted>(?<paths>#: .+?)(?<cmd>[\n]msg(?:[a-z]+) "))/s', $po_file_contents, $chunks, PREG_SET_ORDER );
	foreach ( $chunks as $chunk ) {
		/**
		 * Get paths (removes '#: ').
		 */
		preg_match_all( '/\b[\S]+\b/', $chunk[ 'paths' ], $paths );
		$paths = $paths[0];

		/**
		 * Restore '#: '.
		 */
		array_walk( $paths, function( &$path ) {
			$path = "#: $path";
		} );

		/**
		 * Pad line numbers to 6 digits.
		 */
		array_walk( $paths, function( &$path ) {
			preg_match_all( '/:(?<line>\d+)/', $path, $lines, PREG_SET_ORDER );
			foreach ( $lines as $l) {
				$l_orig = ':' . $l[ 'line' ];
				$l_padded = ':' . str_pad( $l[ 'line' ], 6, '0', STR_PAD_LEFT );
				$path = str_replace( $l_orig, $l_padded, $path );
			}
		} );

		/**
		 * Sort paths.
		 */
		sort( $paths );

		/**
		 * Unpad line numbers.
		 */
		array_walk( $paths, function( &$path ) {
			$path = preg_replace( '/:0+/', ':', $path );
		} );

		$chunk['sorted'] = implode( "\n", $paths ) . $chunk[ 'cmd' ];
		$po_file_contents = str_replace( $chunk[ 'unsorted' ], $chunk[ 'sorted' ], $po_file_contents );
	}

	/**
	 * Save .po file.
	 */
	$filesystem->put_contents( $po_file , $po_file_contents , 0644 );

	/**
	 * Delete helper file.
	 */
	$filesystem->delete( $tmp_file );

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

	$packages = [];

	/**
	 * Prepare URL.
	 */
	$url = 'https://api.wordpress.org/' . $type . 's/info/1.1/';
	$url .= '?action=query_' . $type . 's';
	$url .= '&request[browse]=popular';
	$url .= '&request[per_page]=' . $number ;
	if ( 'theme' === $type ) {
		foreach ( explode( '|', MP_UPDATE_THEME_TAGS ) as $tag ) {
			$url .= '&request[tag]=' . $tag;
		}
	}
	// $url .= '&request[fields][downloaded]=1';

	/**
	 * Send request.
	 * @var [type]
	 */
	$response = wp_remote_get( $url, [
		'timeout' => 10,
	] );
	if ( ! $response || is_wp_error( $response ) || 200 !== $response[ 'response' ][ 'code' ] ) {
		return false;
	}

	/**
	 * Grab packages slugs.
	 */
	foreach ( json_decode( $response[ 'body' ] )->{ $type . 's' } as $package ) {
		$packages[] = $package->slug;
	}

	/**
	 * Done.
	 */
	return $packages;
}
