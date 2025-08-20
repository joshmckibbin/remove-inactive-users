<?php
/**
 * Plugin activation handling
 *
 * Handles the activation of the plugin.
 *
 * @package remove-inactive-users
 */

namespace JMck_Remove_Inactive_Users;

defined( 'ABSPATH' ) || exit;

/**
 * Activator class
 *
 * Handles the activation of the plugin.
 */
class Activator {
	/**
	 * On activation, add wp_last_login usermeta to all users if it isn't there.
	 *
	 * @since 1.0
	 * @return void
	 * @see get_users()
	 * @see update_user_meta()
	 * @see get_user_meta()
	 **/
	public static function activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Get all users.
		$users = get_users();

		// Loop through each user and add user meta.
		foreach ( $users as $user ) {
			// Check if wp_last_login is there and set it to 0 if not.
			$last_login = get_user_meta( $user->ID, 'wp_last_login', true );
			if ( ! $last_login ) {
				update_user_meta( $user->ID, 'wp_last_login', 0 );
			}
		}
	}
}
