<?php

namespace Drupal\commerce_klarna_checkout;

use Drupal\commerce_order\AdjustmentTransformerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Defines a factory for the Klarna manager.
 */
class KlarnaManagerFactory implements KlarnaManagerFactoryInterface {

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
   * Array of all instantiated Klarna managers.
   *
   * @var \Drupal\commerce_klarna_checkout\KlarnaManagerInterface[]
   */
  protected $instances = [];

  /**
   * Constructs a new KlarnaManagerFactory object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_order\AdjustmentTransformerInterface $adjustment_transformer
   *   The adjustment transformer.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AdjustmentTransformerInterface $adjustment_transformer, EventDispatcherInterface $event_dispatcher) {
    $this->entityTypeManager = $entity_type_manager;
    $this->adjustmentTransformer = $adjustment_transformer;
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public function get(array $configuration) {
    $username = $configuration['username'];
    if (!isset($this->instances[$username])) {
      $this->instances[$username] = new KlarnaManager($this->entityTypeManager, $this->adjustmentTransformer, $this->eventDispatcher, $configuration);
    }

    return $this->instances[$username];
  }

}
