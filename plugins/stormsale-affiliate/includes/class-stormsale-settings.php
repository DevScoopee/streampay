<?php

final class StormSale_Settings {

	const OPTION = 'stormsale_settings';

	const GROUP = 'stormsale';

	const PAGE = 'stormsale';

	const CAMPAIGN_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$/';

	const API_KEY_MAX_LENGTH = 512;

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	public function add_page() {
		add_submenu_page(
			'woocommerce',
			__( 'StormSale', 'stormsale-affiliate' ),
			__( 'StormSale', 'stormsale-affiliate' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function register_setting() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function defaults() {
		return array(
			'campaign_id' => '',
			'api_key'     => '',
		);
	}

	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array_merge( self::defaults(), $stored );

		return array(
			'campaign_id' => (string) $settings['campaign_id'],
			'api_key'     => (string) $settings['api_key'],
		);
	}

	public static function get( $key ) {
		$settings = self::all();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : '';
	}

	public static function is_configured() {
		return '' !== self::get( 'campaign_id' ) && '' !== self::get( 'api_key' );
	}

	public static function sanitize( $input ) {
		$current = self::all();
		$output  = self::defaults();

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$campaign = isset( $input['campaign_id'] )
			? sanitize_text_field( wp_unslash( (string) $input['campaign_id'] ) )
			: '';

		if ( '' === $campaign ) {
			$campaign = $current['campaign_id'];
		} elseif ( ! preg_match( self::CAMPAIGN_PATTERN, $campaign ) ) {
			add_settings_error(
				self::OPTION,
				'stormsale_invalid_campaign',
				__( 'Campaign ID must be 3-64 characters using only letters, numbers, dots, dashes or underscores.', 'stormsale-affiliate' ),
				'error'
			);
			$campaign = $current['campaign_id'];
		}

		$output['campaign_id'] = $campaign;

		$api_key = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		$api_key = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $api_key );

		if ( '' === $api_key ) {
			$api_key = $current['api_key'];
		} elseif ( strlen( $api_key ) > self::API_KEY_MAX_LENGTH ) {
			add_settings_error(
				self::OPTION,
				'stormsale_invalid_api_key',
				__( 'API key must be 512 characters or fewer.', 'stormsale-affiliate' ),
				'error'
			);
			$api_key = $current['api_key'];
		}

		$output['api_key'] = $api_key;

		return apply_filters( 'stormsale_sanitized_settings', $output, $input );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'stormsale-affiliate' ) );
		}

		$settings = self::all();
		$stats    = StormSale_Stats::load();
		$codes    = $stats['codes'];
		arsort( $codes );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( __( 'StormSale', 'stormsale-affiliate' ) ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="stormsale_campaign_id"><?php echo esc_html( __( 'Campaign ID', 'stormsale-affiliate' ) ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="stormsale_campaign_id"
								class="regular-text"
								name="<?php echo esc_attr( self::OPTION ); ?>[campaign_id]"
								value="<?php echo esc_attr( $settings['campaign_id'] ); ?>"
								placeholder="cmp_winter25"
								pattern="[A-Za-z0-9][A-Za-z0-9._-]{2,63}"
								autocomplete="off"
							/>
							<p class="description">
								<?php echo esc_html( __( 'The StormSale smart campaign that conversions are attributed to.', 'stormsale-affiliate' ) ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="stormsale_api_key"><?php echo esc_html( __( 'API Key', 'stormsale-affiliate' ) ); ?></label>
						</th>
						<td>
							<input
								type="password"
								id="stormsale_api_key"
								class="regular-text"
								name="<?php echo esc_attr( self::OPTION ); ?>[api_key]"
								value="<?php echo esc_attr( $settings['api_key'] ); ?>"
								autocomplete="new-password"
								spellcheck="false"
							/>
							<p class="description">
								<?php echo esc_html( __( 'Used to encrypt and sign every order payload sent to the StormSale gateway. Leave blank to keep the saved key.', 'stormsale-affiliate' ) ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'stormsale-affiliate' ) ); ?>
			</form>

			<h2><?php echo esc_html( __( 'Referral clicks', 'stormsale-affiliate' ) ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						__( 'Tracked referral clicks: %d', 'stormsale-affiliate' ),
						(int) $stats['total']
					)
				);
				?>
			</p>
			</p>

			<?php if ( empty( $codes ) ) : ?>
				<p><?php echo esc_html( __( 'No referral clicks recorded yet.', 'stormsale-affiliate' ) ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width: 480px;">
					<thead>
						<tr>
							<th><?php echo esc_html( __( 'Referral code', 'stormsale-affiliate' ) ); ?></th>
							<th><?php echo esc_html( __( 'Clicks', 'stormsale-affiliate' ) ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $codes as $code => $count ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $code ); ?></td>
								<td><?php echo esc_html( (string) $count ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
