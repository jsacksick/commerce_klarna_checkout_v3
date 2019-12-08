<?php

namespace Drupal\commerce_klarna_checkout;

use Drupal\commerce_order\Entity\OrderInterface;

interface KlarnaManagerInterface {

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
