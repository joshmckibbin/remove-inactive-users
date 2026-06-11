=== Remove Inactive Users ===
Contributors: Josh Mckibbin
Tags: users, inactive, cleanup, admin
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 3.0.6
License: MIT
License URI: https://opensource.org/licenses/MIT

A simple plugin to remove inactive users from your WordPress site.

== Description ==
Remove Inactive Users is a simple plugin that helps administrators identify and remove users who have not logged in for a specified period. Keep your WordPress user list clean and up-to-date.

== Installation ==
1. Upload the plugin files to the `/wp-content/plugins/remove-inactive-users` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Configure the plugin settings via the 'Users' menu.

== Frequently Asked Questions ==
= How do I set the inactivity period? =
Go to the plugin settings page under 'Settings' and specify the number of days.

= Will this remove users automatically? =
Only if you have enabled the 'Auto Remove' option in the settings. If enabled, it will remove users automatically at regular daily intervals. Otherwise, you will need to manually remove users by submitting the form on the plugin's admin page.

== Screenshots ==
1. Plugin User Options page.
2. Plugin Settings page.

== Changelog ==

= 3.0.6 =
* Fix: Ensure that notice type is set when displaying schedule notice.

= 3.0.5 =
* Fix: Casting of user last login date to integer for accurate comparison.

= 3.0.4 =
* Fix: Link to settings page in admin area.

= 3.0.3 =
* Added option to remove users with no assigned role.
* Improved error handling when removing users.

= 3.0.2 =
* Changed date format in user columns to be consistent with core WordPress tables.
* Changed name of 'Registration Date' user column to 'Registered'.
* Fix: Sorting of Registered column.

= 3.0.1 =
* Added 'Registration Date' column to users table in admin area.

= 3.0.0 =
* Initial public release.
