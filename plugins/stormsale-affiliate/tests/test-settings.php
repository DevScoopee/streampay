<?php

ss_test(
	'settings: option falls back to safe defaults',
	function () {
		ss_same(
			array(
				'campaign_id' => '',
				'api_key'     => '',
			),
			StormSale_Settings::all()
		);
		ss_same( '', StormSale_Settings::get( 'campaign_id' ) );
		ss_same( '', StormSale_Settings::get( 'unknown_key' ) );
		ss_not_ok( StormSale_Settings::is_configured() );
	}
);

ss_test(
	'settings: activation seeds the default option',
	function () {
		StormSale_Plugin::activate();

		ss_same( StormSale_Settings::defaults(), get_option( StormSale_Settings::OPTION ) );
	}
);

ss_test(
	'settings: valid input is saved and the plugin reports configured',
	function () {
		$clean = StormSale_Settings::sanitize(
			array(
				'campaign_id' => ' cmp_winter25 ',
				'api_key'     => " sk_live_secret_1\n",
			)
		);

		ss_same( 'cmp_winter25', $clean['campaign_id'] );
		ss_same( 'sk_live_secret_1', $clean['api_key'] );
		ss_same( array(), StormSale_Test_Env::$settings_errors );

		update_option( StormSale_Settings::OPTION, $clean );

		ss_ok( StormSale_Settings::is_configured() );
		ss_same( 'cmp_winter25', StormSale_Settings::get( 'campaign_id' ) );
	}
);

ss_test(
	'settings: blank fields keep the previously saved values',
	function () {
		ss_configure( 'cmp_saved', 'sk_saved_key' );

		$clean = StormSale_Settings::sanitize(
			array(
				'campaign_id' => '',
				'api_key'     => '',
			)
		);

		ss_same( 'cmp_saved', $clean['campaign_id'] );
		ss_same( 'sk_saved_key', $clean['api_key'] );
		ss_same( array(), StormSale_Test_Env::$settings_errors );
	}
);

ss_test(
	'settings: malformed campaign IDs are rejected without destroying the saved key',
	function () {
		ss_configure( 'cmp_saved', 'sk_saved_key' );

		foreach ( array( 'ab', 'has space!', 'x"; DROP TABLE', str_repeat( 'a', 65 ) ) as $bad ) {
			$clean = StormSale_Settings::sanitize(
				array(
					'campaign_id' => $bad,
					'api_key'     => '',
				)
			);

			ss_same( 'cmp_saved', $clean['campaign_id'], 'invalid campaign "' . $bad . '" is not saved' );
		}

		ss_same( 4, count( StormSale_Test_Env::$settings_errors ), 'one settings error per rejected campaign' );
	}
);

ss_test(
	'settings: non-array input and oversized API keys are handled defensively',
	function () {
		ss_configure( 'cmp_saved', 'sk_saved_key' );

		$clean = StormSale_Settings::sanitize( 'not-an-array' );

		ss_same( 'cmp_saved', $clean['campaign_id'] );
		ss_same( 'sk_saved_key', $clean['api_key'] );

		$clean = StormSale_Settings::sanitize(
			array(
				'campaign_id' => 'cmp_new',
				'api_key'     => str_repeat( 'k', 513 ),
			)
		);

		ss_same( 'cmp_new', $clean['campaign_id'] );
		ss_same( 'sk_saved_key', $clean['api_key'] );
		ss_same( 1, count( StormSale_Test_Env::$settings_errors ) );
	}
);

ss_test(
	'settings: API keys are stripped of control characters',
	function () {
		$clean = StormSale_Settings::sanitize(
			array(
				'campaign_id' => 'cmp_winter25',
				'api_key'     => "sk_live\x00\x1F_secret",
			)
		);

		ss_same( 'sk_live_secret', $clean['api_key'] );
	}
);

ss_test(
	'settings: page is registered under the WooCommerce menu',
	function () {
		do_action( 'admin_menu' );

		ss_same( 1, count( StormSale_Test_Env::$submenu_pages ) );

		$page = StormSale_Test_Env::$submenu_pages[0];

		ss_same( 'woocommerce', $page['parent'] );
		ss_same( 'stormsale', $page['slug'] );
		ss_same( 'manage_woocommerce', $page['capability'] );
	}
);

ss_test(
	'settings: the option is registered with a sanitize callback',
	function () {
		do_action( 'admin_init' );

		ss_same( 1, count( StormSale_Test_Env::$registered_settings ) );

		$setting = StormSale_Test_Env::$registered_settings[0];

		ss_same( StormSale_Settings::GROUP, $setting['group'] );
		ss_same( StormSale_Settings::OPTION, $setting['name'] );
		ss_same( array( 'StormSale_Settings', 'sanitize' ), $setting['args']['sanitize_callback'] );
	}
);

ss_test(
	'settings: render shows the saved values, the click table and escapes output',
	function () {
		ss_configure( 'cmp_winter25', 'sk_live_secret' );
		StormSale_Stats::record_click( 'aff<unsafe>' );

		ob_start();
		( new StormSale_Settings() )->render_page();
		$html = ob_get_clean();

		ss_contains( $html, 'stormsale_campaign_id' );
		ss_contains( $html, 'stormsale_api_key' );
		ss_contains( $html, 'cmp_winter25' );
		ss_contains( $html, 'Tracked referral clicks: 1' );
		ss_contains( $html, 'aff&lt;unsafe&gt;' );
		ss_not_contains( $html, 'aff<unsafe>', 'referral codes are escaped on output' );
	}
);

ss_test(
	'settings: render is denied to users without WooCommerce capability',
	function () {
		StormSale_Test_Env::$can_manage = false;

		try {
			ob_start();
			( new StormSale_Settings() )->render_page();
			ob_end_clean();
			ss_fail( 'expected wp_die for unauthorized users' );
		} catch ( RuntimeException $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			ss_contains( $e->getMessage(), 'wp_die' );
		}
	}
);
