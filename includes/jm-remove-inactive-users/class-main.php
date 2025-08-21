<?php
/**
 * The main plugin handler
 *
 * @package remove-inactive-users
 */

namespace JM_Remove_Inactive_Users;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Main Class
 *
 * Handles the main functionality of the plugin, including user inactivity checks and removals.
 */
class Main {

	/**
	 * The default plugin options
	 *
	 * @var array<string, mixed>
	 */
	private const array DEFAULT_OPTS = array(
		'inactive_period' => 365,
		'inactive_roles'  => array( 'subscriber' ),
		'auto_remove'     => false,
	);


	/**
	 * The currently set options
	 *
	 * @var array<string, mixed>
	 */
	private array $options;


	/**
	 * The inactive users array.
	 *
	 * @var array<int>
	 */
	private array $inactive = array();


	/**
	 * Initialize the class
	 *
	 * Sets up the plugin by loading options and initializing necessary components.
	 */
	public function __construct() {
		// Get the options.
		$this->options = self::get_options();

		// Load the Last_Login class.
		new Last_Login();

		// Retrieve the inactive users and store them in a class property.
		add_action( 'init', array( $this, 'set_inactive_users' ) );

		add_action( 'admin_menu', array( $this, 'users_menu' ) );
		add_action( 'admin_menu', array( $this, 'options_menu' ) );
		add_action( 'admin_post_remove_inactive_users', array( $this, 'handle_post' ), 5, 0 );

		add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );
	}


	/**
	 * Sanitize the options
	 *
	 * @param array<string, mixed> $options The options to sanitize.
	 *
	 * @return array<string, mixed> The sanitized options.
	 *
	 * @see https://developer.wordpress.org/reference/functions/sanitize_text_field/
	 */
	private static function sanitize_options( array $options ) {
		// Integer keys to look for.
		$int_options = array( 'inactive_period' );

		// Array keys to look for.
		$arr_options = array( 'inactive_roles' );

		// Boolean keys to look for.
		$bool_options = array( 'auto_remove' );

		// Create the sanitized array.
		$sanitized = array();

		// Sanitize depending on var type.
		foreach ( $options as $key => $value ) {
			if ( in_array( $key, $int_options, true ) ) {
				// Sanitize integer values.
				$sanitized[ $key ] = absint( $value );
			} elseif ( in_array( $key, $arr_options, true ) ) {
				// Sanitize array values.
				if ( is_array( $value ) ) {
					$sanitized[ $key ] = array_map( 'sanitize_text_field', $value );
				}
			} elseif ( in_array( $key, $bool_options, true ) ) {
				// Sanitize boolean values.
				$sanitized[ $key ] = filter_var( $value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE );
				if ( null === $sanitized[ $key ] ) {
					$sanitized[ $key ] = false; // Default to false if not set.
				}
			}
		}

		// Additional sanitization for inactive roles.
		if ( isset( $sanitized['inactive_roles'] ) ) {
			// Make sure the roles actually exist and are not 'administrator'.
			foreach ( $sanitized['inactive_roles'] as $key => $role ) {
				if ( ! get_role( $role ) || 'administrator' === $role ) {
					unset( $sanitized['inactive_roles'][ $key ] );
				}
			}
		}

		return $sanitized;
	}


	/**
	 * Get the plugin options.
	 *
	 * This method will fetch the options from the database. If options do not
	 * exist, they will be added with appropriate default values. Note that
	 * *_site_option() functions are safe for both single-site and multi-site.
	 *
	 * @return array<string, mixed>
	 *
	 * @see https://developer.wordpress.org/reference/functions/get_site_option/
	 * @see https://developer.wordpress.org/reference/functions/add_site_option/
	 */
	private static function get_options() {
		$options = get_site_option( 'remove_inactive_users', false );
		if ( false === $options || empty( $options ) ) {
			// The options don't exist in the DB. Add them with default values.
			$options = self::DEFAULT_OPTS;
			add_site_option( 'remove_inactive_users', $options );
		}

		// If the constant is set, override any specified options.
		if ( defined( 'JM_REMOVE_INACTIVE_USERS_OPTIONS' ) && is_array( JM_REMOVE_INACTIVE_USERS_OPTIONS ) ) {
			$sanitized_constants = self::sanitize_options( JM_REMOVE_INACTIVE_USERS_OPTIONS );
			$options             = array_merge( $options, $sanitized_constants );
		}

		return $options;
	}


	/**
	 * Update the array of inactive user IDs ($this->inactive)
	 *
	 * @return void
	 *
	 * @see https://developer.wordpress.org/reference/functions/get_users/
	 * @see https://developer.wordpress.org/reference/functions/get_user_meta/
	 */
	public function set_inactive_users() {

		$queried_users = get_users(
			array(
				'role__in' => $this->options['inactive_roles'],
				'meta_key' => 'wp_last_login', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- This query may be slow on large user databases, but is necessary for inactivity checks.
				'orderby'  => 'meta_value_num',
				'fields'   => 'ID',
			)
		);

		foreach ( $queried_users as $user_id ) {
			// Get the user's last login.
			$user_last_login = get_user_meta( $user_id, 'wp_last_login', true );

			// Get the date to check (x days ago from today's date).
			$date_to_check = strtotime( wp_sprintf( '%s -%d days', gmdate( 'Y-m-d' ), $this->options['inactive_period'] ) );

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
	 *
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
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'jm-remove-inactive-users' ) ) {
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
				$error_obj = new \WP_Error(
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
	 * @see https://developer.wordpress.org/reference/functions/add_users_page/
	 */
	public function users_menu() {
		add_users_page(
			__( 'Remove Inactive Users' ),
			__( 'Remove Inactive Users' ),
			'remove_users',
			'remove-inactive-users',
			array( $this, 'users_admin_page' ),
			2.5
		);
	}


	/**
	 * Create the Users admin page
	 *
	 * @uses remove_users()
	 * @uses inactive_users_table()
	 */
	public function users_admin_page() {
		if ( ! current_user_can( 'remove_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to remove users.' ) );
		}
		?>
		<div id="remove-inactive-users" class="wrap">
			<h1><?php esc_html_e( 'Remove Inactive Users' ); ?></h1>
			<?php
			if ( count( $this->options['inactive_roles'] ) > 1 ) {
				// translators: %l is the list of user roles, %d is the number of days.
				$description = __( 'Remove users in the %l roles who have not logged in to the site in over %d days.' );
			} else {
				// translators: %l is the list of user roles, %d is the number of days.
				$description = __( 'Remove users in the %l role who have not logged in to the site in over %d days.' );
			}
			echo '<p>' . esc_html( wp_sprintf( $description, $this->options['inactive_roles'], $this->options['inactive_period'] ) ) . '</p>';

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
				echo '<p>' . esc_html( wp_sprintf( __( 'There are currently %d inactive users' ), count( $this->inactive ) ) ) . ':</p>';

				$this->inactive_users_table();
				?>
				<form id="remove-inactive-users-form" method="post">
					<?php
					// Add a nonce field.
					wp_nonce_field( 'jm-remove-inactive-users' );

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
	 * Add options page
	 */
	public function options_menu() {
		add_options_page(
			__( 'Remove Inactive Users Settings' ),
			__( 'Remove Inactive Users' ),
			'manage_options',
			'remove-inactive-users',
			array( $this, 'options_page' )
		);
	}


	/**
	 * Create the Options admin page
	 */
	public function options_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage options.' ) );
		}
		?>
		<div id="remove-inactive-users-options" class="wrap">
			<h1><?php esc_html_e( 'Remove Inactive Users Options' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'remove-inactive-users' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="remove-inactive-users--inactive_roles"><?php esc_html_e( 'Inactive Roles' ); ?></label></th>
						<td>
							<select id="remove-inactive-users--inactive_roles" name="remove_inactive_users[inactive_roles]" multiple>
								<?php
								$roles = get_editable_roles();
								foreach ( $roles as $role => $details ) {
									$selected = in_array( $role, (array) $this->options['inactive_roles'], true );
									echo '<option value="' . esc_attr( $role ) . '" ' . selected( $selected, true, false ) . '>' . esc_html( $details['name'] ) . '</option>';
								}
								?>
							</select>
							<p><?php esc_html_e( 'Select all roles that should be checked for inactivity.' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Inactive Period' ); ?></th>
						<td>
							<input type="number" id="remove-inactive-users--inactive_period" name="remove_inactive_users[inactive_period]" value="<?php echo esc_attr( $this->options['inactive_period'] ); ?>" class="small-text" />
							<label for="remove-inactive-users--inactive_period"><?php esc_html_e( 'Days' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto Remove' ); ?></th>
						<td>
							<input type="checkbox" id="remove-inactive-users--auto_remove" name="remove_inactive_users[auto_remove]" value="1" <?php checked( $this->options['auto_remove'] ); ?> />
							<label for="remove-inactive-users--auto_remove"><?php esc_html_e( 'Enable daily automatic removal of inactive users.' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}


	/**
	 * Handle POST requests for the settings page
	 */
	public function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage options.' ) );
		}

		// Verify the nonce.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Nonce sanitization unnecessary
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'jm-remove-inactive-users' ) ) {
			wp_die( esc_html__( 'Invalid nonce specified.', 'jm-remove-inactive-users' ) );
		}

		// Process the form submission.
		if ( ! isset( $_POST['remove_inactive_users'] ) ) {
			wp_die( esc_html__( 'No data received.', 'jm-remove-inactive-users' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization handled in sanitize_options().
		$options = $this->sanitize_options( wp_unslash( $_POST['remove_inactive_users'] ) );

		update_site_option( 'remove_inactive_users', $options );

		// Redirect back to the settings page.
		wp_safe_redirect( admin_url( 'options-general.php?page=remove-inactive-users' ) );
		exit;
	}


	/**
	 * Generate the inactive users table
	 *
	 * @return string $table The HTML table of inactive users
	 *
	 * @see https://developer.wordpress.org/reference/functions/get_edit_user_link/
	 * @see https://developer.wordpress.org/reference/functions/get_user_meta/
	 */
	private function inactive_users_table() {
		// Only generate a table if inactive users exist.
		if ( empty( $this->inactive ) ) {
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
	 * @see https://developer.wordpress.org/reference/functions/wp_enqueue_script/
	 */
	public function scripts() {
		// Only add scripts to the management page.
		if ( isset( $_GET['page'] ) && 'remove-inactive-users' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_enqueue_script( 'remove-inactive-users', JM_REMOVE_INACTIVE_USERS_URL . 'admin/scripts.js', array(), JM_REMOVE_INACTIVE_USERS_VERSION, true );
		}
	}
}
