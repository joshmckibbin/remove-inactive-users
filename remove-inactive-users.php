<?php
/**
 * Plugin Name: Remove Inactive Users
 * Description: Removes users that have not logged in for a specified number of days.
 * Version: 3.0.0
 * Author: Josh Mckibbin
 * Author URI: https://josh.mckibb.in
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 *
 * @package RemoveInactiveUsers
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'JM_REMOVE_INACTIVE_USERS_VERSION', '3.0.0' );
define( 'JM_REMOVE_INACTIVE_USERS_PATH', plugin_dir_path( __FILE__ ) );
define( 'JM_REMOVE_INACTIVE_USERS_URL', plugin_dir_url( __FILE__ ) );

// Load the class library.
spl_autoload_register(
	function ( $class_name ) {
		$class_path     = str_replace( '_', '-', $class_name );
		$class_path     = str_replace( '\\', DIRECTORY_SEPARATOR, $class_path );
		$class_path     = strtolower( $class_path );
		$class_filename = explode( DIRECTORY_SEPARATOR, $class_path );
		$class_path     = implode( DIRECTORY_SEPARATOR, array_slice( $class_filename, 0, -1 ) );
		$class_filename = 'class-' . end( $class_filename ) . '.php';

		$file = JM_REMOVE_INACTIVE_USERS_PATH . 'includes' . DIRECTORY_SEPARATOR . $class_path . DIRECTORY_SEPARATOR . $class_filename;

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

// Activate the plugin.
register_activation_hook( __FILE__, array( 'JM_Remove_Inactive_Users\Activator', 'activate' ) );

// Load the main plugin class.
new JM_Remove_Inactive_Users\Main();
