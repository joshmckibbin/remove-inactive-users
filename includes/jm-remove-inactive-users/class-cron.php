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
		add_action( 'jm_remove_inactive_users_cron', array( $this, 'run' ) );
		if ( ! wp_next_scheduled( 'jm_remove_inactive_users_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'jm_remove_inactive_users_cron' );
		}
	}

	/**
	 * Run the remove_users method.
	 */
	public function run() {
		$options = get_site_option( 'remove_inactive_users', false );

		if ( $options && isset( $options['auto_remove'] ) && $options['auto_remove'] ) {
			// If auto-remove is enabled, proceed to remove users.
			$this->remove_users();
			return;
		}
	}

	/**
	 * Remove inactive users.
	 */
	private function remove_users() {
		$main = new Main();

		// Loop through the inactive users and delete them.
		foreach ( $main->inactive as $removed_user ) {
			if ( ! wp_delete_user( $removed_user ) ) {
				$error_obj = new \WP_Error(
					'remove-inactive-users',
					// translators: %s is the user display name.
					wp_sprintf( __( 'Error: unable to remove %s' ), get_the_author_meta( 'display_name', $removed_user ) )
				);
				return $error_obj;
			}
		}

		// Reset the inactive users array.
		$main->inactive = array();
	}

	/**
	 * Unschedule the cron event.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'jm_remove_inactive_users_cron' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'jm_remove_inactive_users_cron' );
		}
	}
}
