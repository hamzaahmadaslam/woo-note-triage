<?php
/**
 * The settings page (WooCommerce > Note triage) and the stored settings.
 *
 * @package Woo_Note_Triage
 */

namespace Woo_Note_Triage;

defined( 'ABSPATH' ) || exit;

/**
 * Settings: on or off, the confidence threshold, the model, and the API key.
 *
 * The key is stored in its own option, which is not autoloaded, and is never printed again: the page shows only
 * its last four characters. A TYPESAFE_API_KEY constant (in wp-config.php) or environment variable takes
 * precedence over the stored key.
 */
final class Settings {

	/** Option holding enabled, threshold and model. */
	public const OPTION = 'woo_note_triage_settings';

	/** Option holding the API key. */
	public const KEY_OPTION = 'woo_note_triage_api_key';

	/** The settings page slug. */
	public const PAGE = 'woo-note-triage';

	/** The admin-post action and nonce action for saving. */
	public const ACTION = 'woo_note_triage_save';

	/** Who may see and change the settings. */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Settings before anything is saved.
	 *
	 * @return array{enabled: bool, threshold: float, model: string}
	 */
	public static function defaults(): array {
		return array(
			'enabled'   => true,
			'threshold' => Decision::DEFAULT_THRESHOLD,
			'model'     => Jev_Client::DEFAULT_MODEL,
		);
	}

	/**
	 * Turns stored or submitted values into valid settings. Pure.
	 *
	 * @param array $input enabled, threshold, model; missing values take their defaults.
	 * @return array{enabled: bool, threshold: float, model: string}
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();
		$model    = self::parse_model( $input['model'] ?? '' );
		return array(
			'enabled'   => array_key_exists( 'enabled', $input ) ? (bool) $input['enabled'] : $defaults['enabled'],
			'threshold' => Decision::parse_threshold( $input['threshold'] ?? null, $defaults['threshold'] ),
			'model'     => '' !== $model ? $model : $defaults['model'],
		);
	}

	/**
	 * A model name such as "jev-latest" or "jev-1.13.0", or an empty string when the value is not one. Pure.
	 *
	 * @param mixed $value Typed or stored value.
	 */
	public static function parse_model( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $value ) ? $value : '';
	}

	/**
	 * An API key as typed: whitespace removed; empty when it is not 8 to 512 printable characters. Pure.
	 *
	 * @param mixed $value Typed value.
	 */
	public static function parse_key( $value ): string {
		$value = is_string( $value ) ? (string) preg_replace( '/\s+/', '', $value ) : '';
		return 1 === preg_match( '/^[\x21-\x7E]{8,512}$/', $value ) ? $value : '';
	}

	/**
	 * The saved settings.
	 *
	 * @return array{enabled: bool, threshold: float, model: string}
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return self::sanitize( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Where the key comes from: "constant", "environment", "settings", or "" when there is none.
	 */
	public static function key_source(): string {
		if ( defined( 'TYPESAFE_API_KEY' ) && is_string( constant( 'TYPESAFE_API_KEY' ) ) && '' !== trim( constant( 'TYPESAFE_API_KEY' ) ) ) {
			return 'constant';
		}
		$env = getenv( 'TYPESAFE_API_KEY' );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return 'environment';
		}
		$saved = get_option( self::KEY_OPTION, '' );
		return is_string( $saved ) && '' !== trim( $saved ) ? 'settings' : '';
	}

	/**
	 * The API key, or an empty string.
	 */
	public static function api_key(): string {
		switch ( self::key_source() ) {
			case 'constant':
				return trim( (string) constant( 'TYPESAFE_API_KEY' ) );
			case 'environment':
				return trim( (string) getenv( 'TYPESAFE_API_KEY' ) );
			case 'settings':
				return trim( (string) get_option( self::KEY_OPTION, '' ) );
			default:
				return '';
		}
	}

	/**
	 * The model: a TYPESAFE_MODEL constant or environment variable when set, otherwise the setting.
	 */
	public static function model(): string {
		if ( defined( 'TYPESAFE_MODEL' ) && '' !== self::parse_model( constant( 'TYPESAFE_MODEL' ) ) ) {
			return self::parse_model( constant( 'TYPESAFE_MODEL' ) );
		}
		$env = getenv( 'TYPESAFE_MODEL' );
		if ( is_string( $env ) && '' !== self::parse_model( $env ) ) {
			return self::parse_model( $env );
		}
		return self::get()['model'];
	}

	/**
	 * Hooks the page into the admin.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WOO_NOTE_TRIAGE_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds WooCommerce > Note triage.
	 */
	public function add_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Customer note triage', 'woo-note-triage' ),
			__( 'Note triage', 'woo-note-triage' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param mixed $links Existing links.
	 * @return mixed
	 */
	public function action_links( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}
		$url = add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'woo-note-triage' ) . '</a>' );
		return $links;
	}

	/**
	 * Prints the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'woo-note-triage' ), '', array( 'response' => 403 ) );
		}
		$settings = self::get();
		$source   = self::key_source();
		$saved    = get_option( self::KEY_OPTION, '' );
		$saved    = is_string( $saved ) ? trim( $saved ) : '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flags set by our own redirect after a nonce-checked save.
		$updated   = isset( $_GET['updated'] );
		$key_error = isset( $_GET['key_error'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$actions_url = add_query_arg(
			array(
				'page' => 'wc-status',
				'tab'  => 'action-scheduler',
				's'    => Triage::HOOK,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Customer note triage', 'woo-note-triage' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'woo-note-triage' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $key_error ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'The API key was not saved: a key is 8 to 512 letters, digits and symbols, with no accented characters.', 'woo-note-triage' ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'When a customer writes a note at checkout, this plugin asks TypeSafe\'s Jev model two questions about it: what kind of note it is (gift message, delivery instruction, question, complaint, fraud signal or other) and how soon the shop needs to act. Orders where Jev is confident get a private order note and a category you can filter by on the Orders screen. The rest are listed under "Needs review". The plugin never changes an order\'s status and never contacts the customer.', 'woo-note-triage' ); ?></p>
			<p><?php esc_html_e( 'What is sent to api.typesafe.ai: your key, the model name, the note text and the two questions, nothing else. Email addresses and numbers of nine or more digits in the note are replaced first, and at most 2,000 characters are sent. No name, address, email, order total or site address is sent.', 'woo-note-triage' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'New orders', 'woo-note-triage' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="woo_note_triage[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>>
								<?php esc_html_e( 'Triage the customer note of every new order', 'woo-note-triage' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="woo-note-triage-key"><?php esc_html_e( 'TypeSafe API key', 'woo-note-triage' ); ?></label></th>
						<td>
							<?php if ( 'constant' === $source || 'environment' === $source ) : ?>
								<p>
									<?php
									echo esc_html(
										'constant' === $source
											? __( 'The key comes from the TYPESAFE_API_KEY constant (usually in wp-config.php).', 'woo-note-triage' )
											: __( 'The key comes from the TYPESAFE_API_KEY environment variable.', 'woo-note-triage' )
									);
									?>
								</p>
								<?php if ( '' !== $saved ) : ?>
									<p class="description"><?php esc_html_e( 'A key saved here earlier is still in the database, but it is not used while this one is set.', 'woo-note-triage' ); ?></p>
								<?php endif; ?>
							<?php else : ?>
								<input type="password" id="woo-note-triage-key" name="woo_note_triage_key" value="" class="regular-text" autocomplete="new-password" spellcheck="false">
								<?php if ( '' !== $saved ) : ?>
									<p class="description">
										<?php
										echo esc_html(
											strlen( $saved ) >= 12
												/* translators: %s: the last four characters of the saved API key. */
												? sprintf( __( 'A key ending in %s is saved. Leave this field empty to keep it.', 'woo-note-triage' ), substr( $saved, -4 ) )
												: __( 'A key is saved. Leave this field empty to keep it.', 'woo-note-triage' )
										);
										?>
									</p>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'No key is saved yet. Nothing is sent until there is one. You can also define TYPESAFE_API_KEY in wp-config.php instead of saving it here.', 'woo-note-triage' ); ?></p>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ( '' !== $saved ) : ?>
								<p>
									<label>
										<input type="checkbox" name="woo_note_triage_remove_key" value="1">
										<?php esc_html_e( 'Remove the saved key', 'woo-note-triage' ); ?>
									</label>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="woo-note-triage-threshold"><?php esc_html_e( 'Confidence threshold', 'woo-note-triage' ); ?></label></th>
						<td>
							<input type="number" id="woo-note-triage-threshold" name="woo_note_triage[threshold]" value="<?php echo esc_attr( Decision::number( $settings['threshold'] ) ); ?>" min="0" max="1" step="0.01" class="small-text">
							<p class="description"><?php esc_html_e( 'From 0 to 1. An order whose category confidence is below this gets no category and no order note, and is listed under "Needs review" instead. The default is 0.80.', 'woo-note-triage' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="woo-note-triage-model"><?php esc_html_e( 'Model', 'woo-note-triage' ); ?></label></th>
						<td>
							<input type="text" id="woo-note-triage-model" name="woo_note_triage[model]" value="<?php echo esc_attr( $settings['model'] ); ?>" class="regular-text" spellcheck="false">
							<p class="description"><?php esc_html_e( 'jev-latest follows TypeSafe\'s current release. Name a version, such as jev-1.13.0, to keep answers steady after you tune the threshold.', 'woo-note-triage' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Older orders and errors', 'woo-note-triage' ); ?></h2>
			<p><?php esc_html_e( 'Orders placed before the plugin was active can be triaged from the command line: wp woo-note-triage backfill --days=30 --dry-run shows what would be sent, and the same command without --dry-run sends it.', 'woo-note-triage' ); ?></p>
			<p>
				<a href="<?php echo esc_url( $actions_url ); ?>"><?php esc_html_e( 'Queued requests are listed under Scheduled Actions.', 'woo-note-triage' ); ?></a>
				<?php esc_html_e( 'An order that could not be triaged shows "Not triaged" in the orders list, and the reason is in the WooCommerce log (source woo-note-triage).', 'woo-note-triage' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Saves the form. Checks the capability and the nonce first.
	 */
	public function save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'woo-note-triage' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		$input = isset( $_POST['woo_note_triage'] ) && is_array( $_POST['woo_note_triage'] ) ? wp_unslash( $_POST['woo_note_triage'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.
		$settings = self::sanitize(
			array(
				'enabled'   => ! empty( $input['enabled'] ),
				'threshold' => isset( $input['threshold'] ) && is_string( $input['threshold'] ) ? sanitize_text_field( $input['threshold'] ) : null,
				'model'     => isset( $input['model'] ) && is_string( $input['model'] ) ? sanitize_text_field( $input['model'] ) : '',
			)
		);
		update_option( self::OPTION, $settings, true );

		$args = array(
			'page'    => self::PAGE,
			'updated' => '1',
		);
		if ( ! empty( $_POST['woo_note_triage_remove_key'] ) ) {
			delete_option( self::KEY_OPTION );
		} elseif ( isset( $_POST['woo_note_triage_key'] ) && is_string( $_POST['woo_note_triage_key'] ) && '' !== trim( wp_unslash( $_POST['woo_note_triage_key'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked by parse_key().
			$key = self::parse_key( wp_unslash( $_POST['woo_note_triage_key'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parse_key() allows printable ASCII only.
			if ( '' !== $key ) {
				update_option( self::KEY_OPTION, $key, false );
			} else {
				$args['key_error'] = '1';
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
