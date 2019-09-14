<?php

/**
 * Re-inject MomtazPress code into core.
 */
add_action(
	'_core_updated_successfully',
	function() {
		$before = '// Load active plugins.';
		$file   = ABSPATH . '/wp-settings.php';
		file_put_contents(
			$file,
			str_replace(
				$before,
				''
				. "// Start MomtazPress Injected Code\n"
				. "\$wp_local_package = 'ar';\n"
				. "// End MomtazPress Injected Code\n\n"
				. $before,
				file_get_contents( $file )
			)
		);
	}
);
