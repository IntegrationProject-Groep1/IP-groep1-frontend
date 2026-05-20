<?php

declare(strict_types=1);

namespace Drupal\session_enrollment\Service;

use Drupal\Core\Database\Database;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\rabbitmq_sender\CalendarInviteSender;
use Drupal\rabbitmq_receiver\CalendarInviteConfirmedReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Handles session enrollment: sends calendar.invite to Planning.
 */
class SessionEnrollmentService
{
    public function __construct(
        private readonly LoggerChannelFactoryInterface $loggerFactory,
        private readonly CalendarInviteSender $calendarInviteSender,
    ) {}

    /**
     * Enrolls a user in one or more sessions.
     *
     * For each session: sends calendar.invite to Planning (via calendar.exchange).
     *
     * @param array $userData  Must contain: email, user_id. Optional: master_uuid.
     * @param array $sessionIds  List of session UUIDs to enroll in.
     * @param array $sessionMap  Map of session_id => session data (from Drupal State).
     *
     * @throws \InvalidArgumentException When required user fields are missing.
     */
    public function enroll(array $userData, array $sessionIds, array $sessionMap): void
    {
        $logger = $this->loggerFactory->get('session_enrollment');

        if (empty($userData['email'])) {
            throw new \InvalidArgumentException('email is required for enrollment');
        }
        if (empty($userData['user_id'])) {
            throw new \InvalidArgumentException('user_id is required for enrollment');
        }
        if (empty($sessionIds)) {
            throw new \InvalidArgumentException('At least one session must be selected');
        }

        foreach ($sessionIds as $sessionId) {
            $sessionId = (string) $sessionId;

            if (!isset($sessionMap[$sessionId])) {
                $logger->warning('Sessie @id niet gevonden in Planning State, inschrijving overgeslagen.', [
                    '@id' => $sessionId,
                ]);
                continue;
            }

            $session = $sessionMap[$sessionId];

            // Notify Planning: calendar.invite
            if (empty($session['start_datetime']) || empty($session['end_datetime']) || empty($session['title'])) {
                $logger->warning('calendar.invite overgeslagen voor sessie @id: start_datetime/end_datetime/title ontbreekt.', [
                    '@id' => $sessionId,
                ]);
                continue;
            }

            $identityUuid = $userData['master_uuid'] ?? $userData['user_id'];

            // Write registration to MariaDB and increment attendee counter.
            try {
                $db = Database::getConnection();
                $db->merge('planning_registrations')
                    ->key(['session_id' => $sessionId, 'master_uuid' => $identityUuid])
                    ->fields(['status' => 'confirmed', 'registered_at' => date('c')])
                    ->execute();
                $db->update('planning_sessions')
                    ->expression('current_attendees', 'current_attendees + 1')
                    ->condition('session_id', $sessionId)
                    ->execute();
            } catch (\Throwable $e) {
                $logger->error('MariaDB registration write failed for session @id: @message', [
                    '@id' => $sessionId, '@message' => $e->getMessage(),
                ]);
            }

            // Notify Planning for Graph API (Outlook) + ICS feed.
            try {
                $client = new RabbitMQClient(
                    (string) (getenv('RABBITMQ_HOST') ?: 'rabbitmq_broker'),
                    (int)    (getenv('RABBITMQ_PORT') ?: 5672),
                    (string) (getenv('RABBITMQ_USER') ?: 'guest'),
                    (string) (getenv('RABBITMQ_PASS') ?: 'guest'),
                    (string) (getenv('RABBITMQ_VHOST') ?: '/')
                );
                $sender = new \Drupal\rabbitmq_sender\CalendarInviteSender($client);
                $sender->send([
                    'identity_uuid'  => $identityUuid,
                    'attendee_email' => $userData['email'],
                    'session_id'     => $sessionId,
                    'title'          => $session['title'],
                    'start_datetime' => $session['start_datetime'],
                    'end_datetime'   => $session['end_datetime'],
                    'location'       => $session['location'] ?? '',
                ]);

                // Poll briefly for calendar_invite_confirmed to get the ICS URL.
                $receiver = new CalendarInviteConfirmedReceiver($client);
                for ($i = 0; $i < 5; $i++) {
                    usleep(500000); // 0.5s
                    try {
                        $confirmed = $receiver->pollOnce();
                        if ($confirmed && !empty($confirmed['ics_url'])) {
                            $db->update('planning_registrations')
                                ->fields(['ics_url' => $confirmed['ics_url']])
                                ->condition('session_id', $sessionId)
                                ->condition('master_uuid', $identityUuid)
                                ->execute();
                            break;
                        }
                    } catch (\Throwable) {
                        break;
                    }
                }

                $logger->info('calendar.invite verstuurd naar Planning voor sessie @id.', ['@id' => $sessionId]);
            } catch (\Throwable $e) {
                $logger->error('calendar.invite mislukt voor sessie @id: @message', [
                    '@id' => $sessionId, '@message' => $e->getMessage(),
                ]);
            }
        }
    }
}
