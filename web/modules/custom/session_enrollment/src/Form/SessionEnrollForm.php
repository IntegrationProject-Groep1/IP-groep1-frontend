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
 * Shows available sessions with a per-session Enroll button.
 *
 * Each button submits the form for exactly one session. The triggering element's
 * #name encodes the session_id so submitForm knows which session to enroll in.
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
        $this->fetchSessionsFromPlanning();

        $sessions = \Drupal::state()->get('planning.sessions', []);

        if (empty($sessions)) {
            $form['notice'] = [
                '#markup' => '<p>' . $this->t('Could not load sessions: no connection to the Planning service. Please try again later.') . '</p>',
            ];
            return $form;
        }

        $form['session_list'] = [
            '#type'       => 'container',
            '#attributes' => ['class' => ['session-cards']],
        ];

        foreach ($sessions as $session) {
            if (empty($session['session_id']) || empty($session['title'])) {
                continue;
            }

            $id      = (string) $session['session_id'];
            $safeKey = 'session_' . preg_replace('/[^a-z0-9]/', '_', strtolower($id));

            $isFull = isset($session['max_attendees'], $session['current_attendees'])
                && (int) $session['max_attendees'] > 0
                && (int) $session['current_attendees'] >= (int) $session['max_attendees'];

            $form['session_list'][$safeKey] = [
                '#type'       => 'container',
                '#attributes' => ['class' => array_filter(['session-card', $isFull ? 'session-card--full' : ''])],
            ];

            $form['session_list'][$safeKey]['info'] = [
                '#markup' => $this->buildSessionMarkup($session),
            ];

            if ($isFull) {
                $form['session_list'][$safeKey]['enroll_btn'] = [
                    '#markup' => '<span class="btn btn-disabled" aria-disabled="true">' . $this->t('Session full') . '</span>',
                ];
            } else {
                $form['session_list'][$safeKey]['enroll_btn'] = [
                    '#type'        => 'submit',
                    '#value'       => $this->t('Enroll'),
                    '#name'        => 'enroll_' . $id,
                    '#button_type' => 'primary',
                    '#attributes'  => ['class' => ['btn', 'btn-primary', 'btn-sm']],
                ];
            }
        }

        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        // Determine which session's Enroll button was clicked.
        $triggeringElement = $form_state->getTriggeringElement();
        $buttonName        = $triggeringElement['#name'] ?? '';

        if (!str_starts_with($buttonName, 'enroll_')) {
            $this->messenger()->addError($this->t('Could not determine which session to enroll in.'));
            return;
        }

        $sessionId  = substr($buttonName, strlen('enroll_'));
        $sessionMap = $this->buildSessionMap();

        if (!isset($sessionMap[$sessionId])) {
            $this->messenger()->addError($this->t('Session data is no longer available. Please try again.'));
            return;
        }

        $currentUser = $this->currentUser();
        $userId      = (int) $currentUser->id();
        $masterUuid  = \Drupal::service('user.data')->get('registration_form', $userId, 'master_uuid') ?? '';

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
            $this->enrollmentService->enroll($userData, [$sessionId], $sessionMap);

            $session = $sessionMap[$sessionId];
            $label   = $session['title'];
            if (!empty($session['start_datetime'])) {
                $label .= ' — ' . $this->formatDateTime($session['start_datetime']);
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

    /**
     * Fetches sessions from Planning on-demand.
     *
     * Strategy:
     *  1. Drain any pending response already in the queue from a previous request.
     *  2. Send a fresh request so the next page load has up-to-date data.
     *  3. Poll briefly in case Planning responds quickly.
     *
     * Falls back to whatever is cached in Drupal state when no response arrives in time.
     */
    private function fetchSessionsFromPlanning(): void
    {
        try {
            $client   = new \Drupal\rabbitmq_sender\RabbitMQClient();
            $receiver = new \Drupal\rabbitmq_receiver\SessionViewResponseReceiver($client);

            for ($i = 0; $i < 3; $i++) {
                if ($receiver->pollOnce()) {
                    (new \Drupal\rabbitmq_sender\SessionViewRequestSender($client))->send();
                    return;
                }
            }

            (new \Drupal\rabbitmq_sender\SessionViewRequestSender($client))->send();

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
    }

    /**
     * Builds safe HTML markup for a single session card's info section.
     */
    private function buildSessionMarkup(array $session): string
    {
        $esc = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $typeTone = match ($session['session_type'] ?? '') {
            'keynote'   => 'primary',
            'workshop'  => 'accent',
            'reception' => 'warm',
            default     => 'neutral',
        };

        $html = '<div class="session-card-header">';
        if (!empty($session['session_type'])) {
            $html .= '<span class="tag tag-' . $typeTone . '">' . $esc($session['session_type']) . '</span>';
        }
        $html .= '</div>';

        $html .= '<h3 class="session-card-title">' . $esc($session['title']) . '</h3>';

        $html .= '<div class="session-card-meta">';

        if (!empty($session['start_datetime'])) {
            $time = $this->formatDateTime($session['start_datetime']);
            if (!empty($session['end_datetime'])) {
                try {
                    $time .= ' – ' . (new \DateTimeImmutable($session['end_datetime']))->format('H:i');
                } catch (\Throwable) {}
            }
            $html .= '<span class="session-card-time">' . $esc($time) . '</span>';
        }

        if (!empty($session['location'])) {
            $html .= '<span class="session-card-location">' . $esc($session['location']) . '</span>';
        }

        if (!empty($session['speaker'])) {
            $spk  = $session['speaker'];
            $name = trim(($spk['first_name'] ?? '') . ' ' . ($spk['last_name'] ?? ''));
            if ($name !== '') {
                $org = !empty($spk['organisation']) ? ' · ' . $esc($spk['organisation']) : '';
                $html .= '<span class="session-card-speaker">' . $esc($name) . $org . '</span>';
            }
        }

        if (!empty($session['max_attendees']) && (int) $session['max_attendees'] > 0) {
            $current = (int) ($session['current_attendees'] ?? 0);
            $max     = (int) $session['max_attendees'];
            $html   .= '<span class="session-card-capacity">' . $current . '&thinsp;/&thinsp;' . $max . '</span>';
        }

        $html .= '</div>';

        return $html;
    }

    private function formatDateTime(string $raw): string
    {
        try {
            return (new \DateTimeImmutable($raw))->format('H:i, D d M');
        } catch (\Throwable) {
            return $raw;
        }
    }

    private function buildSessionMap(): array
    {
        $sessions = \Drupal::state()->get('planning.sessions', []);
        $map      = [];
        foreach ($sessions as $session) {
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
