<?php

declare(strict_types=1);

namespace Drupal\session_enrollment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Handles single-session enrollment from the session card "Enroll" button.
 */
class EnrollSingleController extends ControllerBase
{
    public function enroll(string $session_id): RedirectResponse
    {
        $currentUser  = $this->currentUser();
        $uid          = (int) $currentUser->id();
        $masterUuid   = (string) (\Drupal::service('user.data')->get('registration_form', $uid, 'master_uuid') ?? '');
        $identityUuid = $masterUuid !== '' ? $masterUuid : (string) $uid;

        $db = Database::getConnection('default', 'planning');

        // Load session data.
        $session = $db->query(
            "SELECT * FROM planning_sessions WHERE session_id = :id AND is_deleted = 0",
            [':id' => $session_id]
        )->fetchAssoc();

        if (!$session) {
            $this->messenger()->addError($this->t('Session not found.'));
            return new RedirectResponse(Url::fromRoute('session_enrollment.enroll')->toString());
        }

        // Check if already enrolled.
        $existing = $db->query(
            "SELECT status FROM planning_registrations WHERE session_id = :sid AND master_uuid = :uuid",
            [':sid' => $session_id, ':uuid' => $identityUuid]
        )->fetchAssoc();

        if ($existing && $existing['status'] === 'confirmed') {
            $this->messenger()->addWarning($this->t('You are already enrolled in "@title".', ['@title' => $session['title']]));
            return new RedirectResponse(Url::fromRoute('session_enrollment.my_sessions')->toString());
        }

        // Write registration to MariaDB.
        try {
            $db->merge('planning_registrations')
                ->key(['session_id' => $session_id, 'master_uuid' => $identityUuid])
                ->fields(['status' => 'confirmed', 'registered_at' => date('c')])
                ->execute();

            $db->update('planning_sessions')
                ->expression('current_attendees', 'current_attendees + 1')
                ->condition('session_id', $session_id)
                ->execute();
        } catch (\Throwable $e) {
            \Drupal::logger('session_enrollment')->error('EnrollSingle DB failed: @e', ['@e' => $e->getMessage()]);
            $this->messenger()->addError($this->t('Enrollment failed. Please try again.'));
            return new RedirectResponse(Url::fromRoute('session_enrollment.enroll')->toString());
        }

        // Notify planning for Graph API + ICS (non-blocking).
        try {
            $sender = new \Drupal\rabbitmq_sender\CalendarInviteSender();
            $sender->send([
                'identity_uuid'  => $identityUuid,
                'attendee_email' => $currentUser->getEmail(),
                'session_id'     => $session_id,
                'title'          => $session['title'],
                'start_datetime' => $session['start_datetime'],
                'end_datetime'   => $session['end_datetime'],
                'location'       => $session['location'] ?? '',
            ]);
        } catch (\Throwable $e) {
            \Drupal::logger('session_enrollment')->warning('EnrollSingle RabbitMQ failed (non-blocking): @e', ['@e' => $e->getMessage()]);
        }

        $this->messenger()->addStatus($this->t('You are enrolled in "@title".', ['@title' => $session['title']]));
        return new RedirectResponse(Url::fromRoute('session_enrollment.my_sessions')->toString());
    }
}
