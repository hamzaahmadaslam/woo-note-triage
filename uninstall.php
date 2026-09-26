<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: removes its settings, its saved API key and its
 * queued jobs. The results already recorded on orders (order meta and private order notes) stay, because they
 * are part of each order's history.
 *
 * @package Woo_Note_Triage
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woo_note_triage_settings' );
delete_option( 'woo_note_triage_api_key' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'woo_note_triage_run' );
}
