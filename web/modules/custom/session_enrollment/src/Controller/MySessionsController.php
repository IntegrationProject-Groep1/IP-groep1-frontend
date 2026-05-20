<?php
declare(strict_types=1);

namespace Drupal\session_enrollment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Renders the "My Sessions" personal page and handles unsubscribe actions.
 * Renders the "My Sessions" page and handles unsubscribe actions.
 */
class MySessionsController extends ControllerBase
{
    public static function create(ContainerInterface $container): static
    {
        return new static();
    }

    public function page(): array
    {
        $currentUser = $this->currentUser();
        $uid         = (int) $currentUser->id();

        /** @var \Drupal\user\UserStorageInterface $userStorage */
        $account   = $this->entityTypeManager()->getStorage('user')->load($uid);
        $firstName = '';
        if ($account instanceof \Drupal\user\UserInterface) {
            $firstName = $account->hasField('field_first_name')
                ? (string) $account->get('field_first_name')->value
                : $account->getAccountName();
        }

        $storedUuid   = (string) (\Drupal::service('user.data')
            ->get('registration_form', $uid, 'master_uuid') ?? '');
        // Fall back to Drupal user ID — matches the fallback used during enrollment.
        $identityUuid = $storedUuid !== '' ? $storedUuid : (string) $uid;

        $sessions = $this->fetchSessions($identityUuid);
        $icsUrl   = $this->fetchIcsUrl($identityUuid);

        return [
            '#theme'            => 'my_sessions',
            '#name'             => $firstName,
            '#sessions'         => $sessions,
            '#grouped_sessions' => $this->groupByDay($sessions),
            '#ics_url'          => $icsUrl,
            '#notice'           => null,
            '#cache'            => ['contexts' => ['user'], 'max-age' => 0],
        ];
    }

    /**
     * POST: cancel registration in MariaDB + notify planning (Graph API cleanup).
     */
    public function unsubscribe(string $session_id): RedirectResponse
    {
        $uid          = (int) $this->currentUser()->id();
        $storedUuid   = (string) (\Drupal::service('user.data')
            ->get('registration_form', $uid, 'master_uuid') ?? '');
        $identityUuid = $storedUuid !== '' ? $storedUuid : (string) $uid;

        $db = Database::getConnection();

        $db->update('planning_registrations')
            ->fields(['status' => 'cancelled'])
            ->condition('session_id', $session_id)
            ->condition('master_uuid', $identityUuid)
            ->execute();

        $db->update('planning_sessions')
            ->expression('current_attendees', 'GREATEST(current_attendees - 1, 0)')
            ->condition('session_id', $session_id)
            ->execute();

        try {
            (new \Drupal\rabbitmq_sender\CancelRegistrationSender())->send($session_id, $identityUuid);
            $this->messenger()->addStatus($this->t('You have been unsubscribed from the session.'));
        } catch (\Throwable $e) {
            \Drupal::logger('session_enrollment')->error('Unsubscribe RabbitMQ error: @e', ['@e' => $e->getMessage()]);
            $this->messenger()->addWarning($this->t('Unsubscribed, but calendar removal may be delayed.'));
        }

        return new RedirectResponse(Url::fromRoute('session_enrollment.my_sessions')->toString());
    }

    /**
     * Get the ICS URL stored in planning_registrations.
     * Get the ICS URL stored in planning_registrations (set after calendar_invite_confirmed).
     */
    private function fetchIcsUrl(string $identityUuid): string
    {
        try {
            $db  = Database::getConnection();
            $row = $db->query(
                "SELECT ics_url FROM planning_registrations
                 WHERE master_uuid = :uuid AND ics_url IS NOT NULL
                 LIMIT 1",
                [':uuid' => $identityUuid]
            )->fetchAssoc();
            return $row['ics_url'] ?? '';
        } catch (\Throwable $e) {
            \Drupal::logger('session_enrollment')->warning('ICS URL lookup failed: @e', ['@e' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Query enrolled sessions directly from MariaDB.
     * Query enrolled sessions directly from MariaDB (planning_sessions + planning_registrations).
     *
     * @return list<array<string,mixed>>
     */
    private function fetchSessions(string $identityUuid): array
    {
        try {
            $db   = Database::getConnection();
            $rows = $db->query(
                "SELECT s.session_id, s.title, s.start_datetime, s.end_datetime,
                        s.location, s.session_type, s.status,
                        s.max_attendees, s.current_attendees, s.price
                 FROM planning_sessions s
                 INNER JOIN planning_registrations r ON r.session_id = s.session_id
                 WHERE r.master_uuid = :uuid
                   AND r.status = 'confirmed'
                   AND s.is_deleted = 0
                 ORDER BY s.start_datetime",
                [':uuid' => $identityUuid]
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as &$session) {
                $session['unsubscribe_url'] = Url::fromRoute(
                    'session_enrollment.unsubscribe',
                    ['session_id' => $session['session_id']]
                )->toString();
            }

            return $rows;
        } catch (\Throwable $e) {
            \Drupal::logger('session_enrollment')->error('fetchSessions failed: @e', ['@e' => $e->getMessage()]);
            return [];
        }
    }

    /**
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
                $dayKey  = $startDt->format('l, d F Y');
                $session['formatted_start'] = $startDt->format('H:i');
            } catch (\Throwable) {
                $dayKey = 'Unscheduled';
                $session['formatted_start'] = $startRaw;
            }

            try {
                $session['formatted_end'] = (new \DateTimeImmutable($endRaw))->format('H:i');
            } catch (\Throwable) {
                $session['formatted_end'] = $endRaw;
            }

            $grouped[$dayKey][] = $session;
        }
        return $grouped;
    }
}
