<?php

define( 'ABSPATH', __DIR__ . '/wordpress/' );

require __DIR__ . '/testkit.php';

require dirname( __DIR__ ) . '/stormsale-affiliate.php';

StormSale_Plugin::activate();

do_action( 'plugins_loaded' );

add_filter( 'stormsale_use_action_scheduler', array( 'StormSale_Test_Env', 'use_async_queue' ) );

StormSale_Test_Env::snapshot();
