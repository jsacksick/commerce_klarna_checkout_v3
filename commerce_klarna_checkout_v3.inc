<?php

use Klarna\Rest\Checkout\Order as KlarnaOrder;
use Klarna\Rest\OrderManagement\Order as KlarnaOrderManagement;
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
  $product_line_item_types = commerce_product_line_item_types();
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
  // Retrieve the payment rule configuration name.
  // We can't pass the full payment method instance ID to klarna for some
  // reasons (it's causing errors), we could reuse the payment method from
  // the order data, but having it passed loaded to the menu callbacks is
  // convenient.
  list(, $rule_name) = explode('|', $payment_method['instance_id']);
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
      'confirmation' => url('commerce_klarna_checkout_v3/confirm/' . $order->order_id . '/' . $order->data['payment_redirect_key'] . '/' . $rule_name, $url_options),
      'push' => url('commerce_klarna_checkout_v3/push/' . $order->order_id . '/' . $order->data['payment_redirect_key'] . '/' . $rule_name, $url_options),
      'validation' => url('commerce_klarna_checkout_v3/validate/' . $order->order_id, $url_options),
      'terms' => $settings['terms_path'],
    ],
    'order_amount' => (int) $order_total['amount'],
    'purchase_currency' => $order_total['currency_code'],
    'purchase_country' => $settings['purchase_country'],
    'order_lines' => [],
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

  // Map the Drupal line item type to the Klarna type.
  $types_mapping = [
    'shipping' => 'shipping_fee',
    'giftcard_use' => 'gift_card',
    'commerce_discount' => 'discount',
  ];

  // Assume physical for all the product line item types.
  foreach ($product_line_item_types as $line_item_type) {
    $types_mapping[$line_item_type] = 'physical';
  }

  // Loop over the line items.
  foreach ($order_wrapper->commerce_line_items as $line_item_wrapper) {
    if (!$line_item_wrapper->value()) {
      continue;
    }
    $line_item = $line_item_wrapper->value();
    $unit_price = $line_item_wrapper->commerce_unit_price->value();
    $line_item_total = $line_item_wrapper->commerce_total->value();

    $order_line = [
      'quantity' => (int) $line_item->quantity,
      'name' => commerce_line_item_title($line_item),
      'unit_price' => 0,
      'total_amount' => (int) $line_item_total['amount'],
      'tax_rate' => 0,
      'total_discount_amount' => 0,
      'total_tax_amount' => 0,
    ];

    // For product line item types.
    if (in_array($line_item_wrapper->getBundle(), $product_line_item_types)) {
      $order_line['reference'] = $line_item_wrapper->commerce_product->sku->value();
    }

    // Calculate the unit price without discounts.
    foreach ($unit_price['data']['components'] as $key => $component) {
      if (strpos($component['name'], 'discount') === 0) {
        continue;
      }
      // The discount amount needs to be passed as a positive amount.
      $order_line['unit_price'] += $component['price']['amount'];
    }
    $order_line['unit_price'] = (int) $order_line['unit_price'];

    if ($order_line['unit_price'] > 0) {
      // Calculate the total discount.
      foreach ($line_item_total['data']['components'] as $key => $component) {
        if (strpos($component['name'], 'discount') !== 0) {
          continue;
        }
        // The discount amount needs to be passed as a positive amount.
        $order_line['total_discount_amount'] += -$component['price']['amount'];
      }
      $order_line['total_discount_amount'] = (int) $order_line['total_discount_amount'];
    }

    // Calculate the tax.
    // If the tax module is enabled, try to get the tax rate.
    if ($commerce_tax_enabled) {
      $tax = commerce_tax_components($unit_price['data']['components']);
      $tax_rate = count($tax) ? (int) ($tax[0]['price']['data']['tax_rate']['rate'] * 10000) : 0;
      $order_line['tax_rate'] = $tax_rate;

      $tax_amount = commerce_tax_total_amount($line_item_total['data']['components'], FALSE, $order_total['currency_code']);
      $order_line['total_tax_amount'] = (int) $tax_amount;
    }

    // Check the taxes with the VAT module if no tax has been found before.
    if ($commerce_vat_enabled && $order_line['tax_rate'] <= 0) {
      // Get the VAT rate.
      $vat = commerce_vat_components($unit_price['data']['components']);
      $vat_rate = count($vat) ? (int) ($vat[0]['price']['data']['vat_rate_info']['rate'] * 10000) : 0;
      $order_line['tax_rate'] = $vat_rate;
      $tax_amount = commerce_vat_total_amount($line_item_total['data']['components'], TRUE, $order_total['currency_code']);
      $order_line['total_tax_amount'] = (int) $tax_amount;
    }

    // Add the type.
    if (isset($types_mapping[$line_item_wrapper->getBundle()])) {
      $order_line['type'] = $types_mapping[$line_item_wrapper->getBundle()];
    }

    $order_data['order_lines'][] = $order_line;
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
 * Handles the confirmation callback.
 *
 * @param $order.
 *   The order.
 * @param $payment_redirect_key
 *   The payment redirect key to compare with the one stored in the order.
 * @param $payment_method
 *   The payment method instance array that originally made the payment.
 */
function commerce_klarna_checkout_v3_process_confirmation($order, $payment_redirect_key, $payment_method) {
  if (empty($payment_method['settings']['username']) || empty($payment_method['settings']['password'])) {
    watchdog('commerce_klarna_checkout_v3', 'Cannot show the payment confirmation snippet for the order @order_id because the payment method is not correctly configured.', ['@order_id' => $order->order_id], WATCHDOG_ERROR);
    return MENU_NOT_FOUND;
  }
  // If the order could not be loaded or if the payment redirect key doesn't
  // match.
  if ($order->data['payment_redirect_key'] != $payment_redirect_key || !isset($_GET['klarna_order_id'])) {
    return MENU_NOT_FOUND;
  }

  // Retrieve the klarna order id from $_GET.
  $klarna_order = commerce_klarna_checkout_v3_get_order($order);

  if (empty($klarna_order['html_snippet']) || $klarna_order->getId() != $_GET['klarna_order_id']) {
    return MENU_NOT_FOUND;
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
 * Handles the push callback.
 *
 * @param $order
 *   The order.
 * @param $payment_redirect_key
 *   The payment redirect key to compare with the one stored in the order.
 * @param $payment_method
 *   The payment method instance array that originally made the payment.
 */
function commerce_klarna_checkout_v3_process_push($order, $payment_redirect_key, $payment_method) {
  if (empty($payment_method['settings']['username']) || empty($payment_method['settings']['password'])) {
    watchdog('commerce_klarna_checkout_v3', 'The payment for the order @order_id could not be processed because the payment method is not correctly configured.', ['@order_id' => $order->order_id], WATCHDOG_ERROR);
    return MENU_NOT_FOUND;
  }

  // If the payment redirect key doesn't match.
  if ($order->data['payment_redirect_key'] != $payment_redirect_key || !isset($_GET['klarna_order_id'])) {
    return MENU_NOT_FOUND;
  }
  $klarna_order_id = $_GET['klarna_order_id'];

  try {
    $connector = GuzzleConnector::create(
      $payment_method['settings']['username'],
      $payment_method['settings']['password'],
      commerce_klarna_checkout_v3_server_url($payment_method['settings'])
    );
    $klarna_order = new KlarnaOrderManagement($connector, $klarna_order_id);
    $klarna_order->fetch();
    // TODO: Check if the returned data from Klarna match the Drupal order data.
    $klarna_order->acknowledge();
  }
  catch (Exception $e) {
    watchdog_exception('commerce_klarna_checkout_v3', $e);
    return MENU_NOT_FOUND;
  }

  $charge = [
    'currency_code' => $klarna_order['purchase_currency'],
    'amount' => $klarna_order['order_amount']
  ];

  while (!isset($order->data['commerce_klarna_checkout_push_processed'])) {
    $transaction = db_transaction();
    try {
      $order->data['commerce_klarna_checkout_push_processed'] = TRUE;
      $order->data['payment_method'] = $payment_method['instance_id'];

      if (!empty($klarna_order['billing_address'])) {
        $order->mail = $klarna_order['billing_address']['email'];

        // Create a billing customer profile if requested in the payment method
        // settings.
        if (!empty($payment_method['settings']['customer_profile']['create_billing_profile'])) {
          $billing_profile = commerce_klarna_checkout_v3_create_customer_profile($klarna_order['billing_address'], 'billing', $order->uid);

          if ($billing_profile && isset($order->commerce_customer_billing)) {
            $order->commerce_customer_billing[LANGUAGE_NONE][0]['profile_id'] = $billing_profile->profile_id;
          }
        }
      }
      // Create a shipping customer profile if requested in the payment method
      // settings.
      if (!empty($klarna_order['shipping_address']) && !empty($payment_method['settings']['customer_profile']['create_shipping_profile'])) {
        if (!empty($payment_method['settings']['customer_profile']['create_billing_profile'])) {
          $shipping_profile = commerce_klarna_checkout_v3_create_customer_profile($klarna_order['shipping_address'], 'shipping', $order->uid);

          if ($shipping_profile && isset($order->commerce_customer_shipping)) {
            $order->commerce_customer_shipping[LANGUAGE_NONE][0]['profile_id'] = $shipping_profile->profile_id;
          }
        }
      }
      commerce_payment_redirect_pane_next_page($order, t('Completed order from Klarna push invocation.'));

      // Create the payment transaction.
      commerce_klarna_checkout_v3_transaction_save($payment_method, $order, $charge, $klarna_order);
    }
    // @todo: Find a way to make this exception handling generic.
    catch (\OptimisticLockException $exception) {
      $transaction->rollback();
      watchdog_exception('commerce_klarna_checkout_v3', $exception);
      $order = commerce_order_load($order->order_id);
    }
    catch (\Exception $exception) {
      // Make sure we end the loop in case of a generic exception, since there's
      // no guarantee that the next attempt will succeed.
      $order->data['commerce_klarna_checkout_push_processed'] = TRUE;
      watchdog_exception('commerce_klarna_checkout_v3', $exception);
    }
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
    foreach ($address_mapping as $drupal_field => $klarna_field) {
      // Skip the field if empty.
      if (empty($klarna_address[$klarna_field])) {
        continue;
      }
      $profile_wrapper->commerce_customer_address->{$drupal_field} = $klarna_address[$klarna_field];
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
    drupal_goto('checkout/' . $order->order_id, [], 303);
  }

  // Give modules a chance to validate the order.
  module_invoke_all('commerce_klarna_checkout_v3_validate_order', $order, $klarna_order);

  // Make sure the order is no longer considered as a cart, if the validation
  // passed.
  if (module_exists('commerce_checkout')) {
    while (!isset($order->data['commerce_klarna_checkout_v3_order_validated'])) {
      try {
        $order->data['commerce_klarna_checkout_v3_order_validated'] = TRUE;
        $order->log = t('Waiting payment confirmation from Klarna.');
        commerce_order_status_update($order, 'checkout_payment');
      }
      // @todo: Find a way to make this exception handling generic.
      catch (\OptimisticLockException $e) {
        $order = commerce_order_load($order->order_id);
      }
      catch (\Exception $exception) {
        // In case of a generic exception, there's no indication that the next
        // attempt will succeed, so make sure to break the while loop.
        $order->data['commerce_klarna_checkout_v3_order_validated'] = TRUE;
        watchdog_exception('matsmart_klarna', $exception);
      }
    }
  }

  return MENU_FOUND;
}
