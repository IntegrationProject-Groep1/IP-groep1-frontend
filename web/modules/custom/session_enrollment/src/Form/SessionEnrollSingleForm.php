<?php

declare(strict_types=1);

namespace Drupal\session_enrollment\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\session_enrollment\Service\SessionEnrollmentService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Enrolls the current user in a single session identified by session_id.
 *
 * Used by the per-session "Enroll" buttons on the sessions overview page.
 */
class SessionEnrollSingleForm extends FormBase
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
        return 'session_enroll_single_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state, string $session_id = ''): array
    {
        $sessions   = \Drupal::state()->get('planning.sessions', []);
        $sessionMap = [];
        foreach ($sessions as $s) {
            if (!empty($s['session_id'])) {
                $sessionMap[$s['session_id']] = $s;
            }
        }

        if ($session_id === '' || !isset($sessionMap[$session_id])) {
            $form['error'] = [
                '#markup' => '<p>' . $this->t('Session not found. Please go back and try again.') . '</p>',
            ];
            return $form;
        }

        $session = $sessionMap[$session_id];

        $form['session_id'] = [
            '#type'  => 'hidden',
            '#value' => $session_id,
        ];

        $form['session_info'] = [
            '#markup' => '<p><strong>' . htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') . '</strong></p>',
        ];

        $form['submit'] = [
            '#type'  => 'submit',
            '#value' => $this->t('Enroll in this session'),
        ];

        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $sessionId   = (string) $form_state->getValue('session_id');
        $currentUser = $this->currentUser();
        $userId      = (int) $currentUser->id();

        $sessions   = \Drupal::state()->get('planning.sessions', []);
        $sessionMap = [];
        foreach ($sessions as $s) {
            if (!empty($s['session_id'])) {
                $sessionMap[$s['session_id']] = $s;
            }
        }

        if (!isset($sessionMap[$sessionId])) {
            $this->messenger()->addError($this->t('Session data is no longer available. Please try again.'));
            return;
        }

        $masterUuid = \Drupal::service('user.data')->get('registration_form', $userId, 'master_uuid') ?? '';

        $userData = [
            'email'       => $currentUser->getEmail(),
            'user_id'     => (string) $userId,
            'master_uuid' => $masterUuid !== '' ? $masterUuid : null,
            'first_name'  => $this->resolveUserField($userId, 'field_first_name') ?: $currentUser->getAccountName(),
            'last_name'   => $this->resolveUserField($userId, 'field_last_name') ?: '',
        ];

        try {
            $this->enrollmentService->enroll($userData, [$sessionId], $sessionMap);

            $session = $sessionMap[$sessionId];
            $label   = $session['title'];
            if (!empty($session['start_datetime'])) {
                try {
                    $label .= ' — ' . (new \DateTimeImmutable($session['start_datetime']))->format('H:i, D d M');
                } catch (\Throwable) {}
            }

            $this->tempStoreFactory
                ->get('session_enrollment')
                ->set('confirmation', [
                    'name'     => trim($userData['first_name'] . ' ' . $userData['last_name']),
                    'sessions' => [$label],
                ]);

            $form_state->setRedirectUrl(Url::fromRoute('session_enrollment.confirmation'));
        } catch (\Throwable $e) {
            $this->messenger()->addError($this->t('Enrollment failed: @error', ['@error' => $e->getMessage()]));
        }
    }

    private function resolveUserField(int $uid, string $fieldName): string
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
