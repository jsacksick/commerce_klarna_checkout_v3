<?php

namespace Drupal\commerce_klarna_checkout\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;

/**
 * Provides the interface for the Klarna Checkout payment gateway.
 */
interface KlarnaCheckoutInterface extends OffsitePaymentGatewayInterface, SupportsAuthorizationsInterface {

  /**
   * Acknowledge the given Klarna order, and optionally capture the payment if
   * configured to do so.
   *
   * @param string $klarna_order_id
   *   The Klarna order ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   (optional) Optionally pass the order when available. If not passed, the
   *   order ID will be obtain from the "merchant_reference2" property passed
   *   to Klarna when creating the order.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface
   *   The "authorized"|"captured" payment transaction created.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   In case the Klarna order could not be loaded or if its status is not the
   *   expected one.
   */
  public function acknowledgeOrder($klarna_order_id, OrderInterface $order = NULL);

}
