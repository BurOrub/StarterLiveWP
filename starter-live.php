<?php
/**
 * Plugin Name: Starter Live
 * Description: Modifica testi, immagini, video e link direttamente dal frontend (blocchi Gutenberg e blocchi ACF), senza entrare nel backend. Pensato per il Tema Starter.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Denis Burani
 * License: GPL-2.0-or-later
 * Text Domain: starter-live
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STARTER_LIVE_VERSION', '0.1.0' );
define( 'STARTER_LIVE_DIR', plugin_dir_path( __FILE__ ) );
define( 'STARTER_LIVE_URL', plugin_dir_url( __FILE__ ) );

require_once STARTER_LIVE_DIR . 'includes/class-registry.php';
require_once STARTER_LIVE_DIR . 'includes/class-annotator.php';
require_once STARTER_LIVE_DIR . 'includes/class-frontend.php';
require_once STARTER_LIVE_DIR . 'includes/class-saver.php';
require_once STARTER_LIVE_DIR . 'includes/class-woocommerce.php';

Starter_Live_Frontend::init();
Starter_Live_Saver::init();
Starter_Live_WooCommerce::init();
