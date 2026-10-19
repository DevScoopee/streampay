<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'stormsale_settings' );
delete_option( 'stormsale_clicks' );
