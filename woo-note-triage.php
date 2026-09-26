<?php
/**
 * Plugin Name:       Note Triage for WooCommerce
 * Plugin URI:        https://github.com/hamzaahmadaslam/woo-note-triage
 * Description:       Sorts the notes customers write at checkout into gift messages, delivery instructions, questions, complaints and fraud signals, and ranks them by urgency, with TypeSafe's Jev model. Confident answers become a private order note and an orders list filter; the rest wait for review.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Hamza Ahmad Aslam
 * Author URI:        https://hamzaahmadaslam.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       woo-note-triage
 * WC requires at least: 8.0
 *
 * @package Woo_Note_Triage
 */

defined( 'ABSPATH' ) || exit;

define( 'WOO_NOTE_TRIAGE_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-meta.php';
require_once __DIR__ . '/includes/class-note-text.php';
require_once __DIR__ . '/includes/class-questions.php';
require_once __DIR__ . '/includes/class-decision.php';
require_once __DIR__ . '/includes/class-jev-error.php';
require_once __DIR__ . '/includes/class-jev-client.php';
require_once __DIR__ . '/includes/class-report.php';
require_once __DIR__ . '/includes/class-wp-transport.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-triage.php';
require_once __DIR__ . '/includes/class-orders-list.php';
require_once __DIR__ . '/includes/class-cli.php';
require_once __DIR__ . '/includes/class-plugin.php';

Woo_Note_Triage\Plugin::boot();
