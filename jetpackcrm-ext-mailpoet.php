<?php
/*
Plugin Name: Jetpack CRM Extension: MailPoet Sync
Plugin URI: https://github.com/a8cteam51
Description: Sync MailPoet subscribers into Jetpack CRM
Version: 0.1
Author: Team51
Author URI: https://github.com/a8cteam51
*/


// } Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// } Paths
define( 'ZEROBSCRM_MAILPOET_PATH', plugin_dir_path( __FILE__ ) );
define( 'ZEROBSCRM_MAILPOET_URL', plugin_dir_url( __FILE__ ) );


// } Load only if ZBS is loaded
add_action( 'after_zerobscrm_settings_init', 'zeroBSCRM_load_mailpoet', 11 );
function zeroBSCRM_load_mailpoet() {
	require_once ZEROBSCRM_MAILPOET_PATH . 'inc/mailpoet-sync-database.php';
	require_once ZEROBSCRM_MAILPOET_PATH . 'inc/mailpoet-sync-admin-page.php';
}

/**
 * Database setup.
 * TODO: Needs to be moved to mailpoet-sync-database.php
 */
global $jal_db_version;
$jal_db_version = '1.3';

register_activation_hook( ZEROBSCRM_MAILPOET_PATH, 'jal_install' );

function jal_install() {

	global $wpdb;
	global $jal_db_version;

	$table_name      = $wpdb->prefix . 'zbs_mailpoet_sync';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = 'CREATE TABLE ' . $table_name . ' (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		mailpoet_subscriber_id mediumint(9) NOT NULL default 0,
		zbs_contact_id mediumint(9) NOT NULL default 0,
		last_status VARCHAR(20) NOT NULL default 0,
		PRIMARY KEY (id),
		KEY mailpoet_subscriber_id (mailpoet_subscriber_id),
		KEY zbs_contact_id (zbs_contact_id)
	)' . $charset_collate . ';';

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta( $sql );

	add_option( 'jal_db_version', $jal_db_version );
}

function mailpoet_crm_sync_update_db_check() {
	global $jal_db_version;
	if ( get_site_option( 'jal_db_version' ) != $jal_db_version ) {
		jal_install();
	}
}
add_action( 'plugins_loaded', 'mailpoet_crm_sync_update_db_check' );


