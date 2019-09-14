<?php
/**
 * The Starter class.
 *
 * @package Starter
 */

if ( ! class_exists( 'Starter' ) ) :
	/**
	 * The Starter class.
	 */
	class Starter {

		/**
		 * Class instance
		 *
		 * @var object
		 */
		protected static $instances = [];

		/**
		 * Plugin slug.
		 *
		 * @var String
		 */
		public $plugin_slug;

		/**
		 * Plugin version.
		 *
		 * @var String
		 */
		public $plugin_version;

		/**
		 * Plugin directory path.
		 *
		 * @var String
		 */
		public $plugin_dir_path;

		/**
		 * Relative plugin directory path.
		 *
		 * @var String
		 */
		public $plugin_basename;

		/**
		 * Plugin main file.
		 *
		 * @var String
		 */
		public $plugin_file;

		/**
		 * Plugin directory URL.
		 *
		 * @var String
		 */
		public $plugin_dir_url;

		/**
		 * Constructor
		 */
		public function __construct() {
		}

		/**
		 * Cloner
		 */
		public function __clone() {
		}

		/**
		 * Returns a new or the existing instance of this class
		 *
		 * @param  array $args Arguments.
		 * @return Object
		 */
		public static function get_instance( $args = [] ) {
			if ( empty( self::$instances[ get_called_class() ] ) ) {
				self::$instances[ get_called_class() ] = new static();
				self::$instances[ get_called_class() ]->init( $args );
			}
			return self::$instances[ get_called_class() ];
		}

		/**
		 * Initializes plugin
		 *
		 * @param  array $args Arguments.
		 */
		protected function init( $args ) {

			$this->plugin_file     = debug_backtrace()[1]['file'];
			$this->plugin_basename = plugin_basename( $this->plugin_file );
			$this->plugin_dir_path = plugin_dir_path( $this->plugin_file );
			$this->plugin_dir_url  = plugin_dir_url( $this->plugin_file );
			$this->plugin_slug     = ( ! empty( $args['slug'] ) ) ? $args['slug'] : str_replace( '_', '-', self::camel_case_to_snake_case( get_class( $this ) ) );
			$this->plugin_version  = ( ! empty( $args['version'] ) ) ? $args['version'] : '1.0.0';
			$this->autoload();
			$this->activate();
			$this->enqueue_public_assets();
			$this->l10n();
			$this->shortcodes();
		}

		/**
		 * Requires Composer generated autoload file and .php files in the directory `inc`
		 */
		protected function autoload() {
			$autoload_file_path = $this->plugin_dir_path . 'vendor/autoload.php';
			if ( file_exists( $autoload_file_path ) ) {
				require $autoload_file_path;
				$paths = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->plugin_dir_path . 'inc' ), RecursiveIteratorIterator::SELF_FIRST );
				foreach ( $paths as $path => $unused ) {
					$path = str_replace( '\\', '/', $path );
					if ( preg_match( '/\/[\w-]+\.php$/', $path ) ) {
						require $path;
					}
				}
			} else {
				// @codingStandardsIgnoreStart
				wp_die( sprintf( __( 'Plugin <strong>%1$s</strong> not installed yet, run the `<strong><code>composer install</code></strong>` command on a terminal from within the plugin directory and activate the plugin again from the <a href="%2$s">plugins page</a>.', '{{starter}}' ), $this->plugin_slug, admin_url( 'plugins.php' ) ) ); // XSS OK.
				// @codingStandardsIgnoreEnd
			}
		}

		/**
		 * Runs on plugin actication
		 */
		protected function activate() {
			register_activation_hook(
				$this->plugin_file,
				function() {
					set_transient( $this->plugin_slug, 1, self::in( '15 minutes' ) );
				}
			);
		}

		/**
		 * Loads textdomain.
		 *
		 * Important: textdomain must always be hardcoded in l10n/i18n functions (`__()`, `_e`, ...).
		 */
		protected function l10n() {
			add_action(
				'plugins_loaded',
				function() {
					load_plugin_textdomain( $this->plugin_slug, false, dirname( $this->plugin_basename ) . '/lang' );
				}
			);
		}

		/**
		 * Requires a plugin
		 *
		 * @param  String $name    Plugin name.
		 * @param  Array  $options TGMPA compatible options.
		 */
		protected function require_plugin( $name, $options = [] ) {
			add_action(
				'tgmpa_register',
				function() use ( $name, $options ) {
					$options['name']     = $name;
					$options['slug']     = ! empty( $options['slug'] ) ? $options['slug'] : strtolower( preg_replace( '/[^\w\d]+/', '-', $name ) );
					$options['required'] = true;
					tgmpa( [ $options ] );
				}
			);
		}

		/**
		 * Adds plugin shortcodes.
		 */
		protected function shortcodes() {
			add_shortcode(
				$this->plugin_slug,
				function( $atts ) {
					$args   = shortcode_atts(
						array(
							'dummy' => 'dummy',
						),
						$atts
					);
					$output = Kint::dump( $args );
					return $output;
				}
			);
		}

		/**
		 * Enqueue styles as scripts
		 *
		 * @todo Improve documentation.
		 */
		protected function enqueue_public_assets() {
			$this->enqueue_asset( 'public/css/frontend-main.css' );
			$this->enqueue_asset( 'public/js/frontend-main.js' );
			$this->enqueue_asset(
				'public/css/backend-main.css',
				[
					'is_admin' => true,
				]
			);
			$this->enqueue_asset(
				'public/js/backend-main.js',
				[
					'is_admin' => true,
				]
			);
		}

		/**
		 * Enqueues an asset.
		 *
		 * @todo Improve documentation.
		 * @param  String $path The path relative to the plugin directory.
		 * @param  Array  $args Same as what you would provide to wp_enqueue_script or wp_enqueue_style with the addition of is_admin which enqueue the asset on the backend and l10n/object_name for scripts.
		 */
		public function enqueue_asset( $path, $args = [] ) {

			$default_args = [
				'is_admin'  => false,
				'handle'    => $this->plugin_slug . '-' . sanitize_user( basename( $path ), true ),
				'deps'      => null,
				'ver'       => $this->plugin_version,
				'in_footer' => null,
				'media'     => null,
			];

			$args           += $default_args;
			$args['abspath'] = $this->plugin_dir_path . $path;
			$args['src']     = $this->plugin_dir_url . $path;
			$parts           = explode( '.', $path );
			$extension       = end( $parts );

			if ( ! file_exists( $args['abspath'] ) ) {
				$this->watchdog( sprintf( 'File <code>%s</code> does not exist', $path ), 'notice' );
				return;
			}

			if ( ! in_array( $extension, [ 'css', 'js' ], true ) ) {
				$this->watchdog( sprintf( 'File <code>%s</code> cannot be enqueued', $path ), 'notice' );
				return;
			}

			add_action(
				( $args['is_admin'] ? 'admin' : 'wp' ) . '_enqueue_scripts',
				function() use ( $args, $extension ) {
					switch ( $extension ) {
						case 'css':
							wp_enqueue_style( $args['handle'], $args['src'], $args['deps'], $args['ver'], $args['media'] );
							break;
						case 'js':
							wp_enqueue_script( $args['handle'], $args['src'], $args['deps'], $args['ver'], $args['in_footer'] );
							if ( ! empty( $args['l10n'] ) ) {
								wp_localize_script( $args['handle'], $args['object_name'], $args['l10n'] );
							}
							break;
						default:
							break;
					}
				}
			);
		}

		/**
		 * Enqueues an asset.
		 *
		 * @todo Improve documentation.
		 * @param  String $path The path relative to the plugin directory.
		 * @param  Array  $args Same as what you would provide to wp_enqueue_script or wp_enqueue_style with the addition of is_admin which enqueue the asset on the backend.
		 */
		protected function admin_enqueue_asset( $path, $args = [] ) {
			$args['is_admin'] = true;
			return $this->enqueue_asset( $path, $args );
		}

		/**
		 * Converts a string from camelCase to snake_case
		 *
		 * @param  String $str camelCase.
		 * @return String      snake_case.
		 */
		public static function camel_case_to_snake_case( $str ) {
			preg_match_all( '!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $str, $matches );
			foreach ( $matches[0] as &$match ) {
				if ( strtoupper( $match ) === $match ) {
					$match = strtolower( $match );
				} else {
					$match = lcfirst( $match );
				}
			}
			return implode( '_', $matches[0] );
		}

		/**
		 * Returns number of seconds to given time
		 *
		 * @param  String $time Time.
		 * @return int          Seconds to time.
		 */
		public static function in( $time ) {
			return strftime( $time ) - time();
		}

		/**
		 * Logs whatever you want
		 *
		 * @param  String $msg  A message.
		 * @param  String $type A type.
		 * @todo  Write method
		 */
		protected function watchdog( $msg, $type = 'notice' ) {
			if ( in_array( $type, [ 'deprecated', 'notice', 'warning', 'error' ], true ) ) {
				// The method does nothing yet.
				return;
			}
		}
	}
endif;
