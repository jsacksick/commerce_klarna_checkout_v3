<?php

use Klarna\Rest\Checkout\Order as KlarnaOrder;
use Klarna\Rest\Transport\GuzzleConnector;

/**
 * @file
 * Includes functions related to the communication with Klarna Checkout.
 */

/**
 * Helper function that initiates the Klarna Checkout object.
 *
 * @param object $order
 *   The order object
 * @param array $payment_method
 *   The payment method instance.
 *
 * @return mixed
 *   A Klarna Order object, FALSE in case of error.
 */
function commerce_klarna_checkout_v3_init($order, $payment_method) {
  $settings = $payment_method['settings'] + commerce_klarna_checkout_v3_default_settings();
  $settings['options']['allowed_customer_types'] = array_values(array_filter($settings['options']['allowed_customer_types']));
  // Check which of the Tax module is enabled.
  $commerce_tax_enabled = module_exists('commerce_tax');
  $commerce_vat_enabled = module_exists('commerce_vat');

  $order_wrapper = entity_metadata_wrapper('commerce_order', $order);
  // We could use the order balance but then we wouldn't know how to calculate
  // the total tax for this amount, so use the order total here.
  $order_total = $order_wrapper->commerce_order_total->value();
  $url_options = [
    'absolute' => TRUE,
    'query' => [
      'klarna_order_id' => '{checkout.order.id}',
    ],
    'https' => TRUE,
  ];
  // We need to force url() to generate secure urls.
  $https = variable_get('https', FALSE);
  global $conf;
  if (!$https) {
    $conf['https'] = TRUE;
  }
  $order_data = [
    'merchant_reference1' => $order->order_id,
    'merchant_reference2' => $order->order_number,
    'merchant_urls' => [
      'checkout' => url('checkout/' . $order->order_id . '/payment', $url_options),
      // We cannot use the regular payment/return callback because this works
      // only if the current checkout page is the payment page.
      'confirmation' => url('commerce_klarna_checkout_v3/confirm/' . $order->order_id . '/' . $order->data['payment_redirect_key'], $url_options),
      'push' => url('commerce_klarna_checkout_v3/push/' . $order->order_id . '/' . $order->data['payment_redirect_key'], $url_options),
      'validation' => url('commerce_klarna_checkout_v3/validate/' . $order->order_id, $url_options),
      'terms' => $settings['terms_path'],
    ],
    'order_amount' => (int) $order_total['amount'],
    'purchase_currency' => $order_total['currency_code'],
    'purchase_country' => $settings['purchase_country'],
    'order_lines' => _commerce_klarna_checkout_v3_build_order_lines($order_wrapper->commerce_line_items->value()),
    'order_tax_amount' => 0,
    'locale' => $settings['locale'],
    'options' => $settings['options'],
  ];
  $conf['https'] = $https;

  // First, check using the commerce_tax module.
  if ($commerce_tax_enabled) {
    $tax_amount = commerce_tax_total_amount($order_total['data']['components'], FALSE, $order_total['currency_code']);
    $order_data['order_tax_amount'] = (int) $tax_amount;
  }

  // Retrieve the VAT using the commerce_vat module.
  if ($commerce_vat_enabled && $order_data['order_tax_amount'] <= 0) {
    $vat_amount = commerce_vat_total_amount($order_total['data']['components'], TRUE, $order_total['currency_code']);
    $order_data['order_tax_amount'] = (int) $vat_amount;
  }

  drupal_alter('commerce_klarna_checkout_v3_init', $order_data, $order);

  $connector = GuzzleConnector::create(
    $settings['username'],
    $settings['password'],
    commerce_klarna_checkout_v3_server_url($settings)
  );
  $klarna_order_id = commerce_klarna_checkout_v3_get_mapping($order->order_id);

  // If the order was already created in Klarna, attempt to update it and fetch
  // it.
  if ($klarna_order_id) {
    try {
      $klarna_order = new KlarnaOrder($connector, $klarna_order_id);
      $klarna_order = $klarna_order->update($order_data);
      return $klarna_order;
    }
    catch (Exception $e) {
      watchdog_exception('commerce_klarna_checkout_v3', $e);
    }
  }

  // Otherwise, create a new order.
  try {
    $klarna_order = new KlarnaOrder($connector);
    $klarna_order = $klarna_order->create($order_data);
    // Store the Klarna order ID in our mapping table so that it can be
    // retrieved later.
    commerce_klarna_checkout_v3_store_mapping($order->order_id, $klarna_order->getId());
    return $klarna_order;
  }
  catch (Exception $e) {
    drupal_set_message(t('There was an error while initializing Klarna. Please try again or contact us for assistance.'), 'error');
    watchdog_exception('commerce_klarna_checkout_v3', $e);
    return FALSE;
  }
}

/**
 * Menu callback: Display the Klarna thank you snippet.
 */
function commerce_klarna_checkout_v3_process_confirmation($order, $payment_redirect_key) {
  // If the order could not be loaded or if the payment redirect key doesn't
  // match.
  if ($order->data['payment_redirect_key'] != $payment_redirect_key || !isset($_GET['klarna_order_id'])) {
    return MENU_NOT_FOUND;
  }
  $klarna_order = commerce_klarna_checkout_v3_get_order($order);

  if (empty($klarna_order['html_snippet']) || $klarna_order->getId() != $_GET['klarna_order_id']) {
    return MENU_NOT_FOUND;
  }
  if (!isset($order->data['commerce_klarna_checkout_order_acknowledged'])) {
    commerce_klarna_checkout_v3_acknowledge_order($order, $klarna_order->getId());
  }
  return [
    '#theme' => 'html_tag',
    '#tag' => 'div',
    '#attributes' => [
      'id' => 'klarna-checkout-container',
    ],
    '#value' => $klarna_order['html_snippet'],
  ];
}

/**
 * Menu callback: Klarna push endpoint.
 */
function commerce_klarna_checkout_v3_process_push($order, $payment_redirect_key) {
  // If the payment redirect key doesn't match.
  if ($order->data['payment_redirect_key'] != $payment_redirect_key || !isset($_GET['klarna_order_id'])) {
    return MENU_NOT_FOUND;
  }
  if (!isset($order->data['commerce_klarna_checkout_order_acknowledged'])) {
    commerce_klarna_checkout_v3_acknowledge_order($order, $_GET['klarna_order_id']);
  }
}

/**
 * Create a customer profile of the given type with the information retrieved
 * from Klarna.
 *
 * @param $klarna_address
 *   The billing|shipping address from Klarna.
 * @param $profile_type
 *   The type of customer profile to create.
 * @param $uid
 *   The uid of the user the customer profile is for.
 *
 * @return mixed
 *   The saved customer profile, FALSE if there was an error.
 */
function commerce_klarna_checkout_v3_create_customer_profile($klarna_address, $profile_type, $uid = 0) {
  if (!module_exists('commerce_customer')) {
    return FALSE;
  }
  // Create a new customer profile.
  $profile = commerce_customer_profile_new($profile_type, $uid);
  $profile_wrapper = entity_metadata_wrapper('commerce_customer_profile', $profile);

  if (!isset($profile_wrapper->commerce_customer_address)) {
    return FALSE;
  }

  if (!empty($klarna_address['country'])) {
    $klarna_address['country'] = strtoupper($klarna_address['country']);
  }
  $name_line = [];

  // Build the name_line.
  foreach (['given_name', 'family_name'] as $name_field) {
    if (!empty($klarna_address[$name_field])) {
      $name_line[] = $klarna_address[$name_field];
    }
  }
  $klarna_address['name_line'] = implode(' ', $name_line);

  $address_mapping = [
    'name_line' => 'name_line',
    'first_name' => 'given_name',
    'last_name' => 'family_name',
    'country' => 'country',
    'locality' => 'city',
    'postal_code' => 'postal_code',
    'thoroughfare' => 'street_address',
    'premise' => 'care_of',
  ];
  try {
    $address_wrapper = $profile_wrapper->commerce_customer_address;
    foreach ($address_mapping as $drupal_field => $klarna_field) {
      // Skip the field if empty.
      if (empty($klarna_address[$klarna_field])) {
        continue;
      }
      $address_wrapper->{$drupal_field} = $klarna_address[$klarna_field];
    }

    // For DE/AT/NL, build the street using the "street_name" and "streetNumber".
    if (!empty($klarna_address['street_name'])) {
      $address_wrapper->thoroughfare = $klarna_address['street_name'] .' '. $klarna_address['street_number'];
    }

    module_invoke_all('commerce_klarna_checkout_v3_create_customer_profile', $profile_wrapper, $klarna_address, $profile_type);
    // Save the customer profile and return it.
    $profile_wrapper->save();
    return $profile;
  }
  catch (Exception $e) {
    watchdog_exception('commerce_klarna_checkout_v3', $e, 'An exception occurred while creating the billing customer profile for the order');
    return FALSE;
  }
}

/**
 * Page callback: Validate the order before it is completed.
 */
function commerce_klarna_checkout_v3_validate_order($order) {
  $klarna_order = drupal_json_decode(file_get_contents('php://input'));

  // Log the data sent by Klarna, if logging is enabled.
  if (variable_get('commerce_klarna_checkout_v3_enable_logging', FALSE)) {
    watchdog(
      'commerce_klarna_checkout_v3',
      'Incoming validation request from Klarna for order id @order_id. Klarna object: <pre>@klarna</pre>.',
      [
        '@order_id' => $order->order_id,
        '@klarna' => print_r($klarna_order, TRUE),
      ],
      WATCHDOG_DEBUG
    );
  }
  $klarna_total = [
    'currency_code' => strtoupper($klarna_order['purchase_currency']),
    'amount' => (int) $klarna_order['order_amount'],
  ];
  $order_total = field_get_items('commerce_order', $order, 'commerce_order_total', LANGUAGE_NONE);
  // Check if the total known by Klarna matches the one known by Drupal, and
  // deny the purchase if there's a mismatch.
  if ($order_total[0]['currency_code'] != $klarna_total['currency_code'] ||
    $klarna_total['amount'] != $order_total[0]['amount']) {
    watchdog('commerce_klarna_checkout_v3',
      'Total amount mismatch for order @order_id (Klarna total: @klarna_total, Drupal total: @order_total).',
      [
        '@order_id' => $order->order_id,
        '@klarna_total' => $klarna_total['amount'],
        '@order_total' => $order_total[0]['amount'],
      ]
    );
    if (!empty($order->data['source_url'])) {
      drupal_goto($order->data['source_url'] . '/checkout', ['external' => TRUE], 303);
    }
    else {
      // We don't know where the order was created from, redirect to the Drupal
      // frontend.
      drupal_goto("checkout/$order->order_id", [], 303);
    }
  }

  // Give modules a chance to validate the order.
  module_invoke_all('commerce_klarna_checkout_v3_validate_order', $order, $klarna_order);

  return MENU_FOUND;
}
