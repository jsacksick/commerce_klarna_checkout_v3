<?php

namespace Drupal\commerce_klarna_checkout\PluginForm\OffsiteRedirect;

use Drupal\commerce_klarna_checkout\KlarnaManagerFactoryInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Symfony\Component\DependencyInjection\ContainerInterface;

class KlarnaCheckoutForm extends PaymentOffsiteForm implements ContainerInjectionInterface {

  /**
   * The Klarna manager factory.
   *
   * @var \Drupal\commerce_klarna_checkout\KlarnaManagerFactoryInterface
   */
  protected $klarnaManagerFactory;

  /**
   * Constructs a new KlarnaCheckoutForm object.
   *
   * @param \Drupal\commerce_klarna_checkout\KlarnaManagerFactoryInterface $klarna_manager_factory
   *   The Klarna manager factory.
   */
  public function __construct(KlarnaManagerFactoryInterface $klarna_manager_factory) {
    $this->klarnaManagerFactory = $klarna_manager_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_klarna_checkout.manager_factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    $order = $payment->getOrder();
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $plugin */
    $plugin = $this->plugin;
    $klarna_manager = $this->klarnaManagerFactory->get($plugin->getConfiguration());
    // Check if we already have a Klarna order ID for this order.
    $merchant_urls = [
      'checkout' => $form['#cancel_url'],
      'confirmation' => $form['#return_url'],
      'push' => $plugin->getNotifyUrl()->toString(),
    ];

    if ($order->getData('klarna_order_id')) {
      try {
        $klarna_order = $klarna_manager->updateOrder($order, $merchant_urls);
      }
      catch (\Exception $exception) {
        // the Klarna order ID might be invalid, proceed to creating a new one.
        $klarna_order = NULL;
      }
    }

    if (!isset($klarna_order)) {
      try {
        $klarna_order = $klarna_manager->createOrder($order, $merchant_urls);
        // @todo: Save the Klarna order ID in a custom table, to avoid saving
        // the order.
        $order->setData('klarna_order_id', $klarna_order->getId());
        $order->save();
      }
      catch (\Exception $exception) {
        throw new PaymentGatewayException($exception->getMessage());
      }
    }

    $form['klarna'] = [
      '#markup' => Markup::create($klarna_order['html_snippet']),
    ];

    return $form;
  }

}
