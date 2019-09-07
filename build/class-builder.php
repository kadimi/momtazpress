<?php
/**
 * Builded class.
 *
 * @package Starter
 */

namespace Kadimi;

use \ZipArchive;

/**
 * BootswatchBuild
 */
class Builder {

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $plugin_version;

	/**
	 * Last error seen on the log.
	 *
	 * @var Boolean|String
	 */
	private $last_error = false;

	/**
	 * Timer.
	 *
	 * @var float
	 */
	private $timer;

	/**
	 * Constructor.
	 *
	 * Actions:
	 * - Verifies arguments.
	 * - Starts timer.
	 * - Fires all tasks.
	 */
	public function __construct() {

		global $argv;

		if ( empty( $argv[1] ) ) {
			$this->log_error( 'Missing plugin slug and version, example usage: `php -f build/build.php acme-plugin 1.0.0`' );
		}

		if ( empty( $argv[2] ) ) {
			$this->log_error( 'Missing plugin version, example usage: `php -f build/build.php acme-plugin 1.0.0`' );
		}

		$this->timer = microtime( true );
		$this->plugin_slug = $argv[1];
		$this->plugin_version = $argv[2];
		$this->task( [ $this, 'pot' ], 'Creating languages file' );
		$this->task( [ $this, 'composer_install' ], 'Installing dependencies' );
		$this->task( [ $this, 'package' ], 'Packaging' );
		$this->task( [ $this, 'package_distribution' ], 'Packaging distribution' );
	}

	/**
	 * Destructor.
	 *
	 * Actions:
	 * - Shows duration.
	 */
	public function __destruct() {
		$duration = microtime( true ) - $this->timer;
		if ( ! $this->last_error ) {
			$this->log_title( sprintf( 'Build completed in %.2fs', $duration ) );
		}
	}

	/**
	 * Build plugin zip file.
	 */
	private function package() {

		$dir = 'build/releases/';

		/**
		 * Prepare file name.
		 */
		// $filename = $dir . $this->plugin_slug . '.zip';
		$filename = $dir . $this->plugin_slug . '-plugin-' . $this->plugin_version . '.zip';

		/**
		 * Create directory `releases` if it doesn't exist.
		 */
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		/**
		 * Delete existing release with same file name.
		 */
		if ( file_exists( $filename ) ) {
			// @codingStandardsIgnoreStart
			unlink( $filename );
			// @codingStandardsIgnoreEnd
		}

		/**
		 * Prepare a list of files.
		 */
		$files = array_diff(
			$this->find( '.' ),
			$this->find( '.git' ),
			$this->find( 'build' ),
			[
				'.git/',
				'build/',
			]
		);

		/**
		 * Create plugin package.
		 */
		shell_exec( "rm -fr $filename" );
		$zip = new ZipArchive();
		if ( $zip->open( $filename, ZipArchive::CREATE ) !== true ) {
			$this->log_error( 'cannot open $filename.' );
			exit;
		}
		foreach ( $files as $file ) {
			if ( is_dir( $file ) ) {
				$zip->addEmptyDir( $file );
			} else {
				$zip->addFile( $file );
			}
		}
		$zip->close();

		$this->log();
		$this->log( 'Plugin package created.' );
	}

	/**
	 * Works like shell find command.
	 *
	 * @param  Sting $pattern The pattern.
	 * @return Array          A list of files paths.
	 */
	protected function find( $pattern ) {

		$elements = [];

		/**
		 * All paths
		 */
		$paths = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $pattern ), \RecursiveIteratorIterator::SELF_FIRST );

		foreach ( $paths as $path => $unused ) {
			/**
			 * Skip non-matching.
			 */
			if ( ! preg_match( "#$pattern#", $path ) ) {
				continue;
			}
			/**
			 * Skip `.` and `..`.
			 */
			if ( preg_match( '/\/\.{1,2}$/', $path ) ) {
				continue;
			}
			/**
			 * Remove './';
			 */
			$path = preg_replace( '#^\./#', '', $path );

			/**
			 * Add `/` to directories.
			 */
			if ( is_dir( $path ) ) {
				$path .= '/';
			}

			$elements[] = $path;
		}
		sort( $elements );
		return $elements;
	}

	/**
	 * Simple logger function.
	 *
	 * @param  String  $message         The message.
	 * @param  boolean $append_new_line True to append a new line.
	 * @param  boolean $is_title        True to use special markup.
	 */
	protected function log( $message = '', $append_new_line = true, $is_title = false ) {
		if ( $is_title ) {
			$message = "\033[32m\033[1m$message\033[0m";
		}
		echo $message; // XSS OK.
		if ( $append_new_line ) {
			echo "\n";
		}
	}

	/**
	 * Helper function to show title in log.
	 *
	 * @param  String $title  The log title.
	 */
	protected function log_title( $title ) {
		$this->log();
		$this->log( $title, true, true );
	}

	/**
	 * Helper function to show error in log.
	 *
	 * @param  String $message  The message.
	 */
	protected function log_error( $message ) {

		$this->last_error = $message;
		$this->log( "\033[31m\033[1mError: $message\033[0m", true, true );
		exit();
	}

	/**
	 * Runs a task.
	 *
	 * @param  callable $callback  A callback function.
	 * @param  String   $title     A title.
	 */
	protected function task( callable $callback, $title ) {
		$this->log_title( $title . ' started.' );
		$timer = microtime( true );
		call_user_func( $callback );
		$duration = microtime( true ) - $timer;
		$this->log_title( sprintf( '%s completed in %.2fs', $title, $duration ) );
	}


	/**
	 * Runs `composer install`.
	 */
	protected function composer_install() {
		/**
		 * Run `composer install`.
		 */
		shell_exec( 'rm -fr vendor composer.lock && composer install' );

		/**
		 * Rename composer classes.
		 */
		$prefix = md5( sprintf( '%s:%s', $this->plugin_slug, $this->plugin_version ) );
		$preg_replacements =  [
			'vendor/autoload.php' => [
				'/Composer([a-z]+)[0-9a-f]{32}/i' => 'Composer$1' . $prefix,
			],
			'vendor/composer/autoload_real.php' => [
				'/Composer([a-z]+)[0-9a-f]{32}/i' => 'Composer$1' . $prefix,
			],
			'vendor/composer/autoload_static.php' => [
				'/Composer([a-z]+)[0-9a-f]{32}/i' => 'Composer$1' . $prefix,
			],
		];

		foreach ( $preg_replacements as $file => $replacements ) {
			$count    = 0;
			$contents = file_get_contents( $file );
			foreach ( $replacements as $regex => $replacement ) {
				$contents = preg_replace( $regex, $replacement, $contents, -1, $sub_count );
				$count += $sub_count;
			}
			file_put_contents( $file, $contents );
			$this->log( sprintf( '%d replacements made in %s.'
				, $count
				, $file
			) );
		}

		$this->log( 'Dependencies installed successfully.' );
	}

	/**
	 * Generate pot file.
	 */
	protected function pot() {

		$pot_filename = 'lang/' . $this->plugin_slug . '.pot';

		$this->log();

		if ( ! $this->shell_command_exists( 'xgettext' ) ) {
			$this->log_error( '`xgettext` command does not exist.' );
			exit;
		}

		/**
		 * Prepare `lang`directory
		 */
		shell_exec( 'mkdir -p lang' );

		/**
		 * Prepare xgettext command.
		 */
		$pot_command = str_replace(
			"\n",
			'',
			'
			find -name "*.php"
				-not -path "./build/*"
				-not -path "./tests/*"
				-not -path "./vendor/*"
			|
			xargs xgettext
				--language=PHP
				--package-name=' . $this->plugin_slug . '
				--package-version=' . $this->plugin_version . '
				--copyright-holder="Nabil Kadimi"
				--msgid-bugs-address="https://github.com/kadimi/starter/issues/new"
				--from-code=UTF-8
				--keyword="__"
				--keyword="__ngettext:1,2"
				--keyword="__ngettext_noop:1,2"
				--keyword="_c,_nc:4c,1,2"
				--keyword="_e"
				--keyword="_ex:1,2c"
				--keyword="_n:1,2"
				--keyword="_n_noop:1,2"
				--keyword="_nx:4c,1,2"
				--keyword="_nx_noop:4c,1,2"
				--keyword="_x:1,2c"
				--keyword="esc_attr__"
				--keyword="esc_attr_e"
				--keyword="esc_attr_x:1,2c"
				--keyword="esc_html__"
				--keyword="esc_html_e"
				--keyword="esc_html_x:1,2c"
				--sort-by-file
				-o lang/' . $this->plugin_slug . '.pot
		'
		);

		/**
		 * Run command and restaure old file if nothing changes except the creation date.
		 */
		$old = file_exists( $pot_filename ) ? file_get_contents( $pot_filename ) : '';
		shell_exec( $pot_command );
		$new = file_get_contents( $pot_filename );
		$modified = array_diff( explode( "\n", $old ), explode( "\n", $new ) );
		if ( 1 === count( $modified ) ) {
			if ( preg_match( '/^"POT-Creation-Date/', array_values( $modified )[0] ) ) {
				file_put_contents( $pot_filename, $old );
			}
		}

		$this->log( 'Language file handled successfully.' );
	}

	/**
	 * Check if a shell command exists.
	 *
	 * @param  String $command  The command.
	 * @return Boolean           True if the command exist or false oterwise.
	 */
	protected function shell_command_exists( $command ) {
		$output = shell_exec( sprintf( 'which %s', escapeshellarg( $command ) ) );
		return ! empty( $output );
	}

	/**
	 * Build distribution zip file.
	 */
	protected function package_distribution() {

		$this->log();

		$releases_dir = __DIR__ . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR;
		$tmp_dir = $releases_dir . 'tmp' . DIRECTORY_SEPARATOR;

		$mp_dir = sprintf( '%1$smomtazpress-%2$s', $tmp_dir, $this->plugin_version ) . DIRECTORY_SEPARATOR;
		$mp_file = sprintf( '%1$smomtazpress-distro-%2$s.zip', $releases_dir, $this->plugin_version );

		$wp_dir = sprintf( '%1$swordpress-%2$s', $tmp_dir, $this->plugin_version ) . DIRECTORY_SEPARATOR;
		$wp_file = sprintf( '%1$swordpress-%2$s.tar.gz', $tmp_dir, $this->plugin_version );
		$wp_url = sprintf( 'https://wordpress.org/wordpress-%s.tar.gz', $this->plugin_version );

		/**
		 * Create ./releases and ./releases/tmp folders.
		 */
		shell_exec( "mkdir -p $releases_dir" );
		shell_exec( "mkdir -p $tmp_dir" );

		/**
		 * Download and uncompress WordPress.
		 */
		if ( ! file_exists( $wp_file ) ) {
			file_put_contents( $wp_file, file_get_contents( $wp_url ) );
		}
		shell_exec( "rm -fr $wp_dir && mkdir $wp_dir && tar -xvzf $wp_file -C $wp_dir" );

		/**
		 * Copy MomtazPress files into wp-includes excluding unnecessary files.
		 */
		shell_exec( "rsync -av . {$wp_dir}wordpress/wp-includes/MomtazPress \
			--exclude=\".*\"                                                \
			--exclude='codesniffer.ruleset.xml'                             \
			--exclude='LICENSE'                                             \
			--exclude='README.md'                                           \
			--exclude='wp'                                                  \
			--exclude='build/'                                              \
		" );

		/**
		 * Inject MomtazPress code.
		 */
		$before = '// Load active plugins.';
		file_put_contents( "{$wp_dir}wordpress/wp-settings.php", str_replace( $before, ''
			. "// Load MomtazPress.\n"
			. "\$wp_local_package = 'ar';\n"
			. "include WPINC . '/' . 'MomtazPress/class-plugin.php';\n\n"
			. $before,
			file_get_contents( "{$wp_dir}wordpress/wp-settings.php" )
		) );

		/**
		 * Rename wordpress folders folders
		 */
		shell_exec( "mv -f $wp_dir $mp_dir" );
		shell_exec( "mv -f {$mp_dir}wordpress {$mp_dir}momtazpress" );

		/**
		 * Change working directory to `$mp_dir` to get a proper structure on the zip file.
		 */
		chdir( $mp_dir );

		/**
		 * Prepare a list of files.
		 */
		$files = $this->find( '.' );

		/**
		 * Create distribution package.
		 */
		shell_exec( "rm -fr $mp_file" );
		$zip = new ZipArchive();
		if ( $zip->open( $mp_file, ZipArchive::CREATE ) !== true ) {
			$this->log_error( "cannot open $mp_file." );
			exit;
		}
		foreach ( $files as $file ) {
			if ( is_dir( $file ) ) {
				$zip->addEmptyDir( $file );
			} else {
				$zip->addFile( $file );
			}
		}
		$zip->close();

		/**
		 * Restore working directory.
		 */
		chdir( __DIR__ );

		/**
		 * Cleanup
		 */
		shell_exec( "rm -fr $mp_dir" );

		$this->log( 'Disribution package created.' );
	}
}
