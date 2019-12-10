<?php

namespace Drupal\commerce_klarna_checkout;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;

interface KlarnaManagerInterface {

  /**
   * Acknowledge a Klarna order.
   *
   * @param string $klarna_order_id
   *   The Klarna order ID.
   */
  public function acknowledgeOrder($klarna_order_id);

  /**
   * Creates a capture for the given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_price\Price $amount
   *   (optional) The amount to capture. When not specified, the order total we
   *   default to the order total.
   *
   * @return \Klarna\Rest\OrderManagement\Capture
   *   The capture resource.
   */
  public function captureOrder(OrderInterface $order, Price $amount = NULL);

  /**
   * Creates a new Klarna checkout order for the given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param array $merchant_urls
   *   An associative array containing the following keys:
   *   - 'checkout': The url to the checkout page.
   *   - 'confirmation': The url of the checkout confirmation page.
   *   - 'push': URL that will be requested when an order is completed.
   *
   * @see https://developers.klarna.com/api/#checkout-api-create-an-order
   *
   * @return \Klarna\Rest\Checkout\Order
   *   The Klarna checkout order.
   *
   * @throws \InvalidArgumentException
   *   If the provided $merchant_urls array is incomplete.
   */
  public function createOrder(OrderInterface $order, array $merchant_urls);

  /**
   * Gets an order from Klarna.
   *
   * @param string $klarna_order_id
   *   The Klarna order ID.
   *
   * @return \Klarna\Rest\Checkout\Order
   *   The Klarna checkout order.
   */
  public function getOrder($klarna_order_id);

  /**
   * Updates the order in Klarna.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param array $merchant_urls
   *   An associative array containing the following keys:
   *   - 'checkout': The url to the checkout page.
   *   - 'confirmation': The url of the checkout confirmation page.
   *   - 'push': URL that will be requested when an order is completed.
   *
   * @see https://developers.klarna.com/api/#checkout-api-create-an-order
   *
   * @return \Klarna\Rest\Checkout\Order
   *   The Klarna checkout order.
   *
   * @throws \InvalidArgumentException
   *   If the provided $merchant_urls array is incomplete.
   */
  public function updateOrder(OrderInterface $order, array $merchant_urls);

}
