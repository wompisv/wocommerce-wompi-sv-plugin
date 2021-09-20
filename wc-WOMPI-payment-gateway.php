<?php
/*
Plugin Name: WOMPI - El Salvador
Plugin URI: https://github.com/wompisv/wocommerce-wompi-sv-plugin
Description: Plugin WooCommerce para integrar la pasarela de pago Wompi El Salvador
Version: 1.2.3
Author: WOMPI-El Salvador 
Author URI: https://wompi.sv
*/

  // Payment Gateway with WooCommerce infinitechsv
  add_action( 'plugins_loaded', 'WOMPI_payment_init', 0 );

  function WOMPI_payment_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;
    include_once( 'wc-WOMPI-payment.php' );
    add_filter( 'woocommerce_payment_gateways', 'add_WOMPI_payment_gateway' );
    function add_WOMPI_payment_gateway( $methods ) {
      
      $methods[] = 'WOMPI_Payment_Gateway';
      return $methods;
    }
  }


  add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'WOMPI_payment_action_links' );
  function WOMPI_payment_action_links( $links ) {
    $plugin_links = array(
      '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout' ) . '">' . __( 'Settings', 'WOMPI-payment' ) . '</a>',
    );

    return array_merge( $plugin_links, $links );
  }