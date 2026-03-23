<?php
/**
 * Cron job handling for removing inactive users.
 *
 * @package remove-inactive-users
 */

namespace JM_Remove_Inactive_Users;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Cron class.
 */
class Cron {

	/**
	 * Hook into WordPress cron.
	 */
	public function __construct() {
		add_action( 'jm_remove_inactive_users_auto_remove', array( $this, 'remove_users' ) );

		$options = get_option( 'remove_inactive_users' );
		if ( $options && isset( $options['auto_remove'] ) && $options['auto_remove'] ) {
			$this->activate();
		} else {
			$this->deactivate();
		}
	}

	/**
	 * Remove inactive users.
	 */
	public function remove_users() {
		// Make sure this is being ran by cron.
		if ( ! wp_doing_cron() ) {
			return;
		}

		// Initialize the main class and set inactive users.
		$main = new Main();
		$main->set_inactive_users();

		// Also set roleless users if that option is enabled.
		$options = get_option( 'remove_inactive_users' );
		if ( ! empty( $options['remove_roleless'] ) ) {
			$main->set_roleless_users();
		}

		if ( empty( $main->inactive ) && empty( $main->roleless ) ) {
			return; // No users to remove.
		}

		// Load the User Admin API.
		require_once ABSPATH . 'wp-admin/includes/user.php';

		// Loop through the inactive users and delete them.
		foreach ( $main->inactive as $user_id ) {
			if ( ! wp_delete_user( $user_id ) ) {
				$error_obj = new \WP_Error(
					'remove-inactive-users',
					// translators: %s is the user display name.
					wp_sprintf( __( 'Error: unable to remove %s', 'remove-inactive-users' ), get_the_author_meta( 'display_name', $user_id ) )
				);
				return $error_obj;
			}
		}

		// Loop through the roleless users and delete them.
		foreach ( $main->roleless as $user_id ) {
			if ( ! wp_delete_user( $user_id ) ) {
				$error_obj = new \WP_Error(
					'remove-inactive-users',
					// translators: %s is the user display name.
					wp_sprintf( __( 'Error: unable to remove %s', 'remove-inactive-users' ), get_the_author_meta( 'display_name', $user_id ) )
				);
				return $error_obj;
			}
		}
	}

	/**
	 * Schedule the cron event.
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( 'jm_remove_inactive_users_auto_remove' ) ) {
			wp_schedule_event( time(), 'daily', 'jm_remove_inactive_users_auto_remove' );
		}
	}

	/**
	 * Unschedule the cron event.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'jm_remove_inactive_users_auto_remove' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'jm_remove_inactive_users_auto_remove' );
		}
	}
}
