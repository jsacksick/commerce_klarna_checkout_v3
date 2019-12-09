<?php

namespace Drupal\commerce_klarna_checkout;

use Drupal\commerce_order\Entity\OrderInterface;

interface KlarnaManagerInterface {

  /**
   * Gets an order from Klarna
   *
   * @param string $klarna_order_id
   *   The Klarna order ID.
   *
   * @return \Klarna\Rest\Checkout\Order
   *   The Klarna checkout order.
   */
  public function getOrder($klarna_order_id);

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
   *   @see https://developers.klarna.com/api/#checkout-api-create-an-order
   *
   * @throws \InvalidArgumentException
   *   If the provided $merchant_urls array is incomplete.
   *
   * @return \Klarna\Rest\Checkout\Order
   *   The Klarna checkout order.
   */
  public function createOrder(OrderInterface $order, array $merchant_urls);

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
   *   @see https://developers.klarna.com/api/#checkout-api-create-an-order
   *
   * @throws \InvalidArgumentException
   *   If the provided $merchant_urls array is incomplete.
   *
   * @return \Klarna\Rest\Checkout\Order
   *   The Klarna checkout order.
   */
  public function updateOrder(OrderInterface $order, array $merchant_urls);

}
