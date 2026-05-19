<?php

declare(strict_types=1);

namespace Drupal\session_enrollment\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\rabbitmq_sender\CalendarInviteSender;
use Drupal\rabbitmq_sender\UserRegisteredSender;

/**
 * Handles session enrollment: sends calendar.invite to Planning and user_registered to CRM.
 */
class SessionEnrollmentService
{
    public function __construct(
        private readonly LoggerChannelFactoryInterface $loggerFactory,
        private readonly CalendarInviteSender $calendarInviteSender,
        private readonly UserRegisteredSender $userRegisteredSender,
    ) {}

    /**
     * Enrolls a user in one or more sessions.
     *
     * For each session:
     *  - Sends calendar.invite to Planning (via calendar.exchange).
     *  - Sends user_registered to CRM (via crm.incoming queue).
     *
     * @param array $userData    Must contain: email, user_id, master_uuid. Optional: first_name, last_name, is_company, company_name, vat_number.
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

        $identityUuid = (string) ($userData['master_uuid'] ?? '');
        $isValidUuid  = (bool) preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
            $identityUuid
        );

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
            } else {
                try {
                    $this->calendarInviteSender->send([
                        'identity_uuid'  => $identityUuid ?: $userData['user_id'],
                        'attendee_email' => $userData['email'],
                        'session_id'     => $sessionId,
                        'title'          => $session['title'],
                        'start_datetime' => $session['start_datetime'],
                        'end_datetime'   => $session['end_datetime'],
                        'location'       => $session['location'] ?? '',
                    ]);
                    $logger->info('calendar.invite verstuurd naar Planning voor sessie @id.', ['@id' => $sessionId]);
                } catch (\Throwable $e) {
                    $logger->error('calendar.invite mislukt voor sessie @id: @message', [
                        '@id'      => $sessionId,
                        '@message' => $e->getMessage(),
                    ]);
                }
            }

            // Notify CRM: user_registered
            if ($isValidUuid) {
                try {
                    $this->userRegisteredSender->send([
                        'identity_uuid' => $identityUuid,
                        'email'         => $userData['email'],
                        'first_name'    => $userData['first_name'] ?? '',
                        'last_name'     => $userData['last_name'] ?? '',
                        'is_company'    => $userData['is_company'] ?? false,
                        'company_name'  => $userData['company_name'] ?? '',
                        'vat_number'    => $userData['vat_number'] ?? '',
                        'session_id'    => $sessionId,
                        'session_title' => $session['title'] ?? '',
                    ]);
                    $logger->info('user_registered verstuurd naar CRM voor sessie @id.', ['@id' => $sessionId]);
                } catch (\Throwable $e) {
                    $logger->error('user_registered mislukt voor sessie @id: @message', [
                        '@id'      => $sessionId,
                        '@message' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
