<?php

namespace WordCamp\Budgets_Dashboard\Sponsor_Invoices;
use \WordCamp\Logger;

defined( 'WPINC' ) or die();

const LATEST_DATABASE_VERSION = 3;

if ( defined( 'DOING_AJAX' ) ) {
	add_action( 'wp_ajax_wcbdsi_approve_invoice', __NAMESPACE__ . '\handle_approve_invoice_request'       );
	add_action( 'save_post',                      __NAMESPACE__ . '\update_index_row',              10, 2 );

} elseif ( defined( 'DOING_CRON' ) ) {
	add_action( 'wcbdsi_check_for_paid_invoices', __NAMESPACE__ . '\check_for_paid_invoices'       );
	add_action( 'save_post',                      __NAMESPACE__ . '\update_index_row',       10, 2 );

} elseif ( is_network_admin() ) {
	add_action( 'plugins_loaded',        __NAMESPACE__ . '\schedule_cron_events'  );
	add_action( 'network_admin_menu',    __NAMESPACE__ . '\register_submenu_page' );
	add_action( 'init',                  __NAMESPACE__ . '\upgrade_database'      );

} elseif ( is_admin() ) {
	add_action( 'save_post',    __NAMESPACE__ . '\update_index_row', 11, 2 );   // See note in callback about priority
	add_action( 'trashed_post', __NAMESPACE__ . '\delete_index_row'        );
	add_action( 'delete_post',  __NAMESPACE__ . '\delete_index_row'        );
}

/**
 * Schedule cron job when plugin is activated
 */
function schedule_cron_events() {
	if ( wp_next_scheduled( 'wcbdsi_check_for_paid_invoices' ) ) {
		return;
	}

	wp_schedule_event( current_time( 'timestamp' ), 'hourly', 'wcbdsi_check_for_paid_invoices' );
}

/**
 * Register the admin page
 */
function register_submenu_page() {
	$hook_suffix = add_submenu_page(
		'wordcamp-budgets-dashboard',
		'WordCamp Sponsor Invoices',
		'Sponsor Invoices',
		'manage_network',
		'sponsor-invoices-dashboard',
		__NAMESPACE__ . '\render_submenu_page'
	);

	add_action( "admin_print_scripts-$hook_suffix", __NAMESPACE__ . '\enqueue_scripts' );
}

/**
 * Render the admin page
 */
function render_submenu_page() {
	require_once( __DIR__ . '/sponsor-invoices-list-table.php' );

	$list_table = new Sponsor_Invoices_List_Table();
	$sections   = get_submenu_page_sections();

	$list_table->prepare_items();

	switch ( get_current_section() ) {
		case 'submitted':
			$section_explanation = 'These invoices have been completed by the organizer, but need to be approved by a deputy before being sent to the sponsor.';
			break;

		case 'approved':
			$section_explanation = "These invoices have been approved by a deputy and sent to the sponsor, but haven't been paid yet.";
			break;

		case 'paid':
			$section_explanation = 'These invoices have been paid by the sponsor.';
			break;
	}

	require_once( dirname( __DIR__ ) . '/views/sponsor-invoices/page-sponsor-invoices.php' );
}

/**
 * Get the current section
 *
 * @return string
 */
function get_current_section() {
	$sections        = array( 'submitted', 'approved', 'paid' );
	$current_section = 'submitted';

	if ( isset( $_GET['section'] ) && in_array( $_GET['section'], $sections, true ) ) {
		$current_section = $_GET['section'];
	}

	return $current_section;
}

/**
 * Get all the data needed to render the section tabs
 *
 * @return array
 */
function get_submenu_page_sections() {
	$statuses        = \WordCamp\Budgets\Sponsor_Invoices\get_custom_statuses();
	$sections        = array();
	$current_section = get_current_section();

	foreach ( $statuses as $status_slug => $status_name ) {
		$status_slug = str_replace( 'wcbsi_', '', $status_slug );    // make the URL easier to read

		$classes = 'nav-tab';
		if ( $status_slug === $current_section ) {
			$classes .= ' nav-tab-active';
		}

		$href = add_query_arg(
			array(
				'page'    => 'sponsor-invoices-dashboard',
				'section' => $status_slug,
			),
			network_admin_url( 'admin.php' )
		);

		$sections[ $status_slug ] = array(
			'classes' => $classes,
			'href'    => $href,
			'text'    => $status_name,
		);
	}

	return $sections;
}

/**
 * Enqueue JavaScript and CSS files
 */
function enqueue_scripts() {
	wp_enqueue_script(
		'wcbd-sponsor-invoices',
		plugins_url( 'javascript/sponsor-invoices.js', __DIR__ ),
		array( 'jquery', 'underscore' ),
		1,
		true
	);
}

/**
 * Create or update the database tables
 */
function upgrade_database() {
	global $wpdb;

	$current_database_version = get_site_option( 'wcbdsi_database_version', 0 );

	if ( version_compare( $current_database_version, LATEST_DATABASE_VERSION, '>=' ) ) {
		return;
	}

	$table_name = get_index_table_name();
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

	$schema = "
		CREATE TABLE $table_name (
			blog_id        int( 11 )        unsigned NOT NULL default '0',
			invoice_id     int( 11 )        unsigned NOT NULL default '0',
			qbo_invoice_id int( 11 )        unsigned NOT NULL default '0',
			invoice_title  varchar( 75 )             NOT NULL default '',
			status         varchar( 30 )             NOT NULL default '',
			wordcamp_name  varchar( 75 )             NOT NULL default '',
			sponsor_name   varchar( 75 )             NOT NULL default '',
			description    varchar( 75 )             NOT NULL default '',
			currency       varchar( 3  )             NOT NULL default '',
			due_date       int( 11 )        unsigned NOT NULL default '0',
			amount         numeric( 10, 2 ) unsigned NOT NULL default '0',

			PRIMARY KEY (blog_id, invoice_id),
			KEY status (status)
		)
		DEFAULT CHARACTER SET {$wpdb->charset}
		COLLATE {$wpdb->collate};
	";

	dbDelta( $schema );

	update_site_option( 'wcbdsi_database_version', LATEST_DATABASE_VERSION );
}

/**
 * Returns the name of the custom table.
 */
function get_index_table_name() {
	global $wpdb;

	return $wpdb->get_blog_prefix( 0 ) . 'wcbd_sponsor_invoice_index';
}

/**
 * Handle an AJAX request to approve an invoice
 */
function handle_approve_invoice_request() {
	$required_parameters = array( 'action', 'nonce', 'site_id', 'invoice_id' );

	foreach ( $required_parameters as $parameter ) {
		if ( empty( $_REQUEST[ $parameter ] ) ) {
			wp_send_json_error( array( 'error' => 'Required parameters not set.' ) );
		}
	}

	$site_id    = absint( $_REQUEST['site_id'] );
	$invoice_id = absint( $_REQUEST['invoice_id'] );

	if ( ! wp_verify_nonce( $_REQUEST['nonce'], "wcbdsi-approve-invoice-$site_id-$invoice_id" ) || ! current_user_can( 'manage_network' ) ) {
		wp_send_json_error( array( 'error' => 'Permission denied.' ) );
	}

	switch_to_blog( $site_id );

	$quickbooks_result = \WordCamp_QBO_Client::send_invoice_to_quickbooks( $invoice_id );
	Logger\log( 'send_invoice', compact( 'invoice_id', 'quickbooks_result' ) );

	if ( is_int( $quickbooks_result ) ) {
		update_post_meta( $invoice_id, '_wcbsi_qbo_invoice_id', absint( $quickbooks_result ) );
		update_invoice_status(           $site_id, $invoice_id, 'approved' );
		notify_organizer_status_changed( $site_id, $invoice_id, 'approved' );

		$result = array( 'success' => 'The invoice has been approved and e-mailed to the sponsor.' );
	} else {
		$result = array( 'error' => $quickbooks_result );
	}

	restore_current_blog();

	if ( isset( $result['success'] ) ) {
		wp_send_json_success( $result );
	} else {
		wp_send_json_error( $result );
	}
}

/**
 * Send a request to QuickBooks to check if any sent invoices have been paid
 *
 * If any have been, update the status of the local copy, and notify the organizer who sent the invoice.
 */
function check_for_paid_invoices() {
	global $wpdb;

	$table_name = get_index_table_name();
	$paid_invoices = array();

	$sent_invoices = $wpdb->get_results( "
		SELECT blog_id, invoice_id, qbo_invoice_id
		FROM $table_name
		WHERE status = 'wcbsi_approved'
		LIMIT 1000
	" );

	// Batch requests in order to avoid request size limits imposed by QuickBooks, nginx, etc
	$qbo_invoice_ids = wp_list_pluck( $sent_invoices, 'qbo_invoice_id' );
	$qbo_invoice_ids = array_chunk( $qbo_invoice_ids, 20 );

	foreach( $qbo_invoice_ids as $batch ) {
		$paid_invoices = array_merge(
			$paid_invoices,
			\WordCamp_QBO_Client::get_paid_invoices( $batch )
		);

		usleep( 300000 );   // Avoid hitting the API too frequently
	}

	mark_invoices_as_paid( $sent_invoices, $paid_invoices );
}

/**
 * Mark WordCamp.org invoices as paid when they've been paid in QuickBooks
 *
 * @param array $sent_invoices
 * @param array $paid_invoices
 */
function mark_invoices_as_paid( $sent_invoices, $paid_invoices ) {
	foreach ( $sent_invoices as $invoice ) {
		if ( in_array( (int) $invoice->qbo_invoice_id, $paid_invoices, true ) ) {
			update_invoice_status(           $invoice->blog_id, $invoice->invoice_id, 'paid' );
			notify_organizer_status_changed( $invoice->blog_id, $invoice->invoice_id, 'paid' );
		}
	}
}

/**
 * Update the status of an invoice
 *
 * @param int    $site_id
 * @param int    $invoice_id
 * @param string $new_status
 */
function update_invoice_status( $site_id, $invoice_id, $new_status ) {
	switch_to_blog( $site_id );

	// Disable the functions that run during a normal save, because they'd interfere with this
	remove_filter( 'wp_insert_post_data', 'WordCamp\Budgets\Sponsor_Invoices\set_invoice_status', 10 );
	remove_action( 'save_post',           'WordCamp\Budgets\Sponsor_Invoices\save_invoice',       10 );

	wp_update_post(
		array(
			'ID'          => $invoice_id,
			'post_status' => "wcbsi_$new_status",
		),
		true
	);

	restore_current_blog();
}

/**
 * Notify the organizer when the status of their invoice changes
 *
 * @param int    $site_id
 * @param int    $invoice_id
 * @param string $new_status
 */
function notify_organizer_status_changed( $site_id, $invoice_id, $new_status ) {
	switch_to_blog( $site_id );

	$invoice            = get_post( $invoice_id );
	$to                 = \WordCamp_Budgets::get_requester_formatted_email( $invoice->post_author );
	$subject            = "Invoice for {$invoice->post_title} $new_status";
	$sponsor_name       = get_sponsor_name( $invoice_id );
	$invoice_url        = admin_url( sprintf( 'post.php?post=%s&action=edit', $invoice_id ) );
	$headers            = array( 'Reply-To: support@wordcamp.org' );
	$attachments        = array();
	$attachment_message = '';
	$invoice_filename   = false;

	if ( 'approved' === $new_status ) {
		$sponsor_id       = get_post_meta( $invoice_id, '_wcbsi_sponsor_id',           true );
		$sponsor_email    = get_post_meta( $sponsor_id, '_wcpt_sponsor_email_address', true );
		$qbo_invoice_id   = get_post_meta( $invoice_id, '_wcbsi_qbo_invoice_id',       true );
		$status_message   = "has been sent to $sponsor_name via $sponsor_email. You will receive another notification when they have paid the invoice.";
		$invoice_filename = \WordCamp_QBO_Client::get_invoice_filename( $qbo_invoice_id );

		Logger\log( 'get_invoice_filename', compact( 'qbo_invoice_id', 'invoice_filename' ) );

		if ( ! is_wp_error( $invoice_filename ) ) {
			$attachments[]      = $invoice_filename;
			$attachment_message = "\nA copy of the invoice has been attached to this message, in case you need to follow up with the sponsor.";
		}
	} elseif ( 'paid' === $new_status ) {
		$status_message = "has been paid by $sponsor_name. Go ahead and publish them to your website!";
	} else {
		restore_current_blog();
		return;
	}

	$message = str_replace( "\t", '', "
		The invoice for `{$invoice->post_title}` $status_message

		You can view the invoice and its status any time at:

		$invoice_url
		$attachment_message

		If you have any questions, please reply to let us know."
	);

	wp_mail( $to, $subject, $message, $headers, $attachments );

	if ( $invoice_filename ) {
		unlink( $invoice_filename );
	}

	restore_current_blog();
}

/**
 * Get the name of the sponsor for a given invoice
 *
 * NOTE: This must be called from inside a switch_to_blog() context.
 *
 * @param int $invoice_id
 *
 * @return string
 */
function get_sponsor_name( $invoice_id ) {
	$sponsor_name = '';
	$sponsor_id   = get_post_meta( $invoice_id, '_wcbsi_sponsor_id',  true );
	$sponsor      = get_post( $sponsor_id );

	if ( is_a( $sponsor, 'WP_Post' ) ) {
		$sponsor_name = $sponsor->post_title;
	}

	return $sponsor_name;
}

/**
 * Add or update a row in the index
 *
 * NOTE: This must run after \WordCamp\Budgets\Sponsor_Invoices\save_invoice(), because otherwise the
 * get_post_meta() calls would be fetching the old data, rather than the latest from the current process.
 *
 * @param int      $invoice_id
 * @param \WP_Post $invoice
 */
function update_index_row( $invoice_id, $invoice ) {
	global $wpdb;

	if ( \WordCamp\Budgets\Sponsor_Invoices\POST_TYPE !== $invoice->post_type ) {
		return;
	}

	// Drafts, etc aren't displayed in the list table, so there's no reason to index them
	$ignored_statuses = array( 'auto-draft', 'draft', 'trash' );

	if ( in_array( $invoice->post_status, $ignored_statuses, true ) ) {
		return;
	}

	// todo use post_edit_is_actionable instead?

	$index_row = array(
		'blog_id'        => get_current_blog_id(),
		'invoice_id'     => $invoice_id,
		'qbo_invoice_id' => get_post_meta( $invoice_id, '_wcbsi_qbo_invoice_id', true ),
		'invoice_title'  => substr( $invoice->post_title, 0, 75 ),
		'status'         => $invoice->post_status,
		'wordcamp_name'  => get_wordcamp_name(),
		'sponsor_name'   => substr( get_sponsor_name( $invoice_id ), 0, 75 ),
		'description'    => get_post_meta( $invoice_id, '_wcbsi_description', true ),
		'currency'       => get_post_meta( $invoice_id, '_wcbsi_currency',    true ),
		'due_date'       => 0,  // todo remove this field from index
		'amount'         => get_post_meta( $invoice_id, '_wcbsi_amount',      true ),
	);

	$formats = array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%f' );

	$wpdb->replace( get_index_table_name(), $index_row, $formats );
}

/**
 * Delete a row from the index
 *
 * @param int $invoice_id
 */
function delete_index_row( $invoice_id ) {
	global $wpdb;

	/*
	 * Normally we would check if $invoice_id is from the kind of post type we want, but that's not necessary in
	 * this case, because only invoices are added to the index to begin with. If $invoice_id is from some other
	 * post type, then $wpdb->delete() will return false with no negative consequences. That's quicker than having
	 * to query for the $invoice post in order to check the post type.
	 */

	$wpdb->delete(
		get_index_table_name(),
		array(
			'blog_id'    => get_current_blog_id(),
			'invoice_id' => $invoice_id,
		)
	);
}
