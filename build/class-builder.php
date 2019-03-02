<?php
/**
 * Bootswatch build class.
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
		$this->task( [ $this, 'pot' ], 'Creating Languages File' );
		$this->task( [ $this, 'package' ], 'Packaging' );
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
	 * Build zip file.
	 */
	private function package() {

		$dir = 'build/releases/';

		/**
		 * Prepare file name.
		 */
		$filename = $dir . $this->plugin_slug . '.zip';

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
		 * Create package.
		 */
		$zip = new ZipArchive();
		if ( $zip->open( $filename, ZipArchive::CREATE ) !== true ) {
			$this->log( 'cannot open <$filename>' );
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
		$this->log( 'Package created.' );
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
			if ( ! preg_match( "/$pattern/", $path ) ) {
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
	 * Generate pot file.
	 */
	protected function pot() {

		$this->log();

		if ( ! $this->shell_command_exists( 'xgettext' ) ) {
			$this->log_error( '`xgettext` command does not exist.' );
			exit;
		}

		$command = str_replace(
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
		echo shell_exec( 'mkdir -p lang' ); die();
		shell_exec( $command );
		$this->log( 'Language file created successfully.' );
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
}
