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
	private const DEFAULT_OPTS = array(
		'inactive_period' => 365,
		'inactive_roles'  => array( 'subscriber' ),
		'auto_remove'     => false,
		'remove_roleless' => false,
	);


	/**
	 * The currently set options
	 *
	 * @var array<string, mixed>
	 */
	private array $options;


	/**
	 * Array of inactive user IDs
	 *
	 * @var array<int>
	 */
	public array $inactive = array();


	/**
	 * Array of roleless user IDs
	 *
	 * @var array<int>
	 */
	public array $roleless = array();


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

		// Retrieve roleless users if the option is enabled.
		if ( ! empty( $this->options['remove_roleless'] ) ) {
			add_action( 'init', array( $this, 'set_roleless_users' ) );
		}

		// Load the Cron class.
		new Cron();

		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'users_menu' ) );
			add_action( 'network_admin_menu', array( $this, 'options_menu' ) );
			add_action( 'network_admin_edit_remove_inactive_users', array( $this, 'settings_callback' ), 5, 0 );
		} else {
			add_action( 'admin_menu', array( $this, 'users_menu' ) );
			add_action( 'admin_menu', array( $this, 'options_menu' ) );
			add_action( 'admin_post_remove_inactive_users', array( $this, 'settings_callback' ), 5, 0 );
		}

		add_action( 'admin_init', array( $this, 'register_settings' ) );
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
		$bool_options = array( 'auto_remove', 'remove_roleless' );

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

		// If boolean keys are missing, set them to false.
		foreach ( $bool_options as $key ) {
			if ( ! isset( $sanitized[ $key ] ) ) {
				$sanitized[ $key ] = false;
			}
		}

		// Additional sanitization.
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
		$options = get_option( 'remove_inactive_users' );
		if ( false === $options || empty( $options ) ) {
			// The options don't exist in the DB. Add them with default values.
			$options = self::DEFAULT_OPTS;
			add_option( 'remove_inactive_users', $options );
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
	 * Update the array of roleless user IDs ($this->roleless)
	 *
	 * Finds all users whose capabilities meta is an empty array (no role assigned).
	 *
	 * @return void
	 */
	public function set_roleless_users() {
		global $wpdb;

		$this->roleless = get_users(
			array(
				'fields'     => 'ID',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Necessary to identify users with no role.
					array(
						'key'     => $wpdb->prefix . 'capabilities',
						'value'   => 'a:0:{}',
						'compare' => '=',
					),
				),
			)
		);
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
			wp_die( esc_html__( 'You do not have permission to remove users.', 'remove-inactive-users' ) );
		}

		// Verify the nonce.
		// phpcs:ignore WordPress.Security -- Unslashing and sanitization unnecessary for nonce verification
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'remove-inactive-users' ) ) {
			return;
		}

		// If there are no users to remove, return early.
		if ( empty( $this->inactive ) && empty( $this->roleless ) ) {
			return 0;
		}

		// Make sure the User Admin API is loaded.
		require_once ABSPATH . 'wp-admin/includes/user.php';

		// Loop through the inactive users and delete them.
		$removed_user_count = 0;
		foreach ( $this->inactive as $user_id ) {
			if ( is_multisite() && 1 === count( get_blogs_of_user( $user_id ) ) ) {
				$delete_user = wpmu_delete_user( $user_id );
			} else {
				$delete_user = wp_delete_user( $user_id );
			}
		}

		// Loop through the roleless users and delete them.
		foreach ( $this->roleless as $user_id ) {
			if ( is_multisite() && 1 === count( get_blogs_of_user( $user_id ) ) ) {
				wpmu_delete_user( $user_id );
			} else {
				wp_delete_user( $user_id );
			}
		}

		// Get the number of users to remove from the inactive users array.
		$inactive_user_count = count( $this->inactive );

		// If not all users were removed, return an error.
		if ( ! isset( $removed_user_count ) || $removed_user_count < $inactive_user_count ) {
			$error_obj = new \WP_Error(
				'remove-inactive-users',
				// translators: %d is the number of inactive users.
				wp_sprintf( __( 'Error: unable to remove %d users', 'remove-inactive-users' ), $inactive_user_count - $removed_user_count )
			);
			return $error_obj;
		}

		// Reset the users arrays.
		$this->inactive = array();
		$this->roleless = array();

		// Return the number of users removed.
		return $removed_user_count;
	}


	/**
	 * Add submenu page to the Users admin
	 *
	 * @see https://developer.wordpress.org/reference/functions/add_users_page/
	 */
	public function users_menu() {
		add_users_page(
			__( 'Manage Inactive Users', 'remove-inactive-users' ),
			__( 'Inactive Users', 'remove-inactive-users' ),
			'remove_users',
			'remove-inactive-users',
			array( $this, 'users_admin_page' ),
			1.5
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
			wp_die( esc_html__( 'You do not have permission to remove users.', 'remove-inactive-users' ) );
		}
		?>
		<div id="remove-inactive-users" class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Manage Inactive Users', 'remove-inactive-users' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'options-general.php?page=remove-inactive-users' ) ); ?>"><?php esc_html_e( 'Settings', 'remove-inactive-users' ); ?></a>
			<?php

			if ( ! empty( $this->options['auto_remove'] ) ) {
				$next_run    = wp_next_scheduled( 'jm_remove_inactive_users_auto_remove' );
				$notice_type = 'info';
				// translators: %l is the list of user roles, %s is the plural suffix.
				$schedule_notice_msg = __( 'Automatic daily removal of all inactive users in the %l role%s is currently enabled.', 'remove-inactive-users' );

				if ( $next_run ) {
					$next_run_formatted = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run );

					// translators: %s is the date and time of the next scheduled run.
					$schedule_notice_msg .= '<br><strong>' . wp_sprintf( __( 'Scheduled for %s', 'remove-inactive-users' ), $next_run_formatted ) . '</strong>';
				} else {
					$notice_type = 'warning';
				}
			}

			// translators: %1$l is the list of user roles, %2$s is the plural suffix, %3$d is the number of days.
			$description = __( 'Remove users in the %1$l role%2$s who have not logged in to the site in over %3$d days.', 'remove-inactive-users' );

			$plural_suffix = '';
			if ( count( $this->options['inactive_roles'] ) > 1 ) {
				$plural_suffix = 's';
			}

			echo '<p>' . esc_html( wp_sprintf( $description, $this->options['inactive_roles'], $plural_suffix, $this->options['inactive_period'] ) ) . '</p>';

			if ( ! empty( $this->options['remove_roleless'] ) ) {
				echo '<p>' . esc_html__( 'Additionally, users with no assigned role will also be removed.', 'remove-inactive-users' ) . '</p>';
			}

			if ( isset( $schedule_notice_msg ) ) {
				$schedule_notice = wp_sprintf( $schedule_notice_msg, $this->options['inactive_roles'], $plural_suffix );
				echo '<p class="notice notice-large notice-' . esc_attr( $notice_type ) . '">' . wp_kses_post( $schedule_notice ) . '</p>';
			}

			echo '<hr class="wp-header-end">';

			// Attempt to remove users.
			$removed_users = $this->remove_users();
			if ( $removed_users && ! is_wp_error( $removed_users ) ) {

				// translators: %d is the number of removed users.
				echo '<p class="notice notice-large notice-success">' . esc_html( wp_sprintf( __( '%d users have been removed.', 'remove-inactive-users' ), $removed_users ) ) . '</p>';
			}

			// Calculate the total number of users to remove.
			$total_removable = count( $this->inactive ) + ( ! empty( $this->options['remove_roleless'] ) ? count( $this->roleless ) : 0 );

			// If there are users to remove, display the user tables and form.
			if ( $total_removable > 0 ) {

				if ( count( $this->inactive ) > 0 ) {
					// translators: %d is the number of inactive users.
					echo '<p>' . esc_html( wp_sprintf( __( 'There are currently %d inactive users', 'remove-inactive-users' ), count( $this->inactive ) ) ) . ':</p>';
					$this->inactive_users_table();
				}

				if ( ! empty( $this->options['remove_roleless'] ) && count( $this->roleless ) > 0 ) {
					// translators: %d is the number of roleless users.
					echo '<p>' . esc_html( wp_sprintf( __( 'There are currently %d users with no assigned role', 'remove-inactive-users' ), count( $this->roleless ) ) ) . ':</p>';
					$this->roleless_users_table();
				}
				?>
				<form id="remove-inactive-users-form" method="post">
					<?php
					// Add a nonce field.
					wp_nonce_field( 'remove-inactive-users' );

					// Add the submit button.
					// translators: %d is the total number of users to remove.
					submit_button( wp_sprintf( __( 'Remove %d users', 'remove-inactive-users' ), $total_removable ), 'button-primary', 'delete', true, array( 'id' => 'submit-btn' ) );
					?>
				</form>
			<?php } else { ?>
				<p><?php esc_html_e( 'There are currently 0 users to remove.', 'remove-inactive-users' ); ?></p>
			<?php } ?>
		</div>
		<?php
	}


	/**
	 * Register the settings
	 */
	public function register_settings() {
		register_setting(
			'remove_inactive_users_config',
			'remove_inactive_users',
			array( $this, 'settings_callback' )
		);
	}


	/**
	 * Add options page
	 */
	public function options_menu() {
		add_submenu_page(
			is_multisite() ? 'settings.php' : 'options-general.php',
			__( 'Remove Inactive Users Settings', 'remove-inactive-users' ),
			__( 'Remove Inactive Users', 'remove-inactive-users' ),
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
			wp_die( esc_html__( 'You do not have permission to manage options.', 'remove-inactive-users' ) );
		}

		if ( defined( 'JM_REMOVE_INACTIVE_USERS_OPTIONS' ) ) {
			$override = self::sanitize_options( JM_REMOVE_INACTIVE_USERS_OPTIONS );
		}
		?>
		<div id="remove-inactive-users-options" class="wrap">
			<h1><?php esc_html_e( 'Remove Inactive Users Options', 'remove-inactive-users' ); ?></h1>
			<p><?php esc_html_e( 'Settings for the Remove Inactive Users plugin.', 'remove-inactive-users' ); ?></p>
			<?php
			if ( ! empty( $override ) ) {
				echo '<p class="notice notice-large notice-warning">' . esc_html__( 'Some options have been overridden by the plugin constants.', 'remove-inactive-users' ) . '</p>';
			}
			// Determine the POST action URL.
			$post_url = add_query_arg( 'action', 'remove_inactive_users', admin_url( 'admin-post.php' ) );
			if ( is_multisite() ) {
				$post_url = add_query_arg( 'action', 'remove_inactive_users', network_admin_url( 'edit.php' ) );
			}
			?>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>">
				<?php
				wp_nonce_field( 'remove-inactive-users-' . JM_REMOVE_INACTIVE_USERS_VERSION, 'remove_inactive_users[_nonce]' );
				?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="remove-inactive-users--inactive_roles"><?php esc_html_e( 'Inactive Roles', 'remove-inactive-users' ); ?></label></th>
						<td>
							<select id="remove-inactive-users--inactive_roles" name="remove_inactive_users[inactive_roles][]" multiple
							<?php echo isset( $override['inactive_roles'] ) ? 'disabled' : ''; ?>>
								<?php
								$roles = get_editable_roles();
								foreach ( $roles as $role => $details ) {
									if ( 'administrator' !== $role ) {
										$selected = in_array( $role, (array) $this->options['inactive_roles'], true );
										echo '<option value="' . esc_attr( $role ) . '" ' . selected( $selected, true, false ) . '>' . esc_html( $details['name'] ) . '</option>';
									}
								}
								?>
							</select>
							<p class="description"><?php esc_html_e( 'Select all roles that should be checked for inactivity.', 'remove-inactive-users' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Inactive Period', 'remove-inactive-users' ); ?></th>
						<td>
							<input type="number" id="remove-inactive-users--inactive_period" name="remove_inactive_users[inactive_period]" value="<?php echo esc_attr( $this->options['inactive_period'] ); ?>" class="small-text"
							<?php echo isset( $override['inactive_period'] ) ? 'disabled' : ''; ?>/>
							<label for="remove-inactive-users--inactive_period"><?php esc_html_e( 'Days since last login.', 'remove-inactive-users' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto Remove', 'remove-inactive-users' ); ?></th>
						<td>
							<input type="checkbox" id="remove-inactive-users--auto_remove" name="remove_inactive_users[auto_remove]" value="1"
							<?php
							checked( $this->options['auto_remove'] );
							echo isset( $override['inactive_period'] ) ? ' disabled' : '';
							?>
							/>
							<label for="remove-inactive-users--auto_remove"><?php esc_html_e( 'Enable daily automatic removal of inactive users.', 'remove-inactive-users' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Remove Roleless', 'remove-inactive-users' ); ?></th>
						<td>
							<input type="checkbox" id="remove-inactive-users--remove_roleless" name="remove_inactive_users[remove_roleless]" value="1"
							<?php
							checked( $this->options['remove_roleless'] );
							echo isset( $override['remove_roleless'] ) ? ' disabled' : '';
							?>
							/>
							<label for="remove-inactive-users--remove_roleless"><?php esc_html_e( 'Remove users with no assigned role.', 'remove-inactive-users' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}


	/**
	 * Settings callback handler
	 */
	public function settings_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage options.', 'remove-inactive-users' ) );
		}

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['remove_inactive_users'] ) ) {
			wp_die( 'Request method isn\'t POST or post data is empty!' );
		}

		// Verify the nonce.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Nonce sanitization unnecessary
		if ( empty( $_POST['remove_inactive_users']['_nonce'] ) || ! wp_verify_nonce( $_POST['remove_inactive_users']['_nonce'], 'remove-inactive-users-' . JM_REMOVE_INACTIVE_USERS_VERSION ) ) {
			wp_die( esc_html__( 'Invalid nonce specified.', 'remove-inactive-users' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization handled in sanitize_options().
		$this->options = self::sanitize_options( wp_unslash( $_POST['remove_inactive_users'] ) );

		update_option( 'remove_inactive_users', $this->options );

		// Generate the return_to URL.
		$return_to_page = 'options-general.php';
		if ( is_multisite() ) {
			$return_to_page = 'settings.php';
		}
		$return_to = add_query_arg(
			array(
				'updated' => 'true',
				'page'    => 'remove-inactive-users',
			),
			network_admin_url( $return_to_page )
		);

		wp_safe_redirect( $return_to );
		die;
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
					<th><?php esc_html_e( 'Name', 'remove-inactive-users' ); ?></th>
					<th><?php esc_html_e( 'Last Login', 'remove-inactive-users' ); ?></th>
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
	 * Generate the roleless users table
	 *
	 * @return void
	 *
	 * @see https://developer.wordpress.org/reference/functions/get_edit_user_link/
	 */
	private function roleless_users_table() {
		// Only generate a table if roleless users exist.
		if ( empty( $this->roleless ) ) {
			return;
		}
		?>
		<table class="wp-list-table widefat striped table-view-list">
			<thead>
				<tr>
					<th>#</th>
					<th><?php esc_html_e( 'Name', 'remove-inactive-users' ); ?></th>
					<th><?php esc_html_e( 'Email', 'remove-inactive-users' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $this->roleless as $key => $user_id ) {
					$user = get_userdata( $user_id );
					if ( ! $user ) {
						continue;
					}
					?>
					<tr>
						<td><?php echo esc_html( $key + 1 ); ?></td>
						<td><a href="<?php echo esc_url( get_edit_user_link( $user_id ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a></td>
						<td><?php echo esc_html( $user->user_email ); ?></td>
					</tr>
				<?php } ?>
			</tbody>
		</table>
		<?php
	}


	/**
	 * Add scripts to header
	 *
	 * @see https://developer.wordpress.org/reference/functions/get_current_screen/
	 * @see https://developer.wordpress.org/reference/functions/wp_enqueue_script/
	 */
	public function scripts() {
		// Only add scripts to the Inactive Users management page.
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, array( 'users_page_remove-inactive-users', 'users_page_remove-inactive-users-network', true ) ) ) {
			wp_enqueue_script( 'remove-inactive-users', JM_REMOVE_INACTIVE_USERS_URL . 'admin/scripts.js', array(), JM_REMOVE_INACTIVE_USERS_VERSION, true );
		}
	}
}
