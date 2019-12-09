<?php

namespace Drupal\commerce_klarna_checkout;

/**
 * Provides the Klarna manager factory interface.
 */
interface KlarnaManagerFactoryInterface {

  /**
   * Instantiate a new Klarna manager for the given config.
   *
   * @param array $configuration
   *   An associative array, containing at least these three keys:
   *   - mode: The API mode (e.g "test" or "live").
   *   - username: The API username.
   *   - password: The API password.
   *   - terms_path: The path to the terms and conditions page.
   *
   * @return \Drupal\commerce_klarna_checkout\KlarnaManagerInterface
   *   The Klarna manager.
   */
  public function get(array $configuration);

}
