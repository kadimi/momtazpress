<?php

add_action(
	'mp_init',
	function() {

		/**
		 * On frontend only so we don't prevent plugin activation.
		 */
		if ( is_admin() ) {
			return;
		}

		$cache  = ! empty( $_GET['mp_cache'] ) ? true : MP_CACHE;
		$count  = ! empty( $_GET['mp_number'] ) ? $_GET['mp_number'] : MP_UPDATE_PACKAGE_COUNT;
		$filter = ! empty( $_GET['mp_filter'] ) ? $_GET['mp_filter'] : MP_UPDATE_PACKAGE_FILTER;
		$filter = array_filter( explode( '|', $filter ) );
		$force  = ! empty( $_GET['mp_force'] );

		/**
		 * Check if we need an update.
		 */
		if ( ! $force && get_transient( MP_UPDATE_TRANSCIENT_NAME ) ) {
			return;
		}

		/**
		 * Update core.
		 */
		if ( ! $filter || in_array( 'core', $filter ) ) {
			mp_update_core_pomo( $cache );
		}

		/**
		 * Update bbPress
		 */
		if ( ! $filter || in_array( 'bbpress', $filter ) ) {
			$po_file = MP_LANG_DIR . '/plugins/bbpress-ar.po';
			$po_url  = 'https://translate.wordpress.org/projects/wp-plugins/bbpress/stable/ar/default/export-translations/?format=po';
			mp_update_pomo( $po_file, $po_url, $cache );
		}

		/**
		 * Update BuddyPress
		 */
		if ( ! $filter || in_array( 'buddypress', $filter ) ) {
			$po_file = MP_LANG_DIR . '/plugins/buddypress-ar.po';
			$po_url  = 'https://translate.wordpress.org/projects/wp-plugins/buddypress/stable/ar/default/export-translations/?format=po';
			mp_update_pomo( $po_file, $po_url, $cache );
		}

		/**
		 * Update plugins.
		 */
		foreach ( mp_get_popular_packages( 'plugin', $count ) as $slug ) {
			if ( ! $filter || in_array( $slug, $filter ) ) {
				mp_update_package_pomo( $slug, 'plugin', $cache );
			}
		}

		/**
		 * Update themes.
		 */
		foreach ( mp_get_popular_packages( 'theme', $count ) as $slug ) {
			if ( ! $filter || in_array( $slug, $filter ) ) {
				mp_update_package_pomo( $slug, 'theme', $cache );
			}
		}

		/**
		 * Set transcient.
		 */
		set_transient( MP_UPDATE_TRANSCIENT_NAME, 1, MP_UPDATE_INTERVAL );

		die( "Update complete\n" );
	}
);
