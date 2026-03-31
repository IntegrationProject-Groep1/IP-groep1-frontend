<?php

declare(strict_types=1);

namespace Drupal\session_management\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Renders the session creation confirmation page.
 */
class SessionConfirmationController extends ControllerBase
{
    public function page(): array
    {
        return [
            '#markup' => $this->t('<p>Your session has been created and sent to the Planning service.</p>'),
        ];
    }
}
