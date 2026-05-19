<?php

declare(strict_types=1);

namespace Drupal\session_enrollment\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\session_enrollment\Service\SessionEnrollmentService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form allowing an authenticated user to enroll in one or more Planning sessions.
 */
class SessionEnrollForm extends FormBase
{
    public function __construct(
        private readonly SessionEnrollmentService $enrollmentService,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('session_enrollment.enrollment_service'),
        );
    }

    public function getFormId(): string
    {
        return 'session_enrollment_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        // Prevent Dynamic Page Cache from serving a stale page after enrollment redirect.
        $form['#cache']['max-age'] = 0;

        // Pick up feedback from form submit (setRebuild flow).
        if ($titles = $form_state->get('success_titles')) {
            $label = count($titles) === 1
                ? $this->t('Je bent nu ingeschreven voor sessie: @s', ['@s' => implode(', ', $titles)])
                : $this->t('Je bent nu ingeschreven voor de volgende sessies: @s', ['@s' => implode(', ', $titles)]);
            $form['enrollment_success'] = [
                '#markup' => '<div class="alert alert-success" id="enrollment-success">' . $label . '</div>',
                '#weight' => -100,
            ];
        }
        if ($err = $form_state->get('error_message')) {
            $form['enrollment_error'] = [
                '#markup' => '<div class="alert alert-error" id="enrollment-error">'
                    . $this->t('Inschrijving mislukt: @e', ['@e' => $err]) . '</div>',
                '#weight' => -100,
            ];
        }

        // Fetch sessions on-demand: send request to Planning and poll for response.
        // This avoids dependency on cron for showing available sessions.
        $this->fetchSessionsFromPlanning();

        $options = $this->getSessionOptions();

        if (empty($options)) {
            $form['notice'] = [
                '#markup' => '<p>' . $this->t('Could not load sessions: no connection to the Planning service. Please try again later.') . '</p>',
            ];
            return $form;
        }

        $sessions = \Drupal::state()->get('planning.sessions', []);

        $form['session_ids'] = [
            '#type'     => 'checkboxes',
            '#title'    => $this->t('Available sessions'),
            '#options'  => $options,
            '#required' => true,
        ];

        $form['submit'] = [
            '#type'  => 'submit',
            '#value' => $this->t('Enroll'),
        ];

        // Attach full session data for use in the Twig template.
        $form['#sessions_full'] = $sessions;

        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
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

        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $masterUuid)) {
            $this->messenger()->addError($this->t('Your account is not fully set up. Please complete your registration before enrolling in sessions.'));
            return;
        }

        $userData = [
            'email'         => $currentUser->getEmail(),
            'user_id'       => (string) $userId,
            'master_uuid'   => $masterUuid !== '' ? $masterUuid : null,
            'first_name'    => $this->resolveUserField($userId, 'field_first_name') ?: $currentUser->getAccountName(),
            'last_name'     => $this->resolveUserField($userId, 'field_last_name') ?: '',
            'date_of_birth' => $this->resolveUserField($userId, 'field_date_of_birth') ?: '',
            'is_company'    => (bool) $this->resolveUserField($userId, 'field_is_company'),
            'company_name'  => $this->resolveUserField($userId, 'field_company_name'),
            'vat_number'    => $this->resolveUserField($userId, 'field_vat_number'),
        ];

        try {
            $this->enrollmentService->enroll($userData, $sessionIds, $sessionMap);

            $sessionOptions = $this->getSessionOptions();
            $enrolledTitles = array_map(fn(string $id) => $sessionOptions[$id] ?? $id, $sessionIds);

            $form_state->set('success_titles', $enrolledTitles);
            $form_state->setRebuild(true);
        } catch (\Throwable $e) {
            $form_state->set('error_message', $e->getMessage());
            $form_state->setRebuild(true);
        }
    }

    /**
     * Fetches sessions from Planning on-demand.
     *
     * Strategy:
     *  1. First drain any pending response already in the queue (from a previous page load).
     *     Planning can take ~60 s to respond, so the response of the previous request is
     *     likely waiting by the time the user refreshes.
     *  2. Send a fresh request so the next page load also has up-to-date data.
     *  3. Poll briefly in case Planning happens to respond quickly this time.
     *
     * Falls back to whatever is cached in Drupal state when no response arrives in time.
     */
    private function fetchSessionsFromPlanning(): void
    {
        try {
            $client   = new \Drupal\rabbitmq_sender\RabbitMQClient();
            $receiver = new \Drupal\rabbitmq_receiver\SessionViewResponseReceiver($client);

            // Step 1: pick up any response that is already waiting in the queue.
            for ($i = 0; $i < 3; $i++) {
                if ($receiver->pollOnce()) {
                    // Got fresh data; send a new request for the next page load and return.
                    (new \Drupal\rabbitmq_sender\SessionViewRequestSender($client))->send();
                    return;
                }
            }

            // Step 2: nothing pending – send a fresh request.
            (new \Drupal\rabbitmq_sender\SessionViewRequestSender($client))->send();

            // Step 3: poll a few more times in case Planning responds quickly.
            for ($i = 0; $i < 5; $i++) {
                if ($receiver->pollOnce()) {
                    return;
                }
            }
        } catch (\Throwable $e) {
            \Drupal::logger('session_enrollment')->warning(
                'Could not fetch sessions from Planning: @error',
                ['@error' => $e->getMessage()]
            );
        }
        // Falls back to whatever is in state from a previous successful fetch.
    }

    /**
     * Builds session option list from Drupal State (populated by SessionViewResponseReceiver).
     * Returns an empty array when no live data is available.
     */
    private function getSessionOptions(): array
    {
        $sessions = \Drupal::state()->get('planning.sessions', []);
        $options  = [];

        foreach ($sessions as $session) {
            if (empty($session['session_id']) || empty($session['title'])) {
                continue;
            }
            $label = $session['title'];
            if (!empty($session['start_datetime'])) {
                $label .= ' — ' . $this->formatDateTime($session['start_datetime']);
            }
            $options[$session['session_id']] = $label;
        }

        return $options;
    }

    /**
     * Format a datetime string to a human-readable time (H:i).
     */
    private function formatDateTime(string $raw): string
    {
        try {
            return (new \DateTimeImmutable($raw))->format('H:i, D d M');
        } catch (\Throwable) {
            return $raw;
        }
    }

    /**
     * Builds a map of session_id => session data from Drupal State.
     */
    private function buildSessionMap(): array
    {
        $sessions = \Drupal::state()->get('planning.sessions', []);
        $map = [];
        foreach ($sessions as $session) {
            if (!empty($session['session_id'])) {
                $map[(string) $session['session_id']] = $session;
            }
        }
        return $map;
    }

    /**
     * Resolves a user field value by field name for the given user ID.
     */
    private function resolveUserField(int|string $uid, string $fieldName): string
    {
        try {
            $user = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
            if ($user && $user->hasField($fieldName)) {
                return (string) ($user->get($fieldName)->value ?? '');
            }
        } catch (\Throwable) {
            // Gracefully degrade if entity system is unavailable.
        }
        return '';
    }
}
 