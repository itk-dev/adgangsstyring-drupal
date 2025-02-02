<?php

namespace Drupal\azure_ad_delta_sync;

use ItkDev\AzureAdDeltaSync\Handler\HandlerInterface;

/**
 * Controller.
 */
interface ControllerInterface {

  /**
   * Runs the Azure AD Delta Sync flow.
   *
   * @param \ItkDev\AzureAdDeltaSync\Handler\HandlerInterface $handler
   *   The handler.
   *
   * @throws \ItkDev\AzureAdDeltaSync\Exception\DataException
   */
  public function run(HandlerInterface $handler): void;

}
