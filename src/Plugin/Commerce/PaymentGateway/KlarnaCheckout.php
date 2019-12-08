<?php

namespace Drupal\commerce_klarna_checkout\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
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
  public function onReturn(OrderInterface $order, Request $request) {
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
  }

}
