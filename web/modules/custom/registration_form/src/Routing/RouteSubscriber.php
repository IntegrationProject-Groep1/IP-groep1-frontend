<?php

namespace Drupal\registration_form\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Removes _admin_route from the user edit form so the frontend theme is used.
 */
class RouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('entity.user.edit_form');
    if ($route) {
      $route->setOption('_admin_route', FALSE);
    }
  }

}
