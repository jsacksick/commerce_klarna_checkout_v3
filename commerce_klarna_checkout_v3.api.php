<?php

/**
 * @file
 * This file contains no working PHP code; it exists to provide additional
 * documentation for doxygen as well as to document hooks in the standard
 * Drupal manner.
 */

/**
 * Allows modules to alter the data passed to Klarna before initializing the
 * payment Iframe.
 *
 * @param $order_data
 *   The array containing the data passed to Klarna.
 * @param $order
 *   The order.
 */
function hook_commerce_klarna_checkout_v3_init_alter(&$order_data, $order) {
  $order_data['order_tax_amount'] = 20;
}

/**
 * Allows modules to manipulate the customer profile created by Klarna, right
 * before it's saved.
 *
 * @param $profile_wrapper
 *   The customer profile wrapper.
 * @param array $klarna_address
 *   The Klarna address.
 * @param $profile_type
 *   The customer profile type (e.g 'billing'|'shipping').
 */
function hook_commerce_klarna_checkout_v3_create_customer_profile($profile_wrapper, $klarna_address, $profile_type) {
  if ($profile_type != 'shipping') {
    return;
  }
  $profile_wrapper->my_custom_field = $klarna_address['care_of'];
}
