<?php

define( 'MP_UPDATE_INTERVAL', 6 * HOUR_IN_SECONDS );
define( 'MP_UPDATE_PACKAGE_COUNT', 10 );
define( 'MP_UPDATE_THEME_TAGS', 'rtl-language-support' );
define( 'MP_UPDATE_TRANSCIENT_NAME', 'mp_update' );

/**
 * Determines whether MP_DEV is enabled.
 *
 * @since 1.0.0
 *
 * @return bool True if MP_DEV is enabled, false otherwise.
 */
function mp_dev() {
	return defined( 'MP_DEV' ) && MP_DEV;
}
