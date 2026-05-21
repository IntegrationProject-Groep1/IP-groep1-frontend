<?php

declare(strict_types=1);

namespace Drupal\session_enrollment\Form;

use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\session_enrollment\Service\SessionEnrollmentService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form allowing an authenticated user to enroll in one or more sessions.
 * Sessions are read directly from MariaDB (planning_sessions table).
 */
class SessionEnrollForm extends FormBase
{
    public function __construct(
        private readonly SessionEnrollmentService $enrollmentService,
        private readonly PrivateTempStoreFactory $tempStoreFactory,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('session_enrollment.enrollment_service'),
            $container->get('tempstore.private'),
        );
    }

    public function getFormId(): string
    {
        return 'session_enrollment_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $uid          = (int) $this->currentUser()->id();
        $masterUuid   = (string) (\Drupal::service('user.data')->get('registration_form', $uid, 'master_uuid') ?? '');
        $identityUuid = $masterUuid !== '' ? $masterUuid : (string) $uid;

        $sessions = $this->loadSessionsFromDb($identityUuid);

        if (empty($sessions)) {
            $form['notice'] = [
                '#markup' => '<p>' . $this->t('No sessions available at the moment.') . '</p>',
            ];
            return $form;
        }

        // Only non-enrolled sessions appear in the fallback checkboxes.
        $availableSessions = array_values(array_filter($sessions, fn($s) => !($s['is_enrolled'] ?? false)));
        $options = $this->buildOptions($availableSessions);

        $hasAvailable = !empty($options);

        $form['session_ids'] = [
            '#type'    => 'checkboxes',
            '#title'   => $this->t('Available sessions'),
            '#options' => $options,
            '#required' => $hasAvailable,
            '#access'  => $hasAvailable,
        ];

        // Always define submit so the template can render {{ form.submit }}
        // safely; hide it via #access when there is nothing to enrol in.
        $form['submit'] = [
            '#type'   => 'submit',
            '#value'  => $this->t('Enroll'),
            '#access' => $hasAvailable,
        ];

        // All sessions (including enrolled) are passed to the template.
        $form['#sessions_full'] = $sessions;

        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        // session_ids is absent when all sessions are already enrolled.
        if (!isset($form['session_ids'])) {
            return;
        }
        $selected = array_filter((array) ($form_state->getValue('session_ids') ?? []));
        if (empty($selected)) {
            $form_state->setErrorByName('session_ids', $this->t('Please select at least one session.'));
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $currentUser = $this->currentUser();
        $sessionIds  = array_keys(array_filter((array) ($form_state->getValue('session_ids') ?? [])));
        $sessionMap  = $this->buildSessionMap();

        $userId     = (int) $currentUser->id();
        $masterUuid = \Drupal::service('user.data')->get('registration_form', $userId, 'master_uuid') ?? '';

        $userData = [
            'email'       => $currentUser->getEmail(),
            'user_id'     => (string) $userId,
            'master_uuid' => $masterUuid !== '' ? $masterUuid : null,
            'first_name'  => $this->resolveUserField($userId, 'field_first_name') ?: $currentUser->getAccountName(),
            'last_name'   => $this->resolveUserField($userId, 'field_last_name') ?: '',
        ];

        try {
            $this->enrollmentService->enroll($userData, $sessionIds, $sessionMap);

            $this->tempStoreFactory
                ->get('session_enrollment')
                ->set('confirmation', [
                    'name'     => trim($userData['first_name'] . ' ' . $userData['last_name']),
                    'sessions' => array_map(
                        fn(string $id) => $this->buildOptions($this->loadSessionsFromDb())[$id] ?? $id,
                        $sessionIds
                    ),
                ]);

            $form_state->setRedirectUrl(Url::fromRoute('session_enrollment.confirmation'));
        } catch (\Throwable $e) {
            $this->messenger()->addError($this->t('Enrollment failed: @error', ['@error' => $e->getMessage()]));
        }
    }

    /**
     * Load all published sessions and flag which ones the user is enrolled in.
     *
     * @return list<array<string,mixed>>
     */
    private function loadSessionsFromDb(string $identityUuid = ''): array
    {
        try {
            $db = Database::getConnection();

            // Get session_ids the user is already confirmed for.
            $enrolled = [];
            if ($identityUuid !== '') {
                $rows = $db->query(
                    "SELECT session_id FROM planning_registrations
                     WHERE master_uuid = :uuid AND status = 'confirmed'",
                    [':uuid' => $identityUuid]
                )->fetchCol();
                $enrolled = $rows ?: [];
            }

            $sessions = $db->query(
                "SELECT session_id, title, start_datetime, end_datetime,
                        speaker_name, location, session_type, status, max_attendees,
                        current_attendees, price
                 FROM planning_sessions
                 WHERE is_deleted = 0
                   AND status = 'published'
                 ORDER BY start_datetime"
            )->fetchAll(\PDO::FETCH_ASSOC);

            // Mark each session with enrollment status; do not filter them out.
            $enrolledMap = array_flip($enrolled);
            return array_map(
                fn($s) => array_merge($s, ['is_enrolled' => isset($enrolledMap[$s['session_id']])]),
                $sessions
            );
        } catch (\Throwable $e) {
            \Drupal::logger('session_enrollment')->error('Failed to load sessions from DB: @e', ['@e' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Build session_id => label options for the checkboxes.
     *
     * @param list<array<string,mixed>> $sessions
     * @return array<string,string>
     */
    private function buildOptions(array $sessions): array
    {
        $options = [];
        foreach ($sessions as $session) {
            if (empty($session['session_id']) || empty($session['title'])) {
                continue;
            }
            $label = $session['title'];
            if (!empty($session['speaker_name'])) {
                $label .= ' · ' . $session['speaker_name'];
            }
            if (!empty($session['start_datetime'])) {
                try {
                    $label .= ' — ' . (new \DateTimeImmutable($session['start_datetime']))->format('H:i, D d M');
                } catch (\Throwable) {}
            }
            $price = $session['price'] ?? null;
            if ($price !== null && $price !== '' && (float) $price > 0) {
                $label .= ' · €' . number_format((float) $price, 2, '.', '');
            } else {
                $label .= ' · ' . 'Free';
            }
            $options[(string) $session['session_id']] = $label;
        }
        return $options;
    }

    /**
     * Build session_id => session data map for enrollment service.
     *
     * @return array<string, array<string,mixed>>
     */
    private function buildSessionMap(): array
    {
        $map = [];
        foreach ($this->loadSessionsFromDb() as $session) {
            if (!empty($session['session_id'])) {
                $map[(string) $session['session_id']] = $session;
            }
        }
        return $map;
    }

    private function resolveUserField(int|string $uid, string $fieldName): string
    {
        try {
            $user = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
            if ($user && $user->hasField($fieldName)) {
                return (string) ($user->get($fieldName)->value ?? '');
            }
        } catch (\Throwable) {}
        return '';
    }
}
