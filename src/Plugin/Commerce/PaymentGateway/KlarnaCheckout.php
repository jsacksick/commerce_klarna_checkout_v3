<?php

namespace Drupal\commerce_klarna_checkout\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_klarna_checkout\KlarnaManagerFactoryInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\profile\Entity\ProfileInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Klarna checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "klarna_checkout",
 *   label = "Klarna Checkout",
 *   display_label = "Klarna Checkout",
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_klarna_checkout\PluginForm\OffsiteRedirect\KlarnaCheckoutForm",
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class KlarnaCheckout extends OffsitePaymentGatewayBase {

  /**
   * The Klarna manager factory.
   *
   * @var \Drupal\commerce_klarna_checkout\KlarnaManagerFactoryInterface
   */
  protected $klarnaManagerFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new KlarnaCheckout object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   *   The payment type manager.
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   *   The payment method type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time.
   * @param \Drupal\commerce_klarna_checkout\KlarnaManagerFactoryInterface $klarna_manager_factory
   *   The Klarna manager factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, KlarnaManagerFactoryInterface $klarna_manager_factory, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->klarnaManagerFactory = $klarna_manager_factory;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('commerce_klarna_checkout.manager_factory'),
      $container->get('logger.channel.commerce_klarna_checkout')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'username' => '',
      'password' => '',
      'purchase_country' => '',
      'locale' => 'sv-se',
      'terms_path' => '',
      'update_billing_profile' => TRUE,
      'allowed_customer_types' => [],
      'allow_separate_shipping_address' => FALSE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $this->configuration['username'],
    ];

    $form['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#default_value' => $this->configuration['password'],
    ];

    $form['purchase_country'] = [
      '#type' => 'select',
      '#options' => [
        'AT' => $this->t('Austria'),
        'DK' => $this->t('Denmark'),
        'DE' => $this->t('Germany'),
        'FI' => $this->t('Finland'),
        'NL' => $this->t('Netherlands'),
        'NO' => $this->t('Norway'),
        'SE' => $this->t('Sweden'),
        'GB' => $this->t('United Kingdom'),
        'US' => $this->t('United States'),
      ],
      '#title' => t('Purchase country'),
      '#default_value' => $this->configuration['purchase_country'],
      '#required' => TRUE,
    ];

    $form['locale'] = [
      '#type' => 'select',
      '#title' => $this->t('Locale'),
      '#default_value' => $this->configuration['locale'],
      '#required' => TRUE,
      '#options' => [
        'en-us' => $this->t('English (United States)'),
        'en-gb' => $this->t('English (United Kingdom)'),
        'nl-nl' => $this->t('Dutch (Netherlands)'),
        'da-dk' => $this->t('Danish (Denmark)'),
        'sv-se' => $this->t('Swedish (Sweden)'),
      ],
    ];

    $form['terms_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path to terms and conditions page'),
      '#default_value' => $this->configuration['terms_path'],
      '#required' => TRUE,
    ];

    $form['update_billing_profile'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Update the billing customer profile with address information the customer enters at Klarna.'),
      '#default_value' => $this->configuration['update_billing_profile'],
    ];

    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Options'),
      '#open' => TRUE,
    ];

    $form['options']['allowed_customer_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed customer types'),
      '#options' => [
        'person' => $this->t('Person'),
        'organization' => $this->t('Organization'),
      ],
      '#default_value' => $this->configuration['allowed_customer_types'],
      '#parents' => array_merge($form['#parents'], ['allowed_customer_types']),
    ];

    $form['options']['allow_separate_shipping_address'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow separate shipping address'),
      '#description' => $this->t('If true, the consumer can enter different billing and shipping addresses.'),
      '#default_value' => $this->configuration['allow_separate_shipping_address'],
      '#parents' => array_merge($form['#parents'], ['allow_separate_shipping_address']),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if ($form_state->getErrors()) {
      return;
    }
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['username'] = $values['username'];
    $this->configuration['password'] = $values['password'];
    $this->configuration['purchase_country'] = $values['purchase_country'];
    $this->configuration['locale'] = $values['locale'];
    $this->configuration['terms_path'] = $values['terms_path'];
    $this->configuration['update_billing_profile'] = $values['update_billing_profile'];
    $customer_types = array_values(array_filter($values['allowed_customer_types']));

    if ($customer_types) {
      $this->configuration['allowed_customer_types'] = $customer_types;
    }
    $this->configuration['allow_separate_shipping_address'] = $values['allow_separate_shipping_address'];
  }

  /**
   * {@inheritdoc}
   */
  public function getNotifyUrl() {
    $notify_url = parent::getNotifyUrl();
    $notify_url->setOption('query', [
      'klarna_order_id' => '{checkout.order_id}',
    ]);
    return $notify_url;
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $klarna_manager = $this->klarnaManagerFactory->get($this->getConfiguration());

    try {
      $klarna_order = $klarna_manager->getOrder($order->getData('klarna_order_id'));
    }
    catch (\Exception $exception) {
      throw new PaymentGatewayException($exception->getMessage());
    }

    if (!isset($klarna_order['status']) || $klarna_order['status'] !== 'checkout_complete') {
      throw new PaymentGatewayException(sprintf('Unexpected Klarna order status (Expected: %s, Actual: %s)', 'checkout_complete', $klarna_order['status']));
    }

    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => 'authorization',
      // Use the amount we get from Klarna, rather than the order total, since
      // it might be different.
      'amount' => $this->fromMinorUnits($klarna_order['order_amount'], $klarna_order['purchase_currency']),
      'payment_gateway' => $this->entityId,
      'order_id' => $order->id(),
      'test' => $this->getMode() == 'test',
      'remote_id' => $klarna_order->getId(),
      'remote_state' => $klarna_order['status'],
    ]);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    $klarna_order_id = $request->query->get('klarna_order_id');

    if (empty($klarna_order_id)) {
      $this->logger->error('Cannot acknowledge the Klarna order: No order ID provided.');
      return NULL;
    }
    $klarna_manager = $this->klarnaManagerFactory->get($this->getConfiguration());

    try {
      $klarna_order = $klarna_manager->getOrder($klarna_order_id);
    }
    catch (\Exception $exception) {
      $this->logger->debug($exception->getMessage());
      return NULL;
    }

    if (!isset($klarna_order['status']) || $klarna_order['status'] !== 'checkout_complete') {
      $this->logger->error('Unexpected Klarna order status (Expected: @expected, Actual: @actual)', ['@expected' => 'checkout_complete', '@actual' => $klarna_order['status']]);
      return NULL;
    }

    /** @var \Drupal\commerce_payment\PaymentStorageInterface $payment_storage */
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->loadByRemoteId($klarna_order_id);

    $authorization_amount = $this->fromMinorUnits($klarna_order['order_amount'], $klarna_order['purchase_currency']);
    // It's still an authorization, payment has to be captured via the order
    // management API.
    if (!$payment) {
      $this->logger->notice('Cannot find a matching payment for the Klarna order ID @klarna_order_id, creating one.', ['@klarna_order_id' => $klarna_order_id]);
      $payment = $payment_storage->create([
        'state' => 'authorization',
        // Use the amount we get from Klarna, rather than the order total, since
        // it might be different.
        'amount' => $authorization_amount,
        'payment_gateway' => $this->entityId,
        // The order_id is passed as the "merchant_reference2", so use that for
        // the payment.
        'order_id' => $klarna_order['merchant_reference2'],
        'test' => $this->getMode() == 'test',
        'remote_id' => $klarna_order->getId(),
        'remote_state' => $klarna_order['status'],
      ]);
      $payment->save();
    }

    $order = $payment->getOrder();
    $order_total = $order->getTotalPrice();
    if ($authorization_amount->compareTo($order_total) !== 0) {
      $this->logger->notice('Order total mismatch: (Klarna total: @klarna_total, Order total: @order_total', ['@klarna_total' => $authorization_amount->getNumber(), '@order_total' => $order_total->getNumber()]);
    }

    // Update the billing profile if configured to do so.
    if (!empty($this->configuration['update_billing_profile'])) {
      $profile = $order->getBillingProfile();
      $save_order = FALSE;
      if (!$profile) {
        $profile = $this->entityTypeManager->getStorage('profile')->create([
          'uid' => 0,
          'type' => 'customer',
        ]);
        $save_order = TRUE;
      }
      $this->populateProfile($profile, $klarna_order['billing_address']);
      $profile->save();

      if ($save_order) {
        $order->setBillingProfile($profile);
        $order->save();
      }
    }

    try {
      $klarna_manager->acknowledgeOrder($klarna_order_id);
    }
    catch (\Exception $exception) {
      $this->logger->error('Cannot acknowledge the order ID @klarna_order_id', ['@klarna_order_id' => $klarna_order_id]);
      $this->logger->debug($exception->getMessage());
    }
  }

  /**
   * Converts an amount in "minor unit" to a decimal amount.
   *
   * For example, 999 USD becomes 9.99.
   *
   * @param $amount
   *   The amount in minor unit
   * @param string $currency_code
   *   The currency code.
   *
   * @return \Drupal\commerce_price\Price
   *   The decimal price.
   */
  protected function fromMinorUnits($amount, $currency_code) {
    $currency_storage = $this->entityTypeManager->getStorage('commerce_currency');
    /** @var \Drupal\commerce_price\Entity\CurrencyInterface $currency */
    $currency = $currency_storage->load($currency_code);
    $fraction_digits = $currency->getFractionDigits();

    if ($fraction_digits > 0) {
      $amount = Calculator::divide((string) $amount, pow(10, $fraction_digits), $fraction_digits);
    }

    return new Price((string) $amount, $currency_code);
  }

  /**
   * Populate the given profile with the given Klarna address.
   *
   * @param \Drupal\profile\Entity\ProfileInterface $profile
   *   The profile to populate.
   * @param array $address
   *   The Klarna address.
   */
  protected function populateProfile(ProfileInterface $profile, array $address) {
    $mapping = [
      'organization_name' => 'organization',
      'street_address' => 'address_line1',
      'street_address2' => 'address_line2',
      'city' => 'locality',
      'region' => 'administrative_area',
      'postal_code' => 'postal_code',
      'country' => 'country_code',
      'given_name' =>'given_name',
      'family_name' =>'family_name',
    ];
    foreach ($address as $key => $value) {
      if (!isset($mapping[$key])) {
        continue;
      }
      $profile->address->{$mapping[$key]} = $value;
    }
  }

}
