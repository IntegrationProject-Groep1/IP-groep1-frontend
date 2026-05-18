<?php

declare(strict_types=1);

namespace Drupal\session_enrollment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\rabbitmq_receiver\SessionViewResponseReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;
use Drupal\rabbitmq_sender\SessionViewRequestSender;

/**
 * Renders the public session programme with per-session enroll buttons.
 */
class SessionsOverviewController extends ControllerBase
{
    private const MAX_POLL_ATTEMPTS = 5;

    public function page(): array
    {
        $sessions = $this->fetchSessions();

        return [
            '#theme'    => 'sessions_overview',
            '#sessions' => $sessions,
        ];
    }

    /**
     * Fetches available sessions from Planning, falling back to Drupal state cache.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchSessions(): array
    {
        try {
            $client   = new RabbitMQClient(
                (string) (getenv('RABBITMQ_HOST') ?: 'rabbitmq_broker'),
                (int)    (getenv('RABBITMQ_PORT') ?: 5672),
                (string) (getenv('RABBITMQ_USER') ?: 'guest'),
                (string) (getenv('RABBITMQ_PASS') ?: 'guest'),
                (string) (getenv('RABBITMQ_VHOST') ?: '/')
            );
            $receiver = new SessionViewResponseReceiver($client);

            // Drain any already-waiting response first.
            for ($i = 0; $i < 3; $i++) {
                if ($receiver->pollOnce()) {
                    (new SessionViewRequestSender($client))->send();
                    return array_values(\Drupal::state()->get('planning.sessions', []));
                }
            }

            // Send a fresh request.
            (new SessionViewRequestSender($client))->send();

            // Poll briefly in case Planning responds quickly.
            for ($i = 0; $i < self::MAX_POLL_ATTEMPTS; $i++) {
                if ($receiver->pollOnce()) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            \Drupal::logger('session_enrollment')->warning(
                'SessionsOverview: could not fetch sessions from Planning: @error',
                ['@error' => $e->getMessage()]
            );
        }

        return array_values(\Drupal::state()->get('planning.sessions', []));
    }
}
