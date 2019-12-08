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
      'locale' => 'sv-se',
      'terms_path' => '',
      'update_billing_profile' => TRUE,
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
      '#title' => t('Update the billing customer profile with address information the customer enters at Klarna.'),
      '#default_value' => $this->configuration['update_billing_profile'],
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
    $this->configuration['locale'] = $values['locale'];
    $this->configuration['terms_path'] = $values['terms_path'];
    $this->configuration['update_billing_profile'] = $values['update_billing_profile'];
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
