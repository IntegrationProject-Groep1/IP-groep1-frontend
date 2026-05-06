<?php

declare(strict_types=1);

namespace Drupal\session_enrollment\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\rabbitmq_sender\CalendarInviteSender;
use Drupal\rabbitmq_sender\NewRegistrationSender;
use Drupal\rabbitmq_sender\UserRegisteredSender;

/**
 * Handles session enrollment: notifies CRM (new_registration) and Planning (calendar.invite).
 */
class SessionEnrollmentService
{
    public function __construct(
        private readonly LoggerChannelFactoryInterface $loggerFactory,
        private readonly NewRegistrationSender $newRegistrationSender,
        private readonly CalendarInviteSender $calendarInviteSender,
        private readonly ?UserRegisteredSender $userRegisteredSender = null,
    ) {}

    /**
     * Enrolls a user in one or more sessions.
     *
     * For each session:
     *  1. Sends user.registered to CRM (via frontend.user.registered queue)
     *  2. Sends calendar.invite to Planning (via calendar.exchange)
     *
     * @param array $userData  Must contain: email, user_id, first_name, last_name, date_of_birth.
     *                         Optional: is_company, vat_number, address, registration_fee.
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
        if (empty($userData['first_name'])) {
            throw new \InvalidArgumentException('first_name is required for enrollment');
        }
        if (empty($userData['last_name'])) {
            throw new \InvalidArgumentException('last_name is required for enrollment');
        }
        if (empty($userData['date_of_birth'])) {
            throw new \InvalidArgumentException('date_of_birth is required for enrollment');
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

            // Notify CRM: new_registration (one per session per the XSD contract)
            try {
                $this->newRegistrationSender->send([
                    'identity_uuid' => $userData['master_uuid'] ?? $userData['user_id'],
                    'email'         => $userData['email'],
                    'user_id'       => $userData['user_id'],
                    'first_name'    => $userData['first_name'],
                    'last_name'     => $userData['last_name'],
                    'date_of_birth' => $userData['date_of_birth'],
                    'is_company'    => (bool) ($userData['is_company'] ?? false),
                    'vat_number'    => $userData['vat_number'] ?? '',
                    'session_id'    => $sessionId,
                ]);
                $logger->info('new_registration verstuurd naar CRM voor @email / sessie @id.', [
                    '@email' => $userData['email'],
                    '@id'    => $sessionId,
                ]);
            } catch (\Throwable $e) {
                $logger->error('new_registration mislukt voor sessie @id: @message', [
                    '@id'      => $sessionId,
                    '@message' => $e->getMessage(),
                ]);
                throw new \RuntimeException('Enrollment notification to CRM failed for session ' . $sessionId . ': ' . $e->getMessage(), 0, $e);
            }

            // Notify Planning: calendar.invite
            if (empty($session['start_datetime']) || empty($session['end_datetime']) || empty($session['title'])) {
                $logger->warning('calendar.invite overgeslagen voor sessie @id: start_datetime/end_datetime/title ontbreekt.', [
                    '@id' => $sessionId,
                ]);
            } else {
                try {
                    $this->calendarInviteSender->send([
                        'user_id'        => $userData['user_id'],
                        'attendee_email' => $userData['email'],
                        'session_id'     => $sessionId,
                        'title'          => $session['title'],
                        'start_datetime' => $session['start_datetime'],
                        'end_datetime'   => $session['end_datetime'],
                        'location'       => $session['location'] ?? '',
                    ]);
                    $logger->info('calendar.invite verstuurd naar Planning voor sessie @id.', [
                        '@id' => $sessionId,
                    ]);
                } catch (\Throwable $e) {
                    // Non-fatal: CRM is already notified; log and continue.
                    $logger->error('calendar.invite mislukt voor sessie @id: @message', [
                        '@id'      => $sessionId,
                        '@message' => $e->getMessage(),
                    ]);
                }
            }

            // Notify CRM: user_registered (one per session)
            if ($this->userRegisteredSender === null) {
                continue;
            }
            try {
                $this->userRegisteredSender->send([
                    'identity_uuid' => $userData['master_uuid'] ?? $userData['user_id'],
                    'user_id'       => $userData['user_id'],
                    'email'         => $userData['email'],
                    'first_name'    => $userData['first_name'] ?? '',
                    'last_name'     => $userData['last_name'] ?? '',
                    'is_company'    => (bool) ($userData['is_company'] ?? false),
                    'company_name'  => $userData['company_name'] ?? '',
                    'vat_number'    => $userData['vat_number'] ?? '',
                    'session_id'    => $sessionId,
                    'session_title' => $session['title'],
                ]);
                $logger->info('user_registered verstuurd naar CRM voor @email / sessie @id.', [
                    '@email' => $userData['email'],
                    '@id'    => $sessionId,
                ]);
            } catch (\Throwable $e) {
                // Non-fatal: log and continue.
                $logger->error('user_registered mislukt voor sessie @id: @message', [
                    '@id'      => $sessionId,
                    '@message' => $e->getMessage(),
                ]);
            }
        }
    }
}
