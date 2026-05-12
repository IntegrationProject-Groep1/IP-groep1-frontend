<?php

declare(strict_types=1);

namespace Drupal\session_management\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Central admin page: lists all Planning sessions with create / edit / delete actions.
 */
class AdminSessionsController extends ControllerBase
{
    public function page(): array
    {
        $this->refreshSessions();
        $sessions = \Drupal::state()->get('planning.sessions', []);

        usort($sessions, static function (array $a, array $b): int {
            return strcmp($a['start_datetime'] ?? '', $b['start_datetime'] ?? '');
        });

        try {
            $createUrl = Url::fromRoute('session_management.create')->toString();
        } catch (\Exception) {
            $createUrl = '/session/create';
        }

        return [
            '#theme'      => 'admin_sessions',
            '#sessions'   => $sessions,
            '#create_url' => $createUrl,
            '#cache'      => ['max-age' => 0],
        ];
    }

    /**
     * Refresh the sessions cache from Planning via RabbitMQ (same strategy as SessionEnrollForm).
     */
    private function refreshSessions(): void
    {
        try {
            $client   = new \Drupal\rabbitmq_sender\RabbitMQClient();
            $receiver = new \Drupal\rabbitmq_receiver\SessionViewResponseReceiver($client);

            for ($i = 0; $i < 3; $i++) {
                if ($receiver->pollOnce()) {
                    (new \Drupal\rabbitmq_sender\SessionViewRequestSender($client))->send();
                    return;
                }
            }

            (new \Drupal\rabbitmq_sender\SessionViewRequestSender($client))->send();

            for ($i = 0; $i < 5; $i++) {
                if ($receiver->pollOnce()) {
                    return;
                }
            }
        } catch (\Throwable $e) {
            \Drupal::logger('session_management')->warning(
                'Admin: could not refresh sessions from Planning: @error',
                ['@error' => $e->getMessage()]
            );
        }
    }
}
