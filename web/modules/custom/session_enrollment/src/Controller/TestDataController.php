<?php

declare(strict_types=1);

namespace Drupal\session_enrollment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Seeds fake sessions into Drupal state for local testing.
 *
 * Visit /admin/sessions/seed-test to inject test data.
 * Visit /admin/sessions/seed-test?clear=1 to wipe the test data.
 */
class TestDataController extends ControllerBase
{
    public function seed(Request $request): RedirectResponse
    {
        if ($request->query->get('clear')) {
            \Drupal::state()->delete('planning.sessions');
            $this->messenger()->addStatus($this->t('Test sessions cleared.'));
            return new RedirectResponse('/sessions/enroll');
        }

        $now   = new \DateTimeImmutable('today 09:00', new \DateTimeZone('Europe/Brussels'));
        $today = $now->format('Y-m-d');

        $sessions = [
            [
                'session_id'         => 'aaaaaaaa-0001-4000-8000-000000000001',
                'title'              => 'Keynote: The Future of Event Tech',
                'session_type'       => 'keynote',
                'start_datetime'     => $today . 'T09:00:00',
                'end_datetime'       => $today . 'T10:00:00',
                'location'           => 'Main Stage',
                'status'             => 'active',
                'max_attendees'      => 200,
                'current_attendees'  => 42,
                'speaker'            => [
                    'first_name'   => 'Sophie',
                    'last_name'    => 'Claes',
                    'organisation' => 'Desiderius Hogeschool',
                    'email'        => 'sophie.claes@example.com',
                ],
            ],
            [
                'session_id'         => 'aaaaaaaa-0002-4000-8000-000000000002',
                'title'              => 'Workshop: AI in Practice',
                'session_type'       => 'workshop',
                'start_datetime'     => $today . 'T11:00:00',
                'end_datetime'       => $today . 'T12:30:00',
                'location'           => 'Room A',
                'status'             => 'active',
                'max_attendees'      => 30,
                'current_attendees'  => 25,
                'speaker'            => [
                    'first_name'   => 'Lien',
                    'last_name'    => 'Vermeersch',
                    'organisation' => 'TechLab Gent',
                    'email'        => 'lien.v@example.com',
                ],
            ],
            [
                'session_id'         => 'aaaaaaaa-0003-4000-8000-000000000003',
                'title'              => 'Workshop: Full Session (no spots left)',
                'session_type'       => 'workshop',
                'start_datetime'     => $today . 'T13:00:00',
                'end_datetime'       => $today . 'T14:00:00',
                'location'           => 'Room B',
                'status'             => 'active',
                'max_attendees'      => 20,
                'current_attendees'  => 20,
                'speaker'            => [],
            ],
            [
                'session_id'         => 'aaaaaaaa-0004-4000-8000-000000000004',
                'title'              => 'Networking Reception',
                'session_type'       => 'reception',
                'start_datetime'     => $today . 'T17:00:00',
                'end_datetime'       => $today . 'T19:00:00',
                'location'           => 'Foyer',
                'status'             => 'active',
                'max_attendees'      => 0,
                'current_attendees'  => 0,
                'speaker'            => [],
            ],
        ];

        \Drupal::state()->set('planning.sessions', $sessions);

        $this->messenger()->addStatus($this->t(
            'Injected @count fake sessions into planning.sessions. <a href="/admin/sessions/seed-test?clear=1">Clear test data</a>',
            ['@count' => count($sessions)]
        ));

        return new RedirectResponse('/sessions/enroll');
    }
}
