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
	 * Plugin timestamp.
	 *
	 * @var string
	 */
	private $plugin_timestamp;

	/**
	 * Last error seen on the log.
	 *
	 * @var Boolean|String
	 */
	private $last_error = false;

	/**
	 * Releases directory.
	 *
	 * @var string
	 */
	private $releases_dir = __DIR__ . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR;

	/**
	 * Releases temproray/cache directory.
	 *
	 * @var string
	 */
	private $releases_tmp_dir = __DIR__ . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;

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

		if ( empty( $argv[3] ) ) {
			$this->log_error( "Missing parameters.\n- Usage: php -f build/build.php [name] [version] [timestamp]" );
		}

		shell_exec( "mkdir -p {$this->releases_dir}" );
		shell_exec( "mkdir -p {$this->releases_tmp_dir}" );

		$this->timer = microtime( true );
		$this->plugin_slug = $argv[1];
		$this->plugin_version = $argv[2];
		$this->plugin_timestamp =  $argv[3];
		$this->task( [ $this, 'pot' ], 'Create pot file' );
		$this->task( [ $this, 'composer_install' ], 'Install dependencies' );
		$this->task( [ $this, 'package_plugin' ], 'Package plugin' );
		$this->task( [ $this, 'package_distribution' ], 'Package distribution' );
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
	private function package_plugin() {

		/**
		 * Prepare file name.
		 */
		$filename = $this->releases_dir . $this->plugin_slug . '-plugin-' . $this->plugin_version . '.zip';

		/**
		 * Prepare a list of files.
		 */
		$files = array_diff(
			$this->find( '.' ),
			$this->find( '.git' ),
			$this->find( 'build' ),
			$this->find( 'vendor' ),
			[
				'.git/',
				'composer.lock',
				'build/',
				'vendor/',
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

		$this->remove_zip_extra( $filename, $this->plugin_timestamp );

		$this->log( '[Info] File size: ' . $this->filesize_formatted( $filename ) );
		$this->log( '[Info] Hashes: ' . $this->hashes( $filename ) );
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
		// $this->log();
		$this->log( $title, true, true );
		// $this->log();
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
		$this->log_title( $title );
		$timer = microtime( true );
		call_user_func( $callback );
		$duration = microtime( true ) - $timer;
		$this->log( sprintf( '...completed in %.2fs', $duration ) );
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
		$this->do_preg_replace ( [
			'vendor/autoload.php' => [
				'/Composer([a-z]+)[0-9a-f]{32}/i' => 'Composer$1' . $prefix,
			],
			'vendor/composer/autoload_real.php' => [
				'/Composer([a-z]+)[0-9a-f]{32}/i' => 'Composer$1' . $prefix,
			],
			'vendor/composer/autoload_static.php' => [
				'/Composer([a-z]+)[0-9a-f]{32}/i' => 'Composer$1' . $prefix,
			],
		] );
	}

	/**
	 * Generate pot file.
	 */
	protected function pot() {

		$pot_filename = 'lang/' . $this->plugin_slug . '.pot';

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
				-o ' . $pot_filename . '
		'
		);

		/**
		 * Run command and restaure old file if nothing changes except the creation date.
		 */
		shell_exec( $pot_command );

		$this->do_preg_replace ( [
			$pot_filename => [
				sprintf( "/\"%s:[^\n]+\n/", 'Language' ) => '',
				sprintf( "/\"%s:[^\n]+\n/", 'Language-Team' ) => '',
				sprintf( "/\"%s:[^\n]+\n/", 'Last-Translator') => '',
				sprintf( "/\"%s:[^\n]+\n/", 'Plural-Forms' ) => '',
				'/"(PO|POT)-([a-z]+)-Date: .*/i' => '"$1-$2-Date: YEAR-MO-DA HO:MI+ZONE\n"',
			],
		] );


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

		$mp_dir = sprintf( '%1$smomtazpress-%2$s', $this->releases_tmp_dir, $this->plugin_version ) . DIRECTORY_SEPARATOR;
		$mp_file = sprintf( '%1$smomtazpress-distro-%2$s.zip', $this->releases_dir, $this->plugin_version );

		$wp_dir = sprintf( '%1$swordpress-%2$s', $this->releases_tmp_dir, $this->plugin_version ) . DIRECTORY_SEPARATOR;
		$wp_file = sprintf( '%1$swordpress-%2$s.tar.gz', $this->releases_tmp_dir, $this->plugin_version );

		$wp_url = sprintf( 'https://wordpress.org/wordpress-%s.tar.gz', $this->plugin_version );

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
			--exclude='inc/developer/'                                      \
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

		$this->remove_zip_extra( $mp_file, $this->plugin_timestamp );

		$this->log( '[Info] File size: ' . $this->filesize_formatted( $mp_file ) );
		$this->log( '[Info] Hashes: ' . $this->hashes( $mp_file ) );
	}

	/**
	 * Apply regex string replacements.
	 */
	private function do_preg_replace( $preg_replacements ) {
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
	}

	/**
	 * Get formatted filesize.
	 *
	 * @link https://stackoverflow.com/a/11860664
	 */
	private function filesize_formatted( $path ) {
		$size = filesize( $path );
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
		$power = $size > 0 ? floor( log( $size, 1024 ) ) : 0;
		return number_format( $size / pow( 1024, $power ), 2, '.', ',' ) . ' ' . $units[ $power ];
	}

	/**
	 * Get hashes.
	 *
	 */
	private function hashes( $path ) {
		return 'MD5: ' . md5_file( $path );
	}

	/**
	 * Remove extra data from zip file.
	 */
	private function remove_zip_extra( $file, $timestamp ) {

		$random = substr( str_shuffle( MD5( microtime() ) ), 0, 10 );
		$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . basename( $file) . DIRECTORY_SEPARATOR;
		$tmp_random = $tmp . DIRECTORY_SEPARATOR . $random . DIRECTORY_SEPARATOR;
		$cwd = getcwd();

		/**
		 * Create $tmp.
		 */
		shell_exec( "mkdir -p $tmp" );

		/**
		 * Extract file to the random temporary directory.
		 */
		shell_exec( "unzip $file -d $tmp_random" );

		/**
		 * cd into newly created random temporary directory.
		 */
		chdir( $tmp_random );

		/**
		 * Set files timestamps.
		 */
		echo shell_exec( '
			find -print | while read filename; do
				touch -t ' . $timestamp . ' "$filename"
			done
		' );

		/**
		 * Compress.
		 */
		shell_exec( "zip -X -r ../$random.zip ." );

		/**
		 * Overwrite original file.
		 */
		shell_exec( "mv -f ../$random.zip $file" );

		/**
		 * cd back.
		 */
		chdir( $cwd );

		/**
		 * Cleanup.
		 */
		shell_exec( "rm -fr $tmp_random" );
	}
}
