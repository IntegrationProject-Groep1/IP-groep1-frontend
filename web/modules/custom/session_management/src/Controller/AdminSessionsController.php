<?php

declare(strict_types=1);

namespace Drupal\session_management\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\Core\Url;
use Drupal\session_management\Form\FestivalSettingsForm;

/**
 * Admin page: lists all sessions directly from MariaDB.
 */
class AdminSessionsController extends ControllerBase
{
    public function page(): array
    {
        $sessions = $this->loadSessionsFromDb();

        try {
            $createUrl = Url::fromRoute('session_management.create')->toString();
        } catch (\Exception) {
            $createUrl = '/session/create';
        }

        $festivalForm = \Drupal::formBuilder()->getForm(FestivalSettingsForm::class);
        $hasEvent     = !empty(\Drupal::config('shift_festival.settings')->get('festival_start_date'));

        return [
            '#theme'         => 'admin_sessions',
            '#sessions'      => $sessions,
            '#create_url'    => $createUrl,
            '#festival_form' => $festivalForm,
            '#has_event'     => $hasEvent,
            '#cache'         => ['max-age' => 0],
        ];
    }

    private function loadSessionsFromDb(): array
    {
        try {
            return Database::getConnection('default', 'planning')->query(
                "SELECT * FROM planning_sessions WHERE is_deleted = 0 ORDER BY start_datetime"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            \Drupal::logger('session_management')->error('Failed to load sessions: @e', ['@e' => $e->getMessage()]);
            return [];
        }
    }
}
