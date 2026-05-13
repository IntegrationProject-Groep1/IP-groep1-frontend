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

        // Pre-drain strategy (same pattern as SessionEnrollForm):
        // Planning takes ~60s to respond, so the response from the previous page load
        // is likely already waiting in the queue by the time the user refreshes.
        $sessions = $this->fetchSessions($identityUuid);

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
     * Pre-drain strategy for fetching user sessions from Planning.
     *
     * Planning takes ~60s to respond. By the time the user revisits the page,
     * the response from the previous request is likely already waiting in the queue.
     *
     * Strategy:
     *  1. Drain ALL pending responses from the queue (each stored under its own identity_uuid).
     *  2. If a response for THIS user arrived → send a fresh request for next load, return cached data.
     *  3. If nothing for this user → send a fresh request, poll a few more times.
     *  4. Always fall back to whatever is in Drupal state from a previous successful fetch.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchSessions(string $identityUuid): array
    {
        $stateKey = 'user_sessions.' . $identityUuid;

        try {
            $client   = new RabbitMQClient(
                (string) (getenv('RABBITMQ_HOST') ?: 'rabbitmq_broker'),
                (int)    (getenv('RABBITMQ_PORT') ?: 5672),
                (string) (getenv('RABBITMQ_USER') ?: 'guest'),
                (string) (getenv('RABBITMQ_PASS') ?: 'guest'),
                (string) (getenv('RABBITMQ_VHOST') ?: '/')
            );
            $receiver = new UserSessionsResponseReceiver($client);
            $sender   = new UserSessionsRequestSender($client);

            // Step 1: drain all pending responses (stores each under its own identity_uuid key).
            $gotOwnResponse = false;
            for ($i = 0; $i < 10; $i++) {
                $result = $receiver->pollOnce();
                if ($result === null) {
                    break;
                }
                // storeResult is called inside pollOnce already; check if it's ours.
                if (($result['identity_uuid'] ?? '') === $identityUuid) {
                    $gotOwnResponse = true;
                }
            }

            if ($gotOwnResponse) {
                // Got fresh data — send a new request for the next page load.
                $sender->send($identityUuid);
                $cached = \Drupal::state()->get($stateKey, []);
                return (array) ($cached['sessions'] ?? []);
            }

            // Step 2: nothing for this user yet — send a fresh request.
            $sender->send($identityUuid);

            // Step 3: poll briefly in case Planning responds quickly.
            for ($i = 0; $i < self::MAX_POLL_ATTEMPTS; $i++) {
                $result = $receiver->pollOnce();
                if ($result !== null && ($result['identity_uuid'] ?? '') === $identityUuid) {
                    return $result['sessions'];
                }
            }
        } catch (\Throwable $e) {
            \Drupal::logger('session_enrollment')->warning(
                'Could not fetch user sessions from Planning: @error',
                ['@error' => $e->getMessage()]
            );
        }

        // Fall back to whatever is in state from a previous successful fetch.
        $cached = \Drupal::state()->get($stateKey, []);
        return (array) ($cached['sessions'] ?? []);
    }
}
