<?php

/**
 * Get the WP_Filesystem_Direct instance.
 *
 * @return WP_Filesystem_Direct The instance.
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

function mp_update_pomo( $po_file, $po_url, $use_cache ) {

	$timer = microtime( true );

	/**
	 * Prepare filesystem.
	 */
	$filesystem = mp_get_filesystem();

	/**
	 * Temporary directories.
	 *
	 * @example /tmp/momtazpress-pomo/plugins
	 * @example /tmp/momtazpress-pomo/themes
	 */
	$tmp_dir = __DIR__
		. DIRECTORY_SEPARATOR
		. 'cache'
		. DIRECTORY_SEPARATOR
		. preg_replace( '/.*\//', '', dirname( $po_file ) )
		. DIRECTORY_SEPARATOR;
	shell_exec ( "mkdir -p $tmp_dir" );

	/**
	 * Downloaded .po file.
	 *
	 * @example /tmp/momtazpress-pomo/plugins/akismet-xx_XX.po
	 */
	$dl_file = $tmp_dir . basename( $po_file );
	if ( ! $use_cache || ! file_exists( $dl_file ) ) {
		$response = wp_remote_get( $po_url, [ 'timeout' => 60 ] );
		if ( ! $response || is_wp_error( $response ) || 200 !== $response['response']['code'] ) {
			return false;
		}
		$filesystem->put_contents( $dl_file, $response['body'], 0644 );
	}

	/**
	 * Maybe merge with existing file.
	 */
	if ( file_exists( $po_file ) ) {
		$merge_cmd = sprintf( 'msgmerge --no-wrap -U --backup=none %1$s %2$s', $po_file, $dl_file );
		shell_exec( $merge_cmd );
	} else {
		$filesystem->copy( $dl_file, $po_file, 0644 );
	}

	/**
	 * Organize po file to prevent useless code changess.
	 */
	mp_organize_po( $po_file, $filesystem );

	/**
	 * Re-generate mo file.
	 */
	$mo_cmd = sprintf( 'msgfmt -o %s %s', preg_replace( '/po$/', 'mo', $po_file ), $po_file );
	shell_exec( $mo_cmd );

	printf( '%1$s updated in %2$.2fs' . "\n", basename( $po_file ), microtime( true ) - $timer );
}

function mp_update_core_pomo( $use_cache ) {

	$core = [
		'/'              => '',
		'/admin'         => 'admin',
		'/admin/network' => 'admin-network',
		'/cc'            => 'continents-cities',
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
		$po_url  = 'https://translate.wordpress.org/projects/wp/dev' . $path . '/ar/default/export-translations?format=po';
		mp_update_pomo( $po_file, $po_url, $use_cache );
	}
}

function mp_update_package_pomo( $slug, $type, $use_cache ) {

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
	$po_url  = "https://translate.wordpress.org/projects/wp-{$type}s/" . $slug . ( 'plugin' === $type ? '/stable' : '' ) . '/ar/default/export-translations?format=po';
	mp_update_pomo( $po_file, $po_url, $use_cache );
}

/**
 * Get most popular plugins and themes.
 */
function mp_get_popular_packages( $type, $number = 5 ) {

	$filesystem = mp_get_filesystem();
	$packages = [];
	$update_db = false;

	$cache_dir = __DIR__
		. DIRECTORY_SEPARATOR
		. 'cache'
		. DIRECTORY_SEPARATOR
		. 'api'
		. DIRECTORY_SEPARATOR;
	shell_exec ( "mkdir -p $cache_dir" );

	/**
	 * URL format.
	 */
	$url_format = 'https://api.wordpress.org/' . $type . 's/info/1.1/'
		. '?action=query_' . $type . 's'
		. '&request[fields][description]=0'
		. '&request[fields][downloaded]=1'
		. '&request[fields][homepage]=0'
		. '&request[fields][last_updated]=1'
		. '&request[fields][rating]=0'
		. '&request[fields][screenshot_url]=0'
		. '&request[fields][preview_url]=0'
		. '&request[browse]=popular'
		. '&request[per_page]=250'
		. '&request[page]={page}';
	if ( 'theme' === $type ) {
		foreach ( explode( '|', MP_UPDATE_THEME_TAGS ) as $tag ) {
			$url .= '&request[tag]=' . $tag;
		}
	}

	/**
	 * Download all pages from API
	 */
	$page = 1;
	do {
		/**
		 * Prepare URL.
		 */
		$url = str_replace( '{page}', $page, $url_format );

		/**
		 * Get from cache if found, otherwise send request and cache it.
		 */
		$cache_file = $cache_dir
			. DIRECTORY_SEPARATOR
			. str_pad( $page, 3, '0', STR_PAD_LEFT ) . '-' . md5( $url ) . '.json';

		if ( ! file_exists( $cache_file ) ) {

			/**
			 * Since we are not using cache, we need to update the database.
			 */
			$update_db = true;

			$response = wp_remote_get(
				$url,
				[
					'timeout' => 30,
				]
			);

			/**
			 * Exit if request fails
			 */
			if ( ! $response || is_wp_error( $response ) || 200 !== $response['response']['code'] ) {
				return [];
			}
			/**
			 * Breaks if no more pages.
			 */
			if( ! json_decode( $response[ 'body' ] )->info->results ) {
				break;
			}

			file_put_contents( $cache_file, $response['body'] );
		}
		$page++;
	} while ( true );

	/**
	 * Store number of pages.
	 */
	$pages = $page - 1;

	/**
	 * Get packages sorted by download count.
	 */
	// $packages_from_api = (array) json_decode( $dl_content )->{ $type . 's' };
	// usort(
	// 	$packages_from_api,
	// 	function( $a, $b ) {
	// 		return $a->downloaded < $b->downloaded;
	// 	}
	// );

	/**
	 * Make sure stats.db exists.
	 */
	$db_file = __DIR__ . DIRECTORY_SEPARATOR . 'stats.db';
	if ( ! $filesystem->exists( $db_file ) ) {
		$filesystem->touch( $db_file );
		$db = new SQLite3( $db_file );
		$db->exec( "CREATE TABLE packages(
			id INTEGER PRIMARY KEY,
			name TEXT,
			slug TEXT,
			type TEXT,
			downloaded INT,
			percent_translated INT
		)" );
	} else {
		$db = new SQLite3( $db_file );
	}

	/**
	 * Maybe update database.
	 */
	if ( $update_db ) {
		$db->exec( "DELETE FROM packages WHERE type = '$type'" );
		$page = 1;
		$values_parts = [];
		do {

			/**
			 * Get content from file.
			 */
			$url = str_replace( '{page}', $page, $url_format );
			$cache_file = $cache_dir
				. DIRECTORY_SEPARATOR
				. str_pad( $page, 3, '0', STR_PAD_LEFT ) . '-' . md5( $url ) . '.json';
			if ( ! file_exists( $cache_file ) ) {
				continue;
			}

			/**
			 * SQL work.
			 */
			$packages_from_api = ( array ) json_decode( file_get_contents( $cache_file ) )->{ $type . 's' };
			$packages_from_api = array_slice( $packages_from_api, 0, $number );
			foreach ( $packages_from_api as $package ) {				
				$values_parts[] = str_replace( [
					'{slug}',
					'{name}',
					'{type}',
					'{downloaded}',
					'{percent_translated}',
				], [
					$package->slug,
					SQLite3::escapeString( $package->name ),
					$type,
					$package->downloaded,
					0,
				], '( "{slug}", "{name}", "{type}", {downloaded}, {percent_translated} )' );
			}
		} while ( $page++ < $pages  );

		$db->exec( 'INSERT INTO packages ( slug, name, type, downloaded, percent_translated ) VALUES ' . implode( ', ', $values_parts ) );
	}

	/**
	 * Grab first `$number` packages.
	 */
	$results = $db->query( "SELECT * FROM 'packages' ORDER BY downloaded DESC LIMIT $number" );
	while ( $row = $results->fetchArray() ) {
		$packages[] = $row[ 'slug' ];
	}

	/**
	 * Done.
	 */
	return $packages;
}

/**
 * Organize po file.
 *
 * - Sort header lines
 * - Make file paths one per line and sort them.
 */
function mp_organize_po( $file, $filesystem ) {

	/**
	 * Sort header.
	 */
	$header_sorting_cmd = sprintf( '{ head -n 4 %1$s; head -n 12 %1$s | tail -n +5 | sort; tail -n +13 %1$s; }', $file );
	$file_contents      = trim( shell_exec( $header_sorting_cmd ) ) . "\n";

	/**
	 * Sort file paths.
	 */
	preg_match_all( '/(?<unsorted>(?<paths>#: .+?)(?<cmd>[\n]msg(?:[a-z]+) "))/s', $file_contents, $chunks, PREG_SET_ORDER );

	$replacements = [];
	foreach ( $chunks as $chunk ) {

		/**
		 * Get paths (removes '#: ').
		 */
		preg_match_all( '/\b[\S]+\b/', $chunk['paths'], $paths );
		$paths = $paths[0];

		/**
		 * Restore '#: '.
		 */
		array_walk(
			$paths,
			function( &$path ) {
				$path = "#: $path";
			}
		);

		/**
		 * Pad line numbers to 6 digits.
		 */
		array_walk(
			$paths,
			function( &$path ) {
				preg_match_all( '/:(?<line>\d+)/', $path, $lines, PREG_SET_ORDER );
				foreach ( $lines as $l ) {
					$l_orig   = ':' . $l['line'];
					$l_padded = ':' . str_pad( $l['line'], 6, '0', STR_PAD_LEFT );
					$path     = str_replace( $l_orig, $l_padded, $path );
				}
			}
		);

		/**
		 * Sort paths.
		 */
		sort( $paths );

		/**
		 * Unpad line numbers.
		 */
		array_walk(
			$paths,
			function( &$path ) {
				$path = trim( preg_replace( '/:0+/', ':', $path ) );
			}
		);

		$chunk['sorted']                           = implode( "\n", $paths ) . $chunk['cmd'];
		$replacements[ "\n" . $chunk['unsorted'] ] = "\n" . $chunk['sorted'];
	}
	$file_contents = str_replace( array_keys( $replacements ), array_values( $replacements ), $file_contents );

	/**
	 * Save file.
	 */
	$filesystem->put_contents( $file, $file_contents, 0644 );
}
