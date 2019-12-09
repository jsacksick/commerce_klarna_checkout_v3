<?php

namespace Drupal\commerce_klarna_checkout\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_klarna_checkout\KlarnaManagerFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Klarna checkout completion snippet.
 *
 * @CommerceCheckoutPane(
 *   id = "commerce_klarna_checkout_completion",
 *   label = @Translation("Klarna Confirmation message"),
 *   default_step = "complete",
 * )
 */
class KlarnaCompletion extends CheckoutPaneBase {

  /**
   * The Klarna manager factory.
   *
   * @var \Drupal\commerce_klarna_checkout\KlarnaManagerFactoryInterface
   */
  protected $klarnaManagerFactory;

  /**
   * Constructs a new KlarnaCompletion object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface $checkout_flow
   *   The parent checkout flow.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_klarna_checkout\KlarnaManagerFactoryInterface $klarna_manager_factory
   *   The Klarna manager factory.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, CheckoutFlowInterface $checkout_flow, EntityTypeManagerInterface $entity_type_manager, KlarnaManagerFactoryInterface $klarna_manager_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $checkout_flow, $entity_type_manager);

    $this->klarnaManagerFactory = $klarna_manager_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, CheckoutFlowInterface $checkout_flow = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $checkout_flow,
      $container->get('entity_type.manager'),
      $container->get('commerce_klarna_checkout.manager_factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    $klarna_order_id = $this->order->getData('klarna_order_id');

    if (!$klarna_order_id) {
      return [];
    }
    try {
      /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
      $payment_gateway = $this->order->payment_gateway->entity;
      /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
      $payment_gateway_plugin = $payment_gateway->getPlugin();
      $klarna_manager = $this->klarnaManagerFactory->get($payment_gateway_plugin->getConfiguration());
      $klarna_order = $klarna_manager->getOrder($klarna_order_id);

      // @todo: Acknowledge the order from there.
      $pane_form['klarna_completion'] = [
        '#markup' => Markup::create($klarna_order['html_snippet']),
      ];
      return $pane_form;
    }
    catch (\Exception $exception) {
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isVisible() {
    if (!$this->order->hasField('payment_gateway') || $this->order->payment_gateway->isEmpty()) {
      return FALSE;
    }
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    /** @noinspection PhpUndefinedFieldInspection */
    $payment_gateway = $this->order->payment_gateway->entity;
    return $payment_gateway && $payment_gateway->getPluginId() == 'klarna_checkout';
  }

}
