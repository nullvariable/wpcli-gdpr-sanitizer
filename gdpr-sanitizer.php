<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

if ( ! defined( 'GDPR_SANITIZER_INCLUDES_PATH' ) ) {
	define( 'GDPR_SANITIZER_INCLUDES_PATH', 'includes' );
}

require_once( GDPR_SANITIZER_INCLUDES_PATH . '/class-gdpr-sanitizer.php' );
$instance = new GDPR_Sanitizer();

WP_CLI::add_command( 'gdpr-sanitizer', $instance );
