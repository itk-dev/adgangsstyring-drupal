<?php

namespace Drupal\azure_ad_delta_sync;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Drupal\azure_ad_delta_sync\Helpers\ConfigHelper;

/**
 * User manager.
 */
class UserManager implements UserManagerInterface {
  use StringTranslationTrait;

  /**
   * The userIds.
   *
   * @var array|int[]
   *
   * @phpstan-var array<mixed, mixed>
   */
  private array $userIds;

  /**
   * The user storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private $userStorage;

  /**
   * The config helper.
   *
   * @var \ Drupal\azure_ad_delta_sync\Helpers\ConfigHelper
   */
  private $configHelper;

  /**
   * The oidc storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private $oidcStorage;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private $database;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * The options.
   *
   * @var array
   *
   * @phpstan-var array<mixed, mixed>
   */
  private array $options;

  /**
   * UserManager constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(EntityTypeManager $entityTypeManager, Connection $database, readonly RequestStack $requestStack, LoggerInterface $logger, ConfigHelper $configHelper) {
    $this->userStorage = $entityTypeManager->getStorage('user');
    $this->oidcStorage = $entityTypeManager->getStorage('openid_connect_client');
    $this->database = $database;
    $this->logger = $logger;
    $this->configHelper = $configHelper;
    $this->userIds = [];
    $this->validateConfig();
  }

  /**
   * {@inheritdoc}
   */
  public function setOptions(array $options): void {
    $this->options = $options;
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-return array<mixed, mixed>
   */
  public function loadManagedUserIds(): array {
    $managedUserIds = &drupal_static(__FUNCTION__);
    if (!isset($managedUserIds)) {
      $query = $this->userStorage->getQuery()
        ->accessCheck(FALSE);
      // Never delete user 0 and 1.
      $query->condition('uid', [0, 1], 'NOT IN');

      $providers = $this->configHelper->getProviders();
      if (!empty($providers)) {
        $orCondition = $query->orConditionGroup();
        foreach ($providers as $provider) {
          $providerUserIdQuery = $this->getProviderUserIdsQuery($provider);
          if (NULL !== $providerUserIdQuery) {
            $orCondition->condition('uid', $providerUserIdQuery, 'IN');
          }
        }
        $query->condition($orCondition);
      }
      $roles = $this->configHelper->getRoles();
      if (!empty($roles)) {
        $query->condition('roles', $roles, 'NOT IN');
      }

      $users = $this->configHelper->getUsers();
      if (!empty($users)) {
        $query->condition('uid', $users, 'NOT IN');
      }

      $managedUserIds = $query->execute();
    }

    return $managedUserIds;
  }

  /**
   * {@inheritdoc}
   */
  public function collectUsersForDeletionList(): void {
    $managedUserIds = $this->loadManagedUserIds();
    $this->userIds = $managedUserIds;
    $this->logger->info($this->formatPlural(
      count($this->userIds),
      '1 user marked for deletion.',
      '@count users marked for deletion.'
    ));
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<mixed, mixed> $users
   */
  public function removeUsersFromDeletionList(array $users): void {
    $userIdClaim = $this->configHelper->getConfiguration('azure.user_id_claim');
    $userIdField = $this->configHelper->getConfiguration('drupal.user_id_field');

    $this->logger->info($this->formatPlural(
      count($users),
      'Retaining one user.',
      'Retaining @count users.'
    ));
    $userIdsToKeep = array_map(
        static function (array $user) use ($userIdClaim) {
          if (!isset($user[$userIdClaim])) {
            throw new \RuntimeException(sprintf('Cannot get user id (%s)', $userIdClaim));
          }
          return $user[$userIdClaim];
        },
        $users
      );

    $this->logger->debug(json_encode($users, JSON_PRETTY_PRINT));

    $users = $this->userStorage->loadByProperties([$userIdField => $userIdsToKeep]);
    foreach ($users as $user) {
      $this->logger->info($this->t('Retaining user @name.', ['@name' => $user->label()]));
      unset($this->userIds[$user->id()]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function commitDeletionList(): void {
    $cancelMethod = $this->configHelper->getUserCancelMethod();
    $deletedUserIds = [];

    foreach ($this->userIds as $userId) {
      user_cancel([], $userId, $cancelMethod);
      $deletedUserIds[] = $userId;
    }

    if (0 !== count($deletedUserIds)) {
      $this->logger->info($this->formatPlural(
        count($deletedUserIds),
        'One user to be deleted',
        '@count users to be deleted'
      ));
      if ($this->options['debug'] ?? FALSE) {
        $users = $this->userStorage->loadMultiple($deletedUserIds);
        foreach ($users as $user) {
          $this->logger->debug(sprintf('User to be deleted: %s (#%s)', $user->label(), $user->id()));
        }
      }
    }

    if (!($this->options['dry-run'] ?? FALSE)) {
      if (!empty($deletedUserIds)) {
        $this->logger->info($this->formatPlural(
          count($deletedUserIds),
          'Deleting one user',
          'Deleting @count users'
        ));
        $this->requestStack->getCurrentRequest()
          // batch_process needs a route in the request (!)
          ->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('<none>'));

        // Process the batch created by deleteUser.
        $batch =& batch_get();
        $batch['progressive'] = FALSE;
        $batch['source_url'] = 'cron';

        batch_process();
      }
    }
  }

  /**
   * Get provider user ids select query.
   *
   * @param string $provider
   *   The provider.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The select query.
   */
  private function getProviderUserIdsQuery(string $provider): SelectInterface {
    return $this->database
      ->select('authmap')
      ->fields('authmap', ['uid'])
      ->condition('authmap.provider', $provider);
  }

  /**
   * Validate config.
   */
  private function validateConfig(): void {
    $required = [
      'azure.user_id_claim',
      'drupal.user_id_field',
    ];
    foreach ($required as $name) {
      if (empty($this->configHelper->getConfiguration($name))) {
        throw new \InvalidArgumentException(sprintf('Invalid or missing configuration in %s: %s', static::class, $name));
      }
    }
  }

}
