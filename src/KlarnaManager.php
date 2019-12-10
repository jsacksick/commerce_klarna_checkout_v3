<?php

namespace Drupal\commerce_klarna_checkout;

use Drupal\commerce_order\AdjustmentTransformerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\profile\Entity\ProfileInterface;
use Klarna\Rest\Checkout\Order as KlarnaOrder;
use Klarna\Rest\Transport\ConnectorInterface;
use Klarna\Rest\Transport\GuzzleConnector;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class KlarnaManager implements KlarnaManagerInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The adjustment transformer.
   *
   * @var \Drupal\commerce_order\AdjustmentTransformerInterface
   */
  protected $adjustmentTransformer;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The payment gateway plugin configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * The Klarna HTTP transport connector.
   *
   * @var \Klarna\Rest\Transport\ConnectorInterface
   */
  protected $connector;

  /**
   * Constructs a new KlarnaManager object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_order\AdjustmentTransformerInterface $adjustment_transformer
   *   The adjustment transformer.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param array $config
   *   The payment gateway plugin configuration array.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AdjustmentTransformerInterface $adjustment_transformer, EventDispatcherInterface $event_dispatcher, array $config) {
    $this->entityTypeManager = $entity_type_manager;
    $this->adjustmentTransformer = $adjustment_transformer;
    $this->eventDispatcher = $event_dispatcher;
    $this->config = $config;
    $this->initConnector();
  }

  /**
   * Initialize the HTTP transport connector.
   */
  protected function initConnector() {
    // See https://developers.klarna.com/api/#api-urls.
    if ($this->config['purchase_country'] === 'US') {
      $endpoint = $this->config['mode'] === 'test' ? ConnectorInterface::NA_TEST_BASE_URL : ConnectorInterface::NA_BASE_URL;
    }
    else {
      $endpoint = $this->config['mode'] === 'test' ? ConnectorInterface::EU_TEST_BASE_URL : ConnectorInterface::EU_BASE_URL;
    }
    $this->connector = GuzzleConnector::create(
      $this->config['username'],
      $this->config['password'],
      $endpoint
    );
  }

  /**
   * {@inheritdoc}
   */
  public function acknowledgeOrder($klarna_order_id) {
    $klarna_order = new \Klarna\Rest\OrderManagement\Order($this->connector, $klarna_order_id);
    $klarna_order->acknowledge();
  }

  /**
   * {@inheritdoc}
   */
  public function captureOrder(OrderInterface $order) {
    if (!$order->getData('klarna_order_id')) {
      throw new \InvalidArgumentException(sprintf('Missing Klarna order ID for order %.', $order->id()));
    }
    // Capture the order total.
    $params = [
      'captured_amount' => $this->toMinorUnits($order->getTotalPrice()),
      'order_lines' => $this->buildOrderLines($order),
    ];
    $klarna_order = new \Klarna\Rest\OrderManagement\Order($this->connector, $order->getData('klarna_order_id'));

    try {
      $capture = $klarna_order->createCapture($params);

      // If no exception was thrown, assume everything went allright, update
      // the payment.
      /** @var \Drupal\commerce_payment\PaymentStorageInterface $payment_storage */
      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $payments = $payment_storage->loadMultipleByOrder($order);

      // There's no easy way to identify which payment maps to this capture, so
      // pick the first one.
      if ($payments) {
        $payment = reset($payment);
        if ($payment->getState()->getId() === 'authorization') {
          $payment->setState('completed');
          $payment->save();
        }
      }

      return $capture;
    }
    catch (\Exception $exception) {
      throw $exception;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createOrder(OrderInterface $order, array $merchant_urls) {
    // @todo: Dispatch an event to allow altering the request body.
    $params = $this->buildOrderRequest($order, $merchant_urls);
    $klarna_order = new KlarnaOrder($this->connector);
    $klarna_order = $klarna_order->create($params);
    $klarna_order->fetch();
    return $klarna_order;
  }

  /**
   * {@inheritdoc}
   */
  public function getOrder($klarna_order_id) {
    $klarna_order = new KlarnaOrder($this->connector);
    $klarna_order->fetch();
    return $klarna_order;
  }

  /**
   * {@inheritdoc}
   */
  public function updateOrder(OrderInterface $order, array $merchant_urls) {
    $params = $this->buildOrderRequest($order, $merchant_urls);
    $klarna_order = new KlarnaOrder($this->connector, $order->getData('klarna_order_id'));
    // @todo: Dispatch an event to allow altering the request body.
    $klarna_order->update($params);
    return $klarna_order;
  }

  /**
   * Builds the order request array for the given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param array $merchant_urls
   *   The merchant URLS.
   *
   * @return array
   *   The order request array.
   */
  protected function buildOrderRequest(OrderInterface $order, array $merchant_urls) {
    foreach (['checkout', 'confirmation', 'push'] as $required_key) {
      if (!isset($merchant_urls[$required_key])) {
        throw new \InvalidArgumentException(sprintf('Missing required key %s in the provided $merchant_urls array.', $required_key));
      }
    }
    $profiles = $order->collectProfiles();
    $adjustments = $order->collectAdjustments();
    $params = [
      'purchase_country' => $this->config['purchase_country'],
      'purchase_currency' => $order->getTotalPrice()->getCurrencyCode(),
      'name' => $order->getStore()->label(),
      'locale' => $this->config['locale'],
      'order_amount' => $this->toMinorUnits($order->getTotalPrice()),
      'merchant_urls' => [
        'terms' => Url::fromUserInput($this->config['terms_path'], ['absolute' => TRUE])->toString(),
        'checkout' => $merchant_urls['checkout'],
        'confirmation' => $merchant_urls['confirmation'],
        'push' => $merchant_urls['push'],
      ],
      'merchant_reference1' => $order->getOrderNumber() ?: $order->id(),
      'merchant_reference2' => $order->id(),
      'options' => [
        'allow_separate_shipping_address' => $this->config['allow_separate_shipping_address'],
      ],
      'order_lines' => $this->buildOrderLines($order),
    ];

    if (!empty($this->config['allowed_customer_types'])) {
      $params['options']['allowed_customer_types'] = $this->config['allowed_customer_types'];
    }

    // Send the billing profile only if not null.
    if (isset($profiles['billing'])) {
      $billing_address = $this->buildAddress($profiles['billing']);
      $params['billing_address'] = $billing_address;

      if ($order->getEmail()) {
        $params['billing_address']['email'] = $order->getEmail();
      }
    }

    $tax_total = $this->getAdjustmentsTotal($adjustments, ['tax'], FALSE);
    $params['order_tax_amount'] = $tax_total ? $this->toMinorUnits($tax_total) : 0;
    return $params;
  }

  /**
   * Builds the order lines for Klarna.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   The order lines as expected by Klarna.
   */
  protected function buildOrderLines(OrderInterface $order) {
    $adjustments = $order->collectAdjustments();
    $order_lines = [];
    foreach ($order->getItems() as $order_item) {
      $tax_rate = 0;
      $tax_adjustments = $order_item->getAdjustments(['tax']);
      if ($tax_adjustments) {
        $tax_rate = $tax_adjustments[0]->getPercentage();
      }
      // Fallback to the order item ID.
      $reference = $order_item->id();
      $purchased_entity = $order_item->getPurchasedEntity();

      if ($purchased_entity instanceof ProductVariationInterface) {
        $reference = $purchased_entity->getSku();
      }

      $tax_total = $this->getAdjustmentsTotal($tax_adjustments, [], FALSE);
      $order_lines[] = [
        'reference' => $reference,
        'name' => $order_item->label(),
        'quantity' => (int) $order_item->getQuantity(),
        'tax_rate' => $tax_rate ? (int) Calculator::multiply($tax_rate, '10000') : 0,
        'unit_price' => $this->toMinorUnits($order_item->getUnitPrice()),
        'total_tax_amount' => $tax_total ? $this->toMinorUnits($tax_total) : 0,
        'total_amount' => $this->toMinorUnits($order_item->getTotalPrice()),
      ];
    }
    $adjustments_type_mapping = [
      'promotion' => 'discount',
      'shipping' => 'shipping_fee',
    ];
    foreach ($adjustments as $adjustment) {
      $adjustment_type = $adjustment->getType();
      // Skip included adjustments and the ones we don't handle.
      if ($adjustment->isIncluded() ||
        !isset($adjustments_type_mapping[$adjustment_type])) {
        continue;
      }
      $order_lines[] = [
        'reference' => $adjustment->getSourceId() ? $adjustment->getSourceId() : '',
        'name' => $adjustment->getLabel(),
        'type' => $adjustments_type_mapping[$adjustment_type],
        'quantity' => 1,
        'tax_rate' => 0,
        'total_tax_amount' => 0,
        'unit_price' => $this->toMinorUnits($adjustment->getAmount()),
        'total_amount' => $this->toMinorUnits($adjustment->getAmount()),
      ];
    }

    return $order_lines;
  }

  /**
   * Builds an address in a format expected by Klarna for the given profile.
   *
   * @param \Drupal\profile\Entity\ProfileInterface $profile
   *   The profile to build an address for.
   *
   * @return array
   */
  protected function buildAddress(ProfileInterface $profile) {
    /** @var \Drupal\address\AddressInterface $address */
    $address = $profile->get('address')->first();
    return [
      'organization_name' => $address->getOrganization(),
      'given_name' => $address->getGivenName(),
      'family_name' => $address->getFamilyName(),
      'country' => $address->getCountryCode(),
      'postal_code' => $address->getPostalCode(),
      'city' => $address->getLocality(),
      'region' => $address->getAdministrativeArea(),
      'street_address' => $address->getAddressLine1(),
      'street_address2' => $address->getAddressLine2(),
    ];
  }

  /**
   * Calculates the total for the given adjustments.
   *
   * @param \Drupal\commerce_order\Adjustment[] $adjustments
   *   The adjustments.
   * @param string[] $adjustment_types
   *   The adjustment types to include in the calculation.
   *   Examples: fee, promotion, tax. Defaults to all adjustment types.
   * @param bool $skip_included
   *   (optional) Whether to skip the included adjustments.
   *
   * @return \Drupal\commerce_price\Price|null
   *   The adjustments total, or NULL if no matching adjustments were found.
   */
  protected function getAdjustmentsTotal(array $adjustments, array $adjustment_types = [], $skip_included = TRUE) {
    $adjustments_total = NULL;
    $matching_adjustments = [];

    foreach ($adjustments as $adjustment) {
      if ($adjustment_types && !in_array($adjustment->getType(), $adjustment_types)) {
        continue;
      }
      if ($skip_included && $adjustment->isIncluded()) {
        continue;
      }
      $matching_adjustments[] = $adjustment;
    }
    if ($matching_adjustments) {
      $matching_adjustments = $this->adjustmentTransformer->processAdjustments($matching_adjustments);
      foreach ($matching_adjustments as $adjustment) {
        $adjustments_total = $adjustments_total ? $adjustments_total->add($adjustment->getAmount()) : $adjustment->getAmount();
      }
    }

    return $adjustments_total;
  }

  /**
   * Converts the given amount to its minor units.
   *
   * For example, 9.99 USD becomes 999 (Copied from PaymentGatewayBase).
   *
   * @param \Drupal\commerce_price\Price $amount
   *   The amount.
   *
   * @return int
   *   The amount in minor units, as an integer.
   */
  protected function toMinorUnits(Price $amount) {
    $currency_storage = $this->entityTypeManager->getStorage('commerce_currency');
    /** @var \Drupal\commerce_price\Entity\CurrencyInterface $currency */
    $currency = $currency_storage->load($amount->getCurrencyCode());
    $fraction_digits = $currency->getFractionDigits();
    $number = $amount->getNumber();
    if ($fraction_digits > 0) {
      $number = Calculator::multiply($number, pow(10, $fraction_digits));
    }

    return (int) round($number, 0);
  }

}
