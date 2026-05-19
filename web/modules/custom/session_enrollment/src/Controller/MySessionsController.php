<?php
declare(strict_types=1);

namespace Drupal\session_enrollment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\rabbitmq_receiver\UserSessionsResponseReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;
use Drupal\rabbitmq_sender\UserSessionsRequestSender;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the "My Sessions" personal page.
 *
 * Flow:
 *  1. Resolve master_uuid from user.data (set during Identity Service registration).
 *  2. Send user_sessions_request to Planning with a new correlation_id.
 *  3. Poll the user_sessions_response queue up to MAX_POLL_ATTEMPTS times.
 *  4. If a response arrives (any correlation), store it and display it.
 *  5. Otherwise fall back to the last cached result from state.
 */
class MySessionsController extends ControllerBase
{
    /** Maximum number of non-blocking poll attempts before falling back to cache. */
    private const MAX_POLL_ATTEMPTS = 5;

    public static function create(ContainerInterface $container): static
    {
        return new static();
    }

    public function page(): array
    {
        $currentUser = $this->currentUser();
        $uid         = (int) $currentUser->id();

        // Resolve display name.
        /** @var \Drupal\user\UserStorageInterface $userStorage */
        $userStorage = $this->entityTypeManager()->getStorage('user');
        $account     = $userStorage->load($uid);
        $firstName   = '';
        if ($account instanceof \Drupal\user\UserInterface) {
            $firstName = $account->hasField('field_first_name')
                ? (string) $account->get('field_first_name')->value
                : $account->getAccountName();
        }

        // Resolve identity_uuid (master_uuid stored during Identity Service registration).
        /** @var \Drupal\user\UserDataInterface $userData */
        $userData      = \Drupal::service('user.data');
        $identityUuid  = (string) ($userData->get('registration_form', $uid, 'master_uuid') ?? '');

        if ($identityUuid === '') {
            // User has not completed registration via the Identity Service yet.
            return [
                '#theme'             => 'my_sessions',
                '#name'             => $firstName,
                '#sessions'         => [],
                '#grouped_sessions' => [],
                '#notice'           => $this->t('Your account registration is not yet complete. Please finish registration to view your sessions.'),
            ];
        }

        // Send a fresh user_sessions_request.
        $correlationId = $this->sendRequest($identityUuid);

        // Poll for the response.
        $sessions = $this->pollForResponse($identityUuid, $correlationId);

        return [
            '#theme'             => 'my_sessions',
            '#name'              => $firstName,
            '#sessions'          => $sessions,
            '#grouped_sessions'  => $this->groupByDay($sessions),
            '#notice'            => null,
        ];
    }

    /**
     * Group sessions by calendar day and add formatted time fields.
     *
     * @param list<array<string,mixed>> $sessions
     * @return array<string, list<array<string,mixed>>>
     */
    private function groupByDay(array $sessions): array
    {
        $grouped = [];
        foreach ($sessions as $session) {
            $startRaw = $session['start_datetime'] ?? '';
            $endRaw   = $session['end_datetime']   ?? '';

            try {
                $startDt = new \DateTimeImmutable($startRaw);
                $dayKey  = $startDt->format('l, d F Y'); // e.g. "Tuesday, 12 May 2026"
                $session['formatted_start'] = $startDt->format('H:i');
            } catch (\Throwable) {
                $dayKey = 'Unscheduled';
                $session['formatted_start'] = $startRaw;
            }

            try {
                $endDt = new \DateTimeImmutable($endRaw);
                $session['formatted_end'] = $endDt->format('H:i');
            } catch (\Throwable) {
                $session['formatted_end'] = $endRaw;
            }

            $grouped[$dayKey][] = $session;
        }
        return $grouped;
    }

    /**
     * Send a user_sessions_request and return the correlation_id.
     */
    private function sendRequest(string $identityUuid): string
    {
        try {
            $client = new RabbitMQClient(
                (string) (getenv('RABBITMQ_HOST') ?: 'rabbitmq_broker'),
                (int)    (getenv('RABBITMQ_PORT') ?: 5672),
                (string) (getenv('RABBITMQ_USER') ?: 'guest'),
                (string) (getenv('RABBITMQ_PASS') ?: 'guest'),
                (string) (getenv('RABBITMQ_VHOST') ?: '/')
            );
            $sender = new UserSessionsRequestSender($client);
            return $sender->send($identityUuid);
        } catch (\Throwable $e) {
            \Drupal::logger('session_enrollment')->error(
                'Failed to send user_sessions_request: @error',
                ['@error' => $e->getMessage()]
            );
            return '';
        }
    }

    /**
     * Poll the user_sessions_response queue and return the session list.
     *
     * Tries up to MAX_POLL_ATTEMPTS times (non-blocking basic_get).
     * Accepts the first response that arrives, regardless of correlation_id,
     * because the dedicated queue is per-frontend and responses arrive in order.
     *
     * Falls back to the last cached result in Drupal state if no live response comes.
     *
     * @return list<array<string,mixed>>
     */
    private function pollForResponse(string $identityUuid, string $correlationId): array
    {
        $stateKey = 'user_sessions.' . $identityUuid;

        try {
            $client = new RabbitMQClient(
                (string) (getenv('RABBITMQ_HOST') ?: 'rabbitmq_broker'),
                (int)    (getenv('RABBITMQ_PORT') ?: 5672),
                (string) (getenv('RABBITMQ_USER') ?: 'guest'),
                (string) (getenv('RABBITMQ_PASS') ?: 'guest'),
                (string) (getenv('RABBITMQ_VHOST') ?: '/')
            );
            $receiver = new UserSessionsResponseReceiver($client);

            for ($i = 0; $i < self::MAX_POLL_ATTEMPTS; $i++) {
                $result = $receiver->pollOnce();
                if ($result !== null) {
                    // Store for later (cron + cache fallback).
                    \Drupal::state()->set($stateKey, [
                        'sessions'       => $result['sessions'],
                        'status'         => $result['status'],
                        'correlation_id' => $result['correlation_id'],
                        'fetched_at'     => time(),
                    ]);
                    return $result['sessions'];
                }
            }
        } catch (\Throwable $e) {
            \Drupal::logger('session_enrollment')->error(
                'Failed to poll user_sessions_response: @error',
                ['@error' => $e->getMessage()]
            );
        }

        // Fall back to cached sessions from the last successful fetch.
        $cached = \Drupal::state()->get($stateKey, []);
        return (array) ($cached['sessions'] ?? []);
    }
}
