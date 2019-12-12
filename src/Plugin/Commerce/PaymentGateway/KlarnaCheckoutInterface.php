<?php

namespace Drupal\commerce_klarna_checkout\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;

/**
 * Provides the interface for the Klarna Checkout payment gateway.
 */
interface KlarnaCheckoutInterface extends OffsitePaymentGatewayInterface, SupportsAuthorizationsInterface {}
