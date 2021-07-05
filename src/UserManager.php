<?php

namespace Drupal\adgangsstyring;

use Drupal\adgangsstyring\Form\SettingsForm;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * User manager.
 */
class UserManager {
  private const MODULE = 'adgangsstyring';
  private const MARKER = 'delete';

  /**
   * The user data.
   *
   * @var \Drupal\user\UserDataInterface
   */
  private $userData;

  /**
   * The user storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private $userStorage;

  /**
   * The module config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $moduleConfig;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * UserManager constructor.
   *
   * @param \Drupal\user\UserDataInterface $userData
   *   The user data.
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(UserDataInterface $userData, EntityTypeManager $entityTypeManager, ConfigFactoryInterface $configFactory, LoggerInterface $logger) {
    $this->userData = $userData;
    $this->userStorage = $entityTypeManager->getStorage('user');
    $this->moduleConfig = $configFactory->get(SettingsForm::SETTINGS);
    $this->logger = $logger;
  }

  /**
   * Get user ids.
   */
  public function getUserIds() {
    $userIds = &drupal_static(__FUNCTION__);
    if (!isset($userIds)) {
      $userIds = $this->userStorage->getQuery()->execute();

      unset($userIds[0]);

      var_export($userIds);
    }

    return $userIds;
  }

  /**
   * Mark users for deletion.
   */
  public function markUsersForDeletion() {
    $userIds = $this->getUserIds();
    $now = (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM);
    foreach ($userIds as $userId) {
      $this->userData->set(self::MODULE, $userId, self::MARKER, $now);
    }
  }

  /**
   * Retain users.
   *
   * @param array $users
   *   The users to retain.
   */
  public function retainUsers(array $users) {
    foreach ($users as $user) {
      $username = $user['userPrincipalName'] ?? NULL;
      $user = $this->loadUserByProperties(['name' => $username]);
      if (NULL !== $user) {
        $this->logger->info(sprintf('#users: %s', $user->getAccountName()));
        $this->userData->delete(self::MODULE, $user->id(), self::MARKER);
      }
    }
  }

  /**
   * Delete users.
   */
  public function deleteUsers() {
    $userIds = $this->getUserIds();
    foreach ($userIds as $userId) {
      $marker = $this->userData->get(self::MODULE, $userId, self::MARKER);
      if (NULL !== $marker) {
        $user = $this->userStorage->load($userId);
        $this->deleteUser($user);
      }
    }
  }

  /**
   * Load user by properties.
   *
   * @param array $properties
   *   The properties.
   *
   * @return \Drupal\user\UserInterface|null
   *   The user if any.
   */
  private function loadUserByProperties(array $properties = []): ?UserInterface {
    /** @var \Drupal\user\UserInterface[] $users */
    $users = $this->userStorage->loadByProperties($properties);

    return reset($users) ?: NULL;
  }

  /**
   * Delete user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function deleteUser(UserInterface $user) {
    $user->delete();
  }

}
