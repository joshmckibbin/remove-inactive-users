<?php
/**
 * Handles the last login functionality for users.
 *
 * @package remove-inactive-users
 */

namespace JM_Remove_Inactive_Users;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Last Login class
 */
class Last_Login {

	/**
	 * Initialize the Last_Login class
	 *
	 * @return void
	 * @see register_activation_hook()
	 * @see add_action()
	 * @see add_filter()
	 */
	public function __construct() {

		// Save user meta on new registration.
		add_action( 'user_register', array( $this, 'register_last_login' ), 10, 1 );

		// Update usermeta on login.
		add_action( 'wp_login', array( $this, 'update_last_login' ), 12, 2 );

		// Create new columns for last login and registration date.
		add_filter( 'manage_users_columns', array( $this, 'add_date_columns' ), 12, 3 );
		add_action( 'manage_users_custom_column', array( $this, 'populate_date_columns' ), 12, 3 );
		add_filter( 'manage_users_sortable_columns', array( $this, 'sortable_date_columns' ), 12, 3 );

		// Hook for request.
		add_filter( 'request', array( $this, 'orderby_last_login' ) );

		// Hook for run a query before fetch users.
		add_action( 'pre_user_query', array( $this, 'sort_pre_user_query' ) );
	}


	/**
	 * When a new user registers, add the wp_last_login usermeta
	 * and set it to the current date.
	 *
	 * @since 1.0
	 * @param int $user_id The ID of the user.
	 * @return void
	 * @see update_user_meta()
	 **/
	public function register_last_login( $user_id ) {
		update_user_meta( $user_id, 'wp_last_login', time() );
	}


	/**
	 * Update the last login time when a user logs in.
	 *
	 * @since 1.0
	 * @param string   $user_login The user's login name.
	 * @param \WP_User $user The WP_User object of the logged-in user.
	 * @return void
	 * @see update_user_meta()
	 **/
	public function update_last_login( $user_login, \WP_User $user ) {
		update_user_meta( $user->ID, 'wp_last_login', time() );
	}


	/**
	 * Adds the last login column to the network admin user list.
	 *
	 * @since  1.0
	 * @param  array $columns The columns on the manage users screen.
	 * @return array
	 * @see add_filter()
	 **/
	public function add_date_columns( $columns ) {
		$columns['wp_last_login'] = __( 'Last Login', 'remove-inactive-users' );
		$columns['registration_date'] = __( 'Registration Date', 'remove-inactive-users' );
		return $columns;
	}


	/**
	 * Sets the content of the last login column.
	 *
	 * @since 1.0
	 * @param string $value The value of the column.
	 * @param string $column_name The name of the column.
	 * @param int    $user_id The ID of the user.
	 * @return string $value The modified value.
	 * @see get_user_meta()
	 * @see DateTime()
	 **/
	public function populate_date_columns( $value, $column_name, $user_id ) {
		if ( 'registration_date' === $column_name ) {
			$local_reg_date = wp_date( 'Y-m-d h:ia', strtotime( get_userdata( $user_id )->user_registered ) );
			return $local_reg_date ?? '&ndash;';
		}

		if ( 'wp_last_login' === $column_name ) {
			$last_login = (int) get_user_meta( $user_id, 'wp_last_login', true );
			if ( 0 === $last_login ) return '&ndash;';

			return wp_date( 'Y-m-d h:ia', $last_login );
		}

		return $value;
	}


	/**
	 * Register the new column as sortable.
	 *
	 * @since  1.0
	 * @param array $columns The columns on the manage users screen.
	 * @return array
	 * @see add_filter()
	 **/
	public function sortable_date_columns( $columns ) {
		$columns['wp_last_login'] = 'wp_last_login';
		$columns['registration_date'] = 'registration_date';
		return $columns;
	}


	/**
	 * Handle ordering by last login.
	 *
	 * @since  1.0
	 * @param  array $vars The query variables.
	 * @return array $vars The modified query variables.
	 * @see array_merge()
	 **/
	public function orderby_last_login( $vars ) {
		if ( isset( $vars['orderby'] ) && 'wp_last_login' === $vars['orderby'] ) {
			$vars['meta_key'] = 'wp_last_login'; // phpcs:ignore WordPress.DB.SlowDBQuery
			$vars['orderby']  = 'meta_value';
			$vars['order']    = 'asc';
		}

		return $vars;
	}


	/**
	 * Handle query for sorting before listing.
	 *
	 * @since  1.0
	 * @param  object $user_search The user search object.
	 * @return void
	 * @see $wpdb
	 **/
	public function sort_pre_user_query( $user_search ) {
		global $wpdb;

		// Check if we're currently in the 'orderby' query var.
		$vars = $user_search->query_vars;

		if ( 'wp_last_login' === $vars['orderby'] ) {
			$user_search->query_from   .= " INNER JOIN {$wpdb->usermeta} m1 ON {$wpdb->users}.ID=m1.user_id AND (m1.meta_key='wp_last_login')";
			$user_search->query_orderby = ' ORDER BY UPPER(m1.meta_value) ' . $vars['order'];
		}
	}
}
