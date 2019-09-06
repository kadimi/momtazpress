<?php

add_action( 'mp_init', function() {

	/**
	 * Check if we need an update.
	 */
	if ( get_transient( MP_UPDATE_TRANSCIENT_NAME ) && empty( $_GET[ 'mp_force' ] ) ) {
		return;
	}

	/**
	 * Update core.
	 */
	mp_update_core_pomo();

	/**
	 * Update plugins.
	 */
	foreach ( mp_get_popular_packages( 'plugin', MP_UPDATE_PACKAGE_COUNT ) as $plugin_slug ) {
		mp_update_package_pomo( $plugin_slug, 'plugin' );
	}

	/**
	 * Update bbPress
	 */
	$po_file = MP_LANG_DIR . "/plugins/bbpress-ar.po";
	$po_url = 'https://translate.wordpress.org/projects/wp-plugins/bbpress/stable/ar/default/export-translations/?format=po';
	mp_update_pomo( $po_file, $po_url );

	/**
	 * Update BuddyPress
	 */
	$po_file = MP_LANG_DIR . "/plugins/buddypress-ar.po";
	$po_url = 'https://translate.wordpress.org/projects/wp-plugins/buddypress/stable/ar/default/export-translations/?format=po';

	mp_update_pomo( $po_file, $po_url );

	/**
	 * Update themes.
	 */
	foreach ( mp_get_popular_packages( 'theme', MP_UPDATE_PACKAGE_COUNT ) as $theme_slug ) {
		mp_update_package_pomo( $theme_slug, 'theme' );
	}

	/**
	 * Set transcient.
	 */
	set_transient( MP_UPDATE_TRANSCIENT_NAME, 1,  MP_UPDATE_INTERVAL );
} );
