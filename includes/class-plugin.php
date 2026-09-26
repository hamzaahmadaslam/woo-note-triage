<?php
/**
 * Starts the plugin: compatibility declarations, hooks and the WP-CLI command.
 *
 * @package Woo_Note_Triage
 */

namespace Woo_Note_Triage;

defined( 'ABSPATH' ) || exit;

/**
 * Wiring only. The work happens in Triage, Settings, Orders_List and CLI.
 */
final class Plugin {

	/** Must match the Version header in woo-note-triage.php (a test checks). */
	public const VERSION = '1.0.0';

	/**
	 * Registers the hooks that run before WooCommerce loads.
	 */
	public static function boot(): void {
		add_action( 'before_woocommerce_init', array( self::class, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( self::class, 'init' ) );
		register_deactivation_hook( WOO_NOTE_TRIAGE_FILE, array( self::class, 'deactivate' ) );
	}

	/**
	 * Tells WooCommerce the plugin works with HPOS order storage and with the cart and checkout blocks. The plugin
	 * reads and writes orders only through the order object, as the HPOS extension recipe book asks:
	 * https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/
	 */
	public static function declare_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WOO_NOTE_TRIAGE_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WOO_NOTE_TRIAGE_FILE, true );
		}
	}

	/**
	 * Hooks everything in once WooCommerce is loaded. Does nothing without WooCommerce.
	 */
	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$triage = new Triage();
		$triage->register();
		if ( is_admin() ) {
			( new Settings() )->register();
			( new Orders_List() )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'woo-note-triage', new CLI( $triage ) );
		}
	}

	/**
	 * Removes queued jobs when the plugin is deactivated. Saved results on orders stay.
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Triage::HOOK );
		}
	}
}
