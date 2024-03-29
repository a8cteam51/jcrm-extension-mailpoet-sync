<?php

add_action( 'admin_menu', 'jcrm_mailpoet_sync_setup_menu' );
add_action( 'jcrm_mailpoet_sync_scheduled_hook', 'zbs_mailpoet_start_sync' );

function jcrm_mailpoet_sync_setup_menu() {
	$menu = add_menu_page( 'MailPoet Jetpack CRM Sync Page', 'MailPoet Jetpack CRM Sync', 'manage_options', 'zbs-mailpoet-sync', 'zbs_mailpoet_sync_init' );
	add_action( 'admin_print_styles-' . $menu, 'mailpoet_crm_sync_custom_css' );
}

function mailpoet_crm_sync_custom_css() {
	wp_enqueue_style( 'mailpoet_crm_sync', ZEROBSCRM_MAILPOET_URL . '/assets/admin.css' );
}

/**
 * Renders the table, and also handles form submit
 */
function zbs_mailpoet_sync_init() {
	global $wpdb;
	$query = 'SELECT 
				s.id, 
				s.first_name, 
				s.last_name, 
				s.email, 
				s.status, 
				mps.id as synced_id, 
				mps.last_status
		FROM ' . $wpdb->prefix . 'mailpoet_subscribers AS s 
		LEFT OUTER JOIN ' . $wpdb->prefix . 'zbs_mailpoet_sync AS mps 
		ON s.id = mps.mailpoet_subscriber_id';

	// Submitted as $_POST means we will do a sync first.
	$start_sync = isset( $_POST['startsync'] ) ?: null;
	if ( $start_sync ) {
		$query_where_synced_is_null_or_changed = $query . ' WHERE mps.id IS NULL OR s.status COLLATE utf8mb4_unicode_ci != mps.last_status';
		$subscribers = $wpdb->get_results( $query_where_synced_is_null_or_changed );
		$pending     = count($subscribers);
		update_option( 'jpcrm_mailpoet_sync_pending', $pending );
		wp_clear_scheduled_hook( 'jcrm_mailpoet_sync_scheduled_hook' );
		wp_schedule_single_event( time(), 'jcrm_mailpoet_sync_scheduled_hook', array( $subscribers ) );
	}

	// Render the actual table.
	render_paginated_table( $query );
}


/**
 * Inserts from MailPoet into Jetpack CRM
 * Since this process can take a while, it's binded to an action which
 * is invoked by wp_schedule_single_event() during a form submit
 * * @param string $subscribers
 */
function zbs_mailpoet_start_sync( $subscribers ) {
	foreach ( $subscribers as $key => $subscriber ) {

		if ( ! empty( $subscriber->synced_id ) ) {
			if ( $subscriber->status == $subscriber->last_status ) {
				// skipping since data hasn't change.
				continue;
			}
		}
		$email  = $subscriber->email;
		$fname  = $subscriber->first_name;
		$lname  = $subscriber->last_name;
		$status = $subscriber->status;

		$contact_id = zeroBS_integrations_addOrUpdateCustomer(
			'api',
			$email,
			array(
				'zbsc_email'  => $email,
				'zbsc_status' => 'Lead',
				'zbsc_fname'  => $fname,
				'zbsc_lname'  => $lname,
				'tags'        => array( $status ),
			),
			'',     // Customer date (auto)
			'auto', // fallbackLog
			false,  // Extra meta
		);

		add_or_update_in_sync_table( $contact_id, $subscriber->id, $status, $subscriber->synced_id );
	}

	update_option( 'jpcrm_mailpoet_sync_pending', 0 );
}

/**
 * A helper function that keeps track of what's already synced. Data is stored in the table wp_zbs_mailpoet_sync
 * This function is invoked for each subscriber in MailPoet
 * @param $zbs_contact_id from Jetpack CRM table
 * @param $mailpoet_subscriber_id from MailPoet table
 * @param $status from Jetpack CRM table
 * @param $synced_id if empty, this is a new addition, if present then its the ID of zbs_mailpoet_sync table
 */
function add_or_update_in_sync_table( $zbs_contact_id, $mailpoet_subscriber_id, $status, $synced_id ) {
	global $wpdb;

	$table = $wpdb->prefix . 'zbs_mailpoet_sync';

	// It's an update
	if ( $synced_id ) {
		$successful_update = $wpdb->update(
			$table,
			array(
				'zbs_contact_id'         => $zbs_contact_id,
				'mailpoet_subscriber_id' => $mailpoet_subscriber_id,
				'last_status'            => $status,
			),
			array( 'id' => $synced_id ),
			array( '%d', '%d', '%s' )
		);
		// Brand new insert on the sync table
	} else {
		$successful_update = $wpdb->insert(
			$table,
			array(
				'zbs_contact_id'         => $zbs_contact_id,
				'mailpoet_subscriber_id' => $mailpoet_subscriber_id,
				'last_status'            => $status,
			),
			array( '%d', '%d', '%s' )
		);
	}

	if ( ! $successful_update ) {
		echo "Error updating sync table. The MailPoet subscriber with ID $mailpoet_subscriber_id might not have been synced succesfully.";
	}
}

/**
 * Renders a paginated table with MailPoet subscribers and their synced status
 */
function render_paginated_table( $query ) {
	global $wpdb;

	$pending_to_sync = get_option( 'jpcrm_mailpoet_sync_pending' );
	$btn_disabled = ( empty( $pending_to_sync ) || $pending_to_sync == '0' ) ? '' : 'disabled';

	echo '<div class="mailpoet-sync-wrapper">';
	echo '<h1>MailPoet - Jetpack CRM Sync Tool</h1>';

	echo '<form method="POST" class="mailpoet-sync-btn-wrapper">';
	echo '<input type="hidden" name="startsync" value="1">';
	echo '<button id="btnSync" type="submit" ' . $btn_disabled . ' class="button button-primary mailpoet-sync-btn">Start Sync</button>';
	echo '<script>';
	echo 'document.querySelector("#btnSync").addEventListener("click", () => { document.querySelector("#syncMessage").style.display = "block" })';
	echo '</script>';
	echo '</form>';

	// Query for pagination
	$total_query = "SELECT COUNT(1) FROM (${query}) AS combined_table";
	$total       = $wpdb->get_var( $total_query );
	$items_per_page = 25;
	$page           = isset( $_GET['cpage'] ) ? abs( (int) $_GET['cpage'] ) : 1;
	$offset         = ( $page * $items_per_page ) - $items_per_page;

	$final_query = "{$query} ORDER BY synced_id LIMIT ${offset}, ${items_per_page}";
	$subscribers = $wpdb->get_results( $final_query );

	if ( ! empty( $pending_to_sync ) && $pending_to_sync !== '0' ) {
		$missing = count_pending_sync();
		echo "<p>Pending to sync $missing out of $pending_to_sync subscribers <br>Refresh the page to see latest status.</p>";
	} else {
		echo '<p>Pending to sync: ' . count_pending_sync() . '</p>';
	}
	
	echo '<p><em>Unsynced contacts are displayed first.</em></p>';
	echo '<table class="wp-list-table widefat striped table-view-list mailpoet-subscribers">';
	echo '<tr>';
	echo '<th>Synced</th>';
	echo '<th>Email</th>';
	echo '<th>First Name</th>';
	echo '<th>Last Name</th>';
	echo '<th>Status</th>';
	echo '</tr>';

	foreach ( $subscribers as $subscriber ) :
		$synced = $subscriber->synced_id ? '✓ Yes' : '× No';
		echo '<tr>';
		echo "<td>{$synced}</td>";
		echo "<td>{$subscriber->email}</td>";
		echo "<td>{$subscriber->first_name}</td>";
		echo "<td>{$subscriber->last_name}</td>";
		echo "<td>{$subscriber->status}</td>";
		echo '</tr>';
	endforeach;

	echo '</table>';

	echo '<div class="tablenav-pages">';
	echo "{$total} items in total";
	echo '<div class="pagination-links">';
	echo paginate_links(
		array(
			'base'      => add_query_arg( 'cpage', '%#%' ),
			'format'    => '',
			'prev_text' => __( '&laquo;' ),
			'next_text' => __( '&raquo;' ),
			'total'     => ceil( $total / $items_per_page ),
			'current'   => $page,
		)
	);
	echo '</div>'; // pagination-links.
	echo '</div>'; // tablenav-pages.
	echo '</div>'; // mailpoet-sync-wrapper.
}

/**
 * count_pending_sync
 * Helper function that counts how many MailPoet subscribers are still
 * missing to sync.
 * @return string total
 */
function count_pending_sync() {
	global $wpdb;
	$query = 'SELECT COUNT(s.id) as total
		FROM ' . $wpdb->prefix . 'mailpoet_subscribers AS s 
		LEFT OUTER JOIN ' . $wpdb->prefix . 'zbs_mailpoet_sync AS mps 
		ON s.id = mps.mailpoet_subscriber_id
		WHERE mps.id IS NULL OR s.status COLLATE utf8mb4_unicode_ci != mps.last_status';
	$result = $wpdb->get_results( $query );
	return $result[0]->total;
}
