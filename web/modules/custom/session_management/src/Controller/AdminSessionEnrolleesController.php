<?php

declare(strict_types=1);

namespace Drupal\session_management\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Renders the enrollees overview for a single session at /session/{session_id}/enrollees.
 */
class AdminSessionEnrolleesController extends ControllerBase
{
    public function page(string $session_id): array
    {
        $sessions  = \Drupal::state()->get('planning.sessions', []);
        $session   = null;

        foreach ($sessions as $s) {
            if (isset($s['session_id']) && (string) $s['session_id'] === $session_id) {
                $session = $s;
                break;
            }
        }

        $allEnrollees = \Drupal::state()->get('planning.enrollees', []);
        $enrollees    = array_values(array_filter(
            $allEnrollees,
            fn(array $e) => isset($e['session_id']) && (string) $e['session_id'] === $session_id,
        ));

        return [
            '#theme'      => 'admin_session_enrollees',
            '#session'    => $session,
            '#session_id' => $session_id,
            '#enrollees'  => $enrollees,
            '#cache'      => ['max-age' => 0],
        ];
    }
}
