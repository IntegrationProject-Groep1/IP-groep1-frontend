<?php

declare(strict_types=1);

namespace Drupal\session_enrollment\EventSubscriber;

use Drupal\Core\Database\Database;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Registers the 'planning' database connection on every request.
 *
 * settings.php is the canonical place for this, but when the running pod's
 * settings.php is served from a K8s ConfigMap that pre-dates the planning
 * connection block, the connection is absent and every call to
 * Database::getConnection('default', 'planning') throws "not defined".
 *
 * Using KernelEvents::REQUEST ensures this runs on every bootstrap (including
 * Drush) even when the DI container is cached — unlike a ServiceProvider's
 * register() method, which only runs during container compilation.
 */
class PlanningConnectionSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 300]];
  }

  public function onRequest(RequestEvent $event): void {
    if (Database::getConnectionInfo('planning')) {
      return;
    }

    Database::addConnectionInfo('planning', 'default', [
      'driver'    => 'mysql',
      'database'  => getenv('PLANNING_DB_NAME') ?: 'planning',
      'username'  => getenv('DRUPAL_DB_USER')   ?: 'drupal_user',
      'password'  => getenv('DRUPAL_DB_PASSWORD') ?: '',
      'host'      => getenv('DRUPAL_DB_HOST')   ?: 'frontend_db',
      'port'      => '3306',
      'prefix'    => '',
      'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
      'autoload'  => 'core/modules/mysql/src/Driver/Database/mysql/',
    ]);
  }

}
