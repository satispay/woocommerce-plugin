<?php
/*
 * Plugin Name: WooCommerce Satispay
 * Plugin URI: https://wordpress.org/plugins/woo-satispay/
 * Description: Save time and money by accepting payments from your customers with Satispay. Free, simple, secure! #doitsmart
 * Author: Satispay
 * Author URI: https://www.satispay.com/
 * Version: 1.5.2
 * WC requires at least: 3.4.0
 * WC tested up to: 5.0
 */

add_action('plugins_loaded', 'wc_satispay_init', 0);
function wc_satispay_init() {
	if (!class_exists('WC_Payment_Gateway')) return;

	include_once('wc-satispay.php');

	add_filter('woocommerce_payment_gateways', 'wc_satispay_add_gateway');

	function wc_satispay_add_gateway($methods) {
		$methods[] = 'WC_Satispay';
		return $methods;
	}

	add_filter('plugin_action_links_'.plugin_basename( __FILE__ ), 'wc_satispay_action_links');
	function wc_satispay_action_links($links) {
		$pluginLinks = array(
			'<a href="'.admin_url('admin.php?page=wc-settings&tab=checkout&section=satispay').'">'.__('Settings', 'woo-satispay').'</a>'
		);
		return array_merge($pluginLinks, $links);
	}
}
