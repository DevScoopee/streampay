<?php

ob_start();

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
ini_set( 'log_errors', '0' );

set_error_handler(
	function ( $severity, $message, $file, $line ) {
		if ( ! ( error_reporting() & $severity ) ) {
			return false;
		}

		throw new ErrorException( $message, 0, (int) $severity, $file, $line );
	}
);

require __DIR__ . '/bootstrap.php';

$files = glob( __DIR__ . '/test-*.php' );

if ( ! is_array( $files ) || empty( $files ) ) {
	echo 'No test files found.' . PHP_EOL;
	ob_end_flush();
	exit( 1 );
}

sort( $files );

foreach ( $files as $file ) {
	require $file;
}

$exit_code = StormSale_Test_Env::run();

ob_end_flush();

exit( $exit_code );
