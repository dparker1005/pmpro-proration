<?php
/*
Plugin Name: Paid Memberships Pro - Proration Add On
Plugin URI: http://www.paidmembershipspro.com/wp/pmpro-proration/
Description: Custom Prorating Code for Paid Memberships Pro
Version: .3.1
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
*/

/**
 * Load the languages folder for translations.
 */
function pmprorate_load_plugin_text_domain() {
	load_plugin_textdomain( 'pmpro-proration', false, basename( dirname( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'pmprorate_load_plugin_text_domain' );

include_once( plugin_dir_path( __FILE__ ) . 'includes/checkout.php' ); // Handles proration at checkout
include_once( plugin_dir_path( __FILE__ ) . 'includes/delayed-downgrades.php' ); // Handles downgrade UI and processing delayed downgrades.
include_once( plugin_dir_path( __FILE__ ) . 'includes/deprecated.php' ); // Deprecated functions.

/**
 * Mark the plugin as MMPU-incompatible.
 */
  function pmproprorate_mmpu_incompatible_add_ons( $incompatible ) {
	$incompatible[] = 'PMPro Prorations Add On';
	return $incompatible;
}
add_filter( 'pmpro_mmpu_incompatible_add_ons', 'pmproprorate_mmpu_incompatible_add_ons' );

/**
 * Add links to the plugin row meta
 */
function pmproproate_plugin_row_meta( $links, $file ) {
	if ( strpos( $file, 'pmpro-proration.php' ) !== false ) {
		$new_links = array(
			'<a href="' . esc_url( 'http://www.paidmembershipspro.com/add-ons/plus-add-ons/proration-prorate-membership/' ) . '" title="' . esc_attr( __( 'View Documentation', 'pmpro-proration' ) ) . '">' . esc_html__( 'Docs', 'pmpro-proration' ) . '</a>',
			'<a href="' . esc_url( 'http://paidmembershipspro.com/support/' ) . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro-proration' ) ) . '">' . esc_html__( 'Support', 'pmpro-proration' ) . '</a>',
		);
		$links     = array_merge( $links, $new_links );
	}

	return $links;
}

add_filter( 'plugin_row_meta', 'pmproproate_plugin_row_meta', 10, 2 );
