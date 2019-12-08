<?php

namespace Drupal\commerce_klarna_checkout\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

class KlarnaCheckoutForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayBase $plugin */
    $plugin = $this->plugin;
    // @todo: Implement the order creation here and output the snippet.

    // Embed snippet to plugin form (no redirect needed).
    $form['klarna'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => 'klarna-checkout-form',
      ],
      '#value' => '',
    ];

    return $form;
  }

}
