<?php

/**
 * Check for translations in MomtazPress languages directory first.
 */
add_filter( 'load_textdomain_mofile', function( $mofile, $domain ) {
	$momtaz_mofile = str_replace( WP_LANG_DIR, MP_LANG_DIR, $mofile );
	if ( file_exists( $momtaz_mofile) ) {
		$mofile = $momtaz_mofile;
	}
	return $mofile;
}, 10, 2 );
