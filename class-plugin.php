<?php

/**
 * Plugin Name: MomtazPress
 * Description: MomtazPress.
 * Text Domain: mp
 * Version: 5.2.3
 * Plugin URI: https://www.github.com/kadimi/starter
 * GitHub Plugin URI: https://github.com/kadimi/starter
 * Author: Nabil Kadimi
 * Author URI: https://kadimi.com
 *
 * @package Bayn\MomtazPress
 */

/**
 * Create the plugin class.
 */
require 'class-starter.php';

/**
 * MomtazPress class.
 */
class MomtazPress extends Starter {};

/**
 * Create a shortcut for ease of use.
 */
function mp( $args = [] ) {
	return MomtazPress::get_instance( $args );
}

/**
 * Fire plugin.
 */
mp( [
	'slug' => 'mp',
	'version' => '5.2.3',
] );
