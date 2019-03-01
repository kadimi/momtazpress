<?php

add_action( 'mp_init', function() {

	/**
	 * Check if we need an update.
	 */
	if ( get_transient( MP_UPDATE_TRANSCIENT_NAME ) ) {
		return;
	}

	/**
	 * Update plugins.
	 */
	foreach ( mp_get_most_popular_packages( 'plugin', MP_UPDATE_PACKAGE_COUNT ) as $plugin_slug ) {
		mp_update_package( $plugin_slug, 'plugin' );			
	}

	/**
	 * Update themes.
	 */
	foreach ( mp_get_most_popular_packages( 'theme', MP_UPDATE_PACKAGE_COUNT ) as $theme_slug ) {
		mp_update_package( $theme_slug, 'theme' );			
	}

	/**
	 * Set transcient. 
	 */
	set_transient( MP_UPDATE_TRANSCIENT_NAME, 1,  MP_UPDATE_INTERVAL );
} );

function mp_update_package( $slug, $type ) {

	/**
	 * Check type.
	 */
	if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) ) {
		return;
	}

	/**
	 * Helper file path for plugins.
	 */
	$pofile_tmp = MP_LANG_DIR . '/plugins/TMP.po';

	$pofile = MP_LANG_DIR . "/{$type}s/$slug-ar.po";
	$po_url = "https://translate.wordpress.org/projects/wp-{$type}s/" . $slug . ( 'plugin' === $type ? '/stable' : '' ) . '/ar/default/export-translations?format=po';

	/**
	 * Download .po file.
	 */
	$response = wp_remote_get( $po_url, [
		'timeout' => 10,
	] );

	/**
	 * Skip file if URL doesn't work.
	 */
	if ( ! $response || is_wp_error( $response ) || 200 !== $response[ 'response' ][ 'code' ] ) {
		return false;
	}

	/**
	 * Prepare filesystem.
	 */
	$filesystem = mp_get_filesystem();
	$filesystem->mkdir( MP_LANG_DIR, 0755 );
	$filesystem->mkdir( MP_LANG_DIR . "/{$type}s", 0755 );

	/**
	 * Handle downloaded file.
	 */
	if ( ! file_exists( $pofile ) ) {
		/**
		 * If file doesn't exist then save downloaded and continue.
		 */
		$filesystem->put_contents( $pofile , $response[ 'body' ], 0644 );
	} else {
		/**
		 * Save original file temporarily.
		 */
		$filesystem->put_contents( $pofile_tmp , $response[ 'body' ], 0644 );

		/**
		 * Update existing file intelligently.
		 */
		$merge_cmd = sprintf( 'msgmerge --no-wrap -U --backup=none %1$s %2$s'
			, $pofile
			, $pofile_tmp
		);
		shell_exec( $merge_cmd );
	}

	/**
	 * Re-generate mo file.
	 */
	$mo_cmd = sprintf( 'msgfmt -o %s %s'
		, preg_replace( '/po$/', 'mo', $pofile )
		, $pofile
	);
	shell_exec( $mo_cmd );

	/**
	 * Delete helper file.
	 */
	$filesystem->delete( $pofile_tmp );
}

/**
 * Get most popular plugins and themes.
 */
function mp_get_most_popular_packages( $type, $number = 30 ) {

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
