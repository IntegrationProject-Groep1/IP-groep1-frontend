<?php

declare(strict_types=1);

namespace Drupal\session_management\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Renders the admin sessions overview page at /session/admin.
 *
 * Reads session data from Drupal State (populated by SessionViewResponseReceiver).
 */
class AdminSessionsController extends ControllerBase
{
    public function page(): array
    {
        $sessions = \Drupal::state()->get('planning.sessions', []);

        return [
            '#theme'    => 'admin_sessions',
            '#sessions' => $sessions,
            '#cache'    => ['max-age' => 0],
        ];
    }
}
