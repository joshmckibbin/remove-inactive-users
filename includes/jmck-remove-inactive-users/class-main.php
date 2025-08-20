<?php
/**
 * The main plugin handler
 *
 * @package remove-inactive-users
 */

namespace JMck_Remove_Inactive_Users;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Main Class
 *
 * Handles the main functionality of the plugin, including user inactivity checks and removals.
 */
class Main {

	/**
	 * The number of days before a user is considered inactive.
	 *
	 * @var int
	 */
	private int $days = 365;


	/**
	 * The user roles to check for inactivity.
	 *
	 * @var array<string>
	 */
	private array $roles = array( 'subscriber' );


	/**
	 * The inactive users array.
	 *
	 * @var array<int>
	 */
	private array $inactive = array();


	/**
	 * Initialize the class
	 *
	 * @return void
	 */
	public function __construct() {
		// Set the number of days of inactivity.
		$this->set_days();

		// Set the roles.
		$this->set_roles();

		// Load the Last_Login class.
		new Last_Login();

		// Retrieve the inactive users and store them in a class property.
		add_action( 'init', array( $this, 'set_inactive_users' ) );

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );
	}


	/**
	 * Set the number of days to check for inactivity
	 *
	 * @return void
	 */
	private function set_days() {
		if ( defined( 'JMCK_REMOVE_INACTIVE_USERS_DAYS' ) && is_numeric( JMCK_REMOVE_INACTIVE_USERS_DAYS ) ) {
			$this->days = (int) JMCK_REMOVE_INACTIVE_USERS_DAYS;
		}
	}


	/**
	 * Set the roles to check for inactivity
	 *
	 * @return void
	 */
	private function set_roles() {
		if ( defined( 'JMCK_REMOVE_INACTIVE_USERS_ROLES' ) && ! empty( JMCK_REMOVE_INACTIVE_USERS_ROLES ) ) {
			if ( is_array( JMCK_REMOVE_INACTIVE_USERS_ROLES ) ) {
				$this->roles = JMCK_REMOVE_INACTIVE_USERS_ROLES;
			} else {
				// If roles are defined as a string, explode it into an array.
				$this->roles = array_map( 'trim', explode( ',', JMCK_REMOVE_INACTIVE_USERS_ROLES ) );
			}
		}
		// Remove any roles that don't actually exist.
		foreach ( $this->roles as $key => $role ) {
			if ( ! get_role( $role ) ) {
				unset( $this->roles[ $key ] );
			}
		}
	}


	/**
	 * Return an array of inactive user IDs
	 *
	 * @return void
	 * @see https://developer.wordpress.org/reference/functions/get_users/
	 * @see https://developer.wordpress.org/reference/functions/get_user_meta/
	 */
	public function set_inactive_users() {

		$queried_users = get_users(
			array(
				'role__in' => $this->roles,
				'meta_key' => 'wp_last_login', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- This query may be slow on large user databases, but is necessary for inactivity checks.
				'orderby'  => 'meta_value_num',
				'fields'   => 'ID',
			)
		);

		foreach ( $queried_users as $user_id ) {
			// Get the user's last login.
			$user_last_login = get_user_meta( $user_id, 'wp_last_login', true );

			// Get the date to check (x days ago from today's date).
			$date_to_check = strtotime( wp_sprintf( '%s -%d days', gmdate( 'Y-m-d' ), $this->days ) );

			// If the user's last login is greater than the date to check, add to the inactive users array.
			if ( ( $date_to_check - $user_last_login ) >= 0 ) {
				$this->inactive[] = $user_id;
			}
		}
	}


	/**
	 * Delete users on form submission handler
	 *
	 * @return int|WP_Error The number of users deleted or an error object if the users could not be deleted
	 * @see https://developer.wordpress.org/reference/functions/wp_delete_user/
	 * @see https://developer.wordpress.org/reference/functions/wp_verify_nonce/
	 * @see https://developer.wordpress.org/reference/functions/wp_nonce_field/
	 */
	private function remove_users() {
		// Only proceed if user has the capability to remove users.
		if ( ! current_user_can( 'remove_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to remove users.' ) );
		}

		// Verify the nonce.
		// phpcs:ignore WordPress.Security -- Unslashing and sanitization unnecessary for nonce verification
		if ( ! isset( $_POST['remove_inactive_users'] ) || ! wp_verify_nonce( $_POST['remove_inactive_users'], 'riu_noncey_poo' ) ) {
			return;
		}

		// If there are no inactive users, return early.
		if ( empty( $this->inactive ) ) {
			return 0;
		}

		// Get the number of users to remove from the inactive users array.
		$remove_count = count( $this->inactive );

		// Loop through the inactive users and delete them.
		foreach ( $this->inactive as $removed_user ) {
			if ( ! wp_delete_user( $removed_user ) ) {
				$error_obj = new WP_Error(
					'remove-inactive-users',
					// translators: %s is the user display name.
					wp_sprintf( __( 'Error: unable to remove %s' ), get_the_author_meta( 'display_name', $removed_user ) )
				);
				return $error_obj;
			}
		}

		// Reset the inactive users array.
		$this->inactive = array();

		// Return the number of users removed.
		return $remove_count;
	}


	/**
	 * Add submenu page to the Users admin
	 *
	 * @return void
	 * @see https://developer.wordpress.org/reference/functions/add_users_page/
	 */
	public function menu() {
		add_users_page(
			__( 'Remove Inactive Users' ),
			__( 'Remove Inactive Users' ),
			'remove_users',
			'remove-inactive-users',
			array( $this, 'admin_page' ),
			2.5
		);
	}


	/**
	 * Create the admin page
	 *
	 * @return void
	 */
	public function admin_page() {
		?>
		<div id="remove-inactive-users" class="wrap">
			<h1><?php esc_html_e( 'Remove Inactive Users' ); ?></h1>
			<?php
			// translators: %l is the list of user roles, %d is the number of days.
			echo '<p>' . esc_html( wp_sprintf( __( 'Purge users (%l) who have not logged in to the site in over %d days.' ), $this->roles, $this->days ) ) . '</p>';

			echo '<hr class="wp-header-end">';

			// Attempt to remove users.
			$removed_users = $this->remove_users();
			if ( $removed_users && ! is_wp_error( $removed_users ) ) {

				// translators: %d is the number of removed users.
				echo '<p class="notice notice-large notice-success">' . esc_html( wp_sprintf( __( '%d users have been removed.' ), $removed_users ) ) . '</p>';
			}

			// If there are inactive users, display the inactive user table and form.
			if ( count( $this->inactive ) > 0 ) {

				// translators: %d is the number of inactive users.
				echo '<p>' . esc_html( wp_sprintf( __( 'There are currently %d inactive users' ), count( $this->inactive ) ) ) . '</p>';

				$this->inactive_users_table();
				?>
				<form id="remove-inactive-users-form" method="post">
					<?php
					// Add a nonce field.
					wp_nonce_field( 'riu_noncey_poo', 'remove_inactive_users' );

					// Add the submit button.
					// translators: %$d is the number of inactive users.
					submit_button( wp_sprintf( __( 'Remove %d users' ), count( $this->inactive ) ), 'button-primary', 'delete', true, array( 'id' => 'submit-btn' ) );
					?>
				</form>
			<?php } else { ?>
				<p><?php esc_html_e( 'There are currently 0 inactive users.' ); ?></p>
			<?php } ?>
		</div>
		<?php
	}


	/**
	 * Generate the inactive users table
	 *
	 * @return string $table The HTML table of inactive users
	 * @see https://developer.wordpress.org/reference/functions/get_edit_user_link/
	 * @see https://developer.wordpress.org/reference/functions/get_user_meta/
	 */
	private function inactive_users_table() {
		// Only generate a table if inactive users exist.
		if ( ! $this->inactive ) {
			return;
		}
		?>
		<table class="wp-list-table widefat striped table-view-list">
			<thead>
				<tr>
					<th>#</th>
					<th><?php esc_html_e( 'Name' ); ?></th>
					<th><?php esc_html_e( 'Last Login' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				// Create a row for each inactive user.
				foreach ( $this->inactive as $key => $inactive_id ) {

					// Retrieve the user's last login time.
					$last_login = get_user_meta( $inactive_id, 'wp_last_login', true );
					?>
					<tr>
						<td><?php echo esc_html( $key + 1 ); ?></td>
						<td><a href="<?php echo esc_url( get_edit_user_link( $inactive_id ) ); ?>"><?php echo esc_html( get_the_author_meta( 'display_name', $inactive_id ) ); ?></a></td>
						<td><?php echo esc_html( wp_date( 'Y-m-d h:ia', $last_login ) ); ?></td>
					</tr>
				<?php } ?>
			</tbody>
		</table>
		<?php
	}


	/**
	 * Add scripts to header
	 *
	 * @return void
	 * @see https://developer.wordpress.org/reference/functions/wp_enqueue_script/
	 */
	public function scripts() {
		// Only add scripts to the management page.
		if ( isset( $_GET['page'] ) && 'remove-inactive-users' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_enqueue_script( 'remove-inactive-users', JMCK_REMOVE_INACTIVE_USERS_URL . 'assets/scripts.js', array(), JMCK_REMOVE_INACTIVE_USERS_VERSION, true );
		}
	}
}
