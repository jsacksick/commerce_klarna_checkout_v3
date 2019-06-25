<?php

/**
 * Klarna payment confirmation pane: form callback.
 */
function commerce_klarna_checkout_v3_confirm_pane_checkout_form($form, &$form_state, $checkout_pane, $order) {
  if (empty($order->data['payment_method'])) {
    return [];
  }
  $payment_method = commerce_payment_method_instance_load($order->data['payment_method']);

  if ($payment_method['method_id'] != 'klarna_checkout_v3') {
    return [];
  }
  $klarna_order = commerce_klarna_checkout_v3_get_order($order, $payment_method);

  if (!empty($klarna_order['html_snippet'])) {
    return [
      'klarna_confirmation_snippet' => [
        '#markup' => $klarna_order['html_snippet'],
      ],
    ];
  }

  return [];
}
