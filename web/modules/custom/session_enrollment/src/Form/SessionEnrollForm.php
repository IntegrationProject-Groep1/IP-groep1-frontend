<?php

declare(strict_types=1);

namespace Drupal\session_enrollment\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\session_enrollment\Service\SessionEnrollmentService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form allowing an authenticated user to enroll in one or more Planning sessions.
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
        $options = $this->getSessionOptions();

        if (empty($options)) {
            $form['notice'] = [
                '#markup' => '<p>' . $this->t('Could not load sessions: no connection to the Planning service. Please try again later.') . '</p>',
            ];
            return $form;
        }

        $form['session_ids'] = [
            '#type'        => 'select',
            '#title'       => $this->t('Available sessions'),
            '#description' => $this->t('Hold Ctrl / Cmd to select multiple sessions.'),
            '#options'     => $options,
            '#multiple'    => true,
            '#required'    => true,
            '#size'        => min(count($options) + 1, 10),
        ];

        $form['submit'] = [
            '#type'  => 'submit',
            '#value' => $this->t('Enroll'),
        ];

        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        $selected = (array) ($form_state->getValue('session_ids') ?? []);
        if (empty($selected)) {
            $form_state->setErrorByName('session_ids', $this->t('Please select at least one session.'));
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $currentUser = $this->currentUser();
        $sessionIds  = array_values((array) ($form_state->getValue('session_ids') ?? []));
        $sessionMap  = $this->buildSessionMap();

        $userData = [
            'email'         => $currentUser->getEmail(),
            'user_id'       => (string) $currentUser->id(),
            'first_name'    => $this->resolveUserField($currentUser->id(), 'field_first_name') ?: $currentUser->getAccountName(),
            'last_name'     => $this->resolveUserField($currentUser->id(), 'field_last_name') ?: '',
            'date_of_birth' => $this->resolveUserField($currentUser->id(), 'field_date_of_birth') ?: '',
            'is_company'    => false,
        ];

        try {
            $this->enrollmentService->enroll($userData, $sessionIds, $sessionMap);

            $sessionOptions = $this->getSessionOptions();
            $this->tempStoreFactory
                ->get('session_enrollment')
                ->set('confirmation', [
                    'name'     => trim($userData['first_name'] . ' ' . $userData['last_name']),
                    'sessions' => array_map(
                        fn(string $id) => $sessionOptions[$id] ?? $id,
                        $sessionIds
                    ),
                ]);

            $form_state->setRedirectUrl(Url::fromRoute('session_enrollment.confirmation'));
        } catch (\Throwable $e) {
            $this->messenger()->addError($this->t('Enrollment failed: @error', ['@error' => $e->getMessage()]));
        }
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
                $label .= ' — ' . $session['start_datetime'];
            }
            $options[$session['session_id']] = $label;
        }

        return $options;
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
