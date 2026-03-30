<?php

declare(strict_types=1);

namespace Drupal\registration_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;

class ConfirmationController extends ControllerBase
{
    public function page(Request $request): array
    {
        $session = $request->query->get('session', '');
        $name    = $request->query->get('name', '');

        return [
            '#theme'   => 'registration_confirmation',
            '#name'    => $name,
            '#session' => $session,
        ];
    }
}
