<?php

class StormSale_Assertion_Failure extends Exception {
}

class WP_Error {

	private $code;

	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

class StormSale_Fake_Session {

	private $data = array();

	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
	}

	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
	}

	public function remove( $key ) {
		unset( $this->data[ $key ] );
	}

	public function all() {
		return $this->data;
	}
}

class StormSale_Fake_WC {

	public $session = null;

	public function __construct() {
		$this->session = new StormSale_Fake_Session();
	}
}

class StormSale_Fake_Order {

	public $meta = array();

	public $saves = 0;

	private $id;

	private $data;

	public function __construct( $id, array $data = array() ) {
		$this->id   = (int) $id;
		$this->data = array_merge(
			array(
				'order_key'     => 'wc_order_test' . $id,
				'status'        => 'processing',
				'currency'      => 'USD',
				'total'         => '19.99',
				'item_count'    => 3,
				'billing_email' => 'Buyer@Example.COM ',
				'payment_method' => 'cod',
				'order_number'  => (string) $id,
				'date_created'  => new DateTimeImmutable( '2026-10-01T10:00:00+00:00' ),
			),
			$data
		);
	}

	public function get_id() {
		return $this->id;
	}

	public function get_order_key() {
		return $this->data['order_key'];
	}

	public function get_order_number() {
		return $this->data['order_number'];
	}

	public function get_status() {
		return $this->data['status'];
	}

	public function get_currency() {
		return $this->data['currency'];
	}

	public function get_total() {
		return $this->data['total'];
	}

	public function get_item_count() {
		return $this->data['item_count'];
	}

	public function get_payment_method() {
		return $this->data['payment_method'];
	}

	public function get_billing_email() {
		return $this->data['billing_email'];
	}

	public function get_date_created() {
		return $this->data['date_created'];
	}

	public function get_meta( $key, $single = true ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	public function save() {
		$this->saves++;

		return $this->id;
	}
}

class StormSale_Fake_Logger {

	public function debug( $message, $context = array() ) {
		StormSale_Test_Env::log( 'debug', $message, $context );
	}

	public function info( $message, $context = array() ) {
		StormSale_Test_Env::log( 'info', $message, $context );
	}

	public function notice( $message, $context = array() ) {
		StormSale_Test_Env::log( 'notice', $message, $context );
	}

	public function warning( $message, $context = array() ) {
		StormSale_Test_Env::log( 'warning', $message, $context );
	}

	public function error( $message, $context = array() ) {
		StormSale_Test_Env::log( 'error', $message, $context );
	}

	public function critical( $message, $context = array() ) {
		StormSale_Test_Env::log( 'critical', $message, $context );
	}
}

class StormSale_Test_Env {

	public static $tests = array();

	public static $actions = array();

	public static $filters = array();

	public static $baseline_actions = array();

	public static $baseline_filters = array();

	public static $options = array();

	public static $remote_calls = array();

	public static $remote_queue = array();

	public static $orders = array();

	public static $scripts = array();

	public static $inline_scripts = array();

	public static $settings_errors = array();

	public static $registered_settings = array();

	public static $submenu_pages = array();

	public static $async_actions = array();

	public static $scheduled_actions = array();

	public static $activation_hooks = array();

	public static $logs = array();

	public static $wc = null;

	public static $async_queue = false;

	public static $ssl = false;

	public static $price_decimals = 2;

	public static $home_url = 'https://shop.example.test';

	public static $can_manage = true;

	public static function reset() {
		self::$actions          = self::$baseline_actions;
		self::$filters          = self::$baseline_filters;
		self::$options          = array();
		self::$remote_calls     = array();
		self::$remote_queue     = array();
		self::$orders           = array();
		self::$scripts          = array();
		self::$inline_scripts   = array();
		self::$settings_errors  = array();
		self::$registered_settings = array();
		self::$submenu_pages    = array();
		self::$async_actions    = array();
		self::$scheduled_actions = array();
		self::$logs             = array();
		self::$async_queue      = false;
		self::$ssl              = false;
		self::$price_decimals   = 2;
		self::$can_manage       = true;
		self::$wc               = new StormSale_Fake_WC();

		$_GET    = array();
		$_POST   = array();
		$_COOKIE = array();
	}

	public static function snapshot() {
		self::$baseline_actions = self::$actions;
		self::$baseline_filters = self::$filters;
	}

	public static function callback_id( $callback ) {
		if ( is_array( $callback ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];

			return $class . '::' . (string) $callback[1];
		}

		if ( is_string( $callback ) ) {
			return $callback;
		}

		if ( $callback instanceof Closure ) {
			return 'closure:' . spl_object_hash( $callback );
		}

		return gettype( $callback );
	}

	public static function add_listener( array &$store, $hook, $callback, $priority, $accepted_args ) {
		$sequence = isset( $store[ $hook ] ) ? count( $store[ $hook ] ) : 0;

		$store[ $hook ][] = array(
			'callback'      => $callback,
			'priority'      => (int) $priority,
			'accepted_args' => (int) $accepted_args,
			'id'            => self::callback_id( $callback ),
			'sequence'      => $sequence,
		);
	}

	public static function listeners( array $store, $hook ) {
		if ( ! isset( $store[ $hook ] ) ) {
			return array();
		}

		$listeners = $store[ $hook ];

		usort(
			$listeners,
			function ( $a, $b ) {
				if ( $a['priority'] === $b['priority'] ) {
					return $a['sequence'] - $b['sequence'];
				}

				return $a['priority'] - $b['priority'];
			}
		);

		return $listeners;
	}

	public static function find_priority( array $store, $hook, $callback ) {
		$id = self::callback_id( $callback );

		foreach ( self::listeners( $store, $hook ) as $listener ) {
			if ( $listener['id'] === $id ) {
				return $listener['priority'];
			}
		}

		return false;
	}

	public static function queue_response( $code, $body ) {
		self::$remote_queue[] = self::http_response( $code, $body );
	}

	public static function http_response( $code, $body ) {
		return array(
			'response' => array(
				'code'    => (int) $code,
				'message' => (int) $code >= 400 ? 'Error' : 'OK',
			),
			'body'     => is_string( $body ) ? $body : json_encode( $body ),
		);
	}

	public static function log( $level, $message, $context ) {
		self::$logs[] = array(
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);
	}

	public static function use_async_queue() {
		return (bool) self::$async_queue;
	}

	public static function run() {
		$passed   = 0;
		$failures = array();

		foreach ( self::$tests as $test ) {
			self::reset();

			try {
				call_user_func( $test['fn'] );
				$passed++;
				echo '.';
			} catch ( Throwable $e ) {
				$failures[] = $test['name'] . "\n    " . $e->getMessage();
				echo 'F';
			}
		}

		echo PHP_EOL;

		foreach ( $failures as $failure ) {
			echo PHP_EOL . 'FAIL: ' . $failure . PHP_EOL;
		}

		echo PHP_EOL . sprintf(
			'%d passed, %d failed (%d total)',
			$passed,
			count( $failures ),
			count( self::$tests )
		) . PHP_EOL;

		return empty( $failures ) ? 0 : 1;
	}
}

function ss_test( $name, $fn ) {
	StormSale_Test_Env::$tests[] = array(
		'name' => $name,
		'fn'   => $fn,
	);
}

function ss_fail( $message ) {
	throw new StormSale_Assertion_Failure( $message );
}

function ss_ok( $condition, $message = 'expected condition to be true' ) {
	if ( ! $condition ) {
		ss_fail( $message );
	}
}

function ss_not_ok( $condition, $message = 'expected condition to be false' ) {
	if ( $condition ) {
		ss_fail( $message );
	}
}

function ss_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		ss_fail(
			( '' !== $message ? $message . ' | ' : '' )
			. 'expected ' . var_export( $expected, true )
			. ', got ' . var_export( $actual, true )
		);
	}
}

function ss_contains( $haystack, $needle, $message = '' ) {
	if ( ! is_string( $haystack ) || false === strpos( $haystack, $needle ) ) {
		ss_fail(
			( '' !== $message ? $message . ' | ' : '' )
			. 'expected to find "' . $needle . '" in ' . var_export( $haystack, true )
		);
	}
}

function ss_not_contains( $haystack, $needle, $message = '' ) {
	if ( is_string( $haystack ) && false !== strpos( $haystack, $needle ) ) {
		ss_fail(
			( '' !== $message ? $message . ' | ' : '' )
			. 'did not expect to find "' . $needle . '" in ' . var_export( $haystack, true )
		);
	}
}

function ss_configure( $campaign_id = 'cmp_winter25', $api_key = 'sk_live_stormsale_abc123' ) {
	update_option(
		StormSale_Settings::OPTION,
		array(
			'campaign_id' => $campaign_id,
			'api_key'     => $api_key,
		)
	);
}

function ss_order( $id, array $data = array() ) {
	$order = new StormSale_Fake_Order( $id, $data );

	StormSale_Test_Env::$orders[ $order->get_id() ] = $order;

	return $order;
}

function ss_last_request() {
	$calls = StormSale_Test_Env::$remote_calls;

	return empty( $calls ) ? null : $calls[ count( $calls ) - 1 ];
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	StormSale_Test_Env::add_listener( StormSale_Test_Env::$actions, $hook, $callback, $priority, $accepted_args );

	return true;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	StormSale_Test_Env::add_listener( StormSale_Test_Env::$filters, $hook, $callback, $priority, $accepted_args );

	return true;
}

function has_action( $hook, $callback = false ) {
	if ( false === $callback ) {
		return ! empty( StormSale_Test_Env::$actions[ $hook ] );
	}

	return StormSale_Test_Env::find_priority( StormSale_Test_Env::$actions, $hook, $callback );
}

function has_filter( $hook, $callback = false ) {
	if ( false === $callback ) {
		return ! empty( StormSale_Test_Env::$filters[ $hook ] );
	}

	return StormSale_Test_Env::find_priority( StormSale_Test_Env::$filters, $hook, $callback );
}

function remove_action( $hook, $callback, $priority = 10 ) {
	$id = StormSale_Test_Env::callback_id( $callback );

	if ( isset( StormSale_Test_Env::$actions[ $hook ] ) ) {
		foreach ( StormSale_Test_Env::$actions[ $hook ] as $index => $listener ) {
			if ( $listener['id'] === $id && $listener['priority'] === (int) $priority ) {
				unset( StormSale_Test_Env::$actions[ $hook ][ $index ] );
			}
		}
	}

	return true;
}

function do_action( $hook ) {
	$args = func_get_args();
	array_shift( $args );

	foreach ( StormSale_Test_Env::listeners( StormSale_Test_Env::$actions, $hook ) as $listener ) {
		call_user_func_array(
			$listener['callback'],
			array_slice( $args, 0, $listener['accepted_args'] )
		);
	}
}

function apply_filters( $hook, $value = null ) {
	$args = func_get_args();
	array_shift( $args );

	foreach ( StormSale_Test_Env::listeners( StormSale_Test_Env::$filters, $hook ) as $listener ) {
		$call      = array_slice( $args, 0, $listener['accepted_args'] );
		$args[0] = call_user_func_array( $listener['callback'], $call );
	}

	return $args[0];
}

function get_option( $option, $default = false ) {
	return array_key_exists( $option, StormSale_Test_Env::$options )
		? StormSale_Test_Env::$options[ $option ]
		: $default;
}

function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $option, StormSale_Test_Env::$options ) ) {
		return false;
	}

	StormSale_Test_Env::$options[ $option ] = $value;

	return true;
}

function update_option( $option, $value, $autoload = null ) {
	$exists  = array_key_exists( $option, StormSale_Test_Env::$options );
	$changed = ! $exists || StormSale_Test_Env::$options[ $option ] !== $value;

	StormSale_Test_Env::$options[ $option ] = $value;

	return $changed;
}

function delete_option( $option ) {
	if ( ! array_key_exists( $option, StormSale_Test_Env::$options ) ) {
		return false;
	}

	unset( StormSale_Test_Env::$options[ $option ] );

	return true;
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}

	if ( is_string( $value ) ) {
		return stripslashes( $value );
	}

	return $value;
}

function sanitize_text_field( $value ) {
	$value = strip_tags( (string) $value );
	$value = preg_replace( '/[\r\n\t]+/', ' ', $value );

	return trim( preg_replace( '/\s{2,}/', ' ', $value ) );
}

function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $value, $domain = null ) {
	return esc_html( $value );
}

function esc_attr__( $value, $domain = null ) {
	return esc_attr( $value );
}

function __( $value, $domain = null ) {
	return (string) $value;
}

function current_user_can( $capability ) {
	return (bool) StormSale_Test_Env::$can_manage;
}

function wp_die( $message = '' ) {
	throw new RuntimeException( 'wp_die: ' . strip_tags( (string) $message ) );
}

function settings_fields( $group ) {
	echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '" />';
	echo '<input type="hidden" name="action" value="update" />';
	wp_nonce_field( 'stormsale-settings' );
}

function wp_nonce_field( $action = '' ) {
	echo '<input type="hidden" name="_wpnonce" value="testnonce" />';
}

function submit_button( $text = '' ) {
	echo '<p class="submit"><input type="submit" class="button-primary" value="' . esc_attr( $text ) . '" /></p>';
}

function register_setting( $group, $name, $args = array() ) {
	StormSale_Test_Env::$registered_settings[] = array(
		'group' => $group,
		'name'  => $name,
		'args'  => $args,
	);

	return true;
}

function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback = '' ) {
	StormSale_Test_Env::$submenu_pages[] = array(
		'parent'      => $parent,
		'page_title'  => $page_title,
		'menu_title'  => $menu_title,
		'capability'  => $capability,
		'slug'        => $slug,
		'callback'    => $callback,
	);

	return $slug;
}

function add_settings_error( $option, $code, $message, $type = 'error' ) {
	StormSale_Test_Env::$settings_errors[] = array(
		'option'  => $option,
		'code'    => $code,
		'message' => $message,
		'type'    => $type,
	);
}

function get_settings_errors( $option = '' ) {
	return StormSale_Test_Env::$settings_errors;
}

function is_ssl() {
	return (bool) StormSale_Test_Env::$ssl;
}

function home_url( $path = '' ) {
	return StormSale_Test_Env::$home_url . (string) $path;
}

function wp_remote_post( $url, $args = array() ) {
	StormSale_Test_Env::$remote_calls[] = array(
		'url'  => $url,
		'args' => $args,
	);

	if ( empty( StormSale_Test_Env::$remote_queue ) ) {
		return new WP_Error( 'http_request_failed', 'no response queued' );
	}

	return array_shift( StormSale_Test_Env::$remote_queue );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code( $response ) {
	if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
		return (int) $response['response']['code'];
	}

	return 0;
}

function wp_remote_retrieve_body( $response ) {
	if ( is_array( $response ) && isset( $response['body'] ) ) {
		return (string) $response['body'];
	}

	return '';
}

function wc_get_order( $order ) {
	$order_id = is_numeric( $order ) ? (int) $order : 0;

	return isset( StormSale_Test_Env::$orders[ $order_id ] )
		? StormSale_Test_Env::$orders[ $order_id ]
		: false;
}

function wc_get_price_decimals() {
	return (int) StormSale_Test_Env::$price_decimals;
}

function wc_get_logger() {
	return new StormSale_Fake_Logger();
}

function WC() {
	return StormSale_Test_Env::$wc;
}

function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	StormSale_Test_Env::$async_actions[] = array(
		'hook'  => $hook,
		'args'  => $args,
		'group' => $group,
	);

	return true;
}

function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
	StormSale_Test_Env::$scheduled_actions[] = array(
		'timestamp' => (int) $timestamp,
		'hook'      => $hook,
		'args'      => $args,
		'group'     => $group,
	);

	return count( StormSale_Test_Env::$scheduled_actions );
}

function as_has_scheduled_action( $hook, $args = array(), $group = '' ) {
	foreach ( StormSale_Test_Env::$async_actions as $action ) {
		if ( $action['hook'] === $hook && $action['args'] === $args && $action['group'] === $group ) {
			return true;
		}
	}

	foreach ( StormSale_Test_Env::$scheduled_actions as $action ) {
		if ( $action['hook'] === $hook && $action['args'] === $args && $action['group'] === $group ) {
			return true;
		}
	}

	return false;
}

function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/\\' ) . '/';
}

function plugin_basename( $file ) {
	return 'stormsale-affiliate/' . str_replace( '\\', '/', basename( $file ) );
}

function plugins_url( $path = '', $file = '' ) {
	return 'https://shop.example.test/wp-content/plugins/stormsale-affiliate/' . ltrim( str_replace( '\\', '/', (string) $path ), '/' );
}

function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
	return true;
}

function register_activation_hook( $file, $callback ) {
	StormSale_Test_Env::$activation_hooks[] = array(
		'file'     => $file,
		'callback' => $callback,
	);
}

function register_deactivation_hook( $file, $callback ) {
	return true;
}

function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
	StormSale_Test_Env::$scripts[] = array(
		'handle'    => $handle,
		'src'       => $src,
		'deps'      => $deps,
		'ver'       => $ver,
		'in_footer' => $in_footer,
	);

	return true;
}

function wp_add_inline_script( $handle, $data, $position = 'after' ) {
	StormSale_Test_Env::$inline_scripts[] = array(
		'handle'   => $handle,
		'data'     => $data,
		'position' => $position,
	);

	return true;
}
