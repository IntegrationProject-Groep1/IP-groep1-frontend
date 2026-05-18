<?php

declare(strict_types=1);

namespace Drupal\session_enrollment\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
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
            '#attributes' => ['class' => ['session-list']],
        ];

        foreach ($sessions as $session) {
            if (empty($session['session_id']) || empty($session['title'])) {
                continue;
            }

            $id      = (string) $session['session_id'];
            $safeKey = preg_replace('/[^a-z0-9]/', '_', strtolower($id));

            $maxAttendees     = isset($session['max_attendees']) ? (int) $session['max_attendees'] : 0;
            $currentAttendees = isset($session['current_attendees']) ? (int) $session['current_attendees'] : 0;
            $isFull           = $maxAttendees > 0 && $currentAttendees >= $maxAttendees;

            if ($isFull) {
                $form['session_list']['session_' . $safeKey] = [
                    '#markup' => Markup::create($this->buildFullCard($session, $maxAttendees, $currentAttendees)),
                ];
            } else {
                $form['session_list']['btn_' . $safeKey] = [
                    '#type'        => 'submit',
                    '#value'       => $this->t('Enroll'),
                    '#name'        => 'enroll_' . $id,
                    '#button_type' => 'primary',
                    '#attributes'  => ['class' => ['btn', 'btn-ink', 'btn-sm', 'btn-full']],
                    '#prefix'      => Markup::create($this->buildCardPrefix($session, $maxAttendees, $currentAttendees)),
                    '#suffix'      => Markup::create('</div></div>'),
                ];
            }
        }

        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
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
     * Builds the HTML prefix for a session card — everything up to and including
     * the opening of .session-side, so the submit button lands inside it.
     *
     * Output structure:
     *   <div class="session-card">
     *     <div class="session-time-col"> ... </div>
     *     <div class="session-main"> ... </div>
     *     <div class="session-side">
     *       [capacity bar if applicable]
     *       {submit button inserted here by Drupal}
     *     </div>  ← closed by #suffix
     *   </div>    ← closed by #suffix
     */
    private function buildCardPrefix(array $session, int $maxAttendees, int $currentAttendees): string
    {
        $html  = '<div class="session-card">';
        $html .= $this->buildTimeCol($session);
        $html .= $this->buildMainCol($session);
        $html .= '<div class="session-side">';
        $html .= $this->buildCapacityBar($maxAttendees, $currentAttendees);
        return $html;
    }

    /**
     * Builds the complete card HTML for a full (sold-out) session — no submit button.
     */
    private function buildFullCard(array $session, int $maxAttendees, int $currentAttendees): string
    {
        $html  = '<div class="session-card session-card-full">';
        $html .= $this->buildTimeCol($session);
        $html .= $this->buildMainCol($session);
        $html .= '<div class="session-side">';
        $html .= $this->buildCapacityBar($maxAttendees, $currentAttendees);
        $html .= '<span class="btn btn-ghost-light btn-sm btn-full" aria-disabled="true">' . $this->t('Session full') . '</span>';
        $html .= '</div></div>';
        return $html;
    }

    private function buildTimeCol(array $session): string
    {
        $html = '<div class="session-time-col">';

        if (!empty($session['start_datetime'])) {
            try {
                $dt   = new \DateTimeImmutable($session['start_datetime']);
                $html .= '<span class="session-day">' . $dt->format('D') . '</span>';
                $html .= '<span class="session-hour">' . $dt->format('H:i') . '</span>';
            } catch (\Throwable) {
                $html .= '<span class="session-hour">' . $this->esc($session['start_datetime']) . '</span>';
            }
        }

        if (!empty($session['end_datetime'])) {
            try {
                $end  = new \DateTimeImmutable($session['end_datetime']);
                $html .= '<span class="session-dur">— ' . $end->format('H:i') . '</span>';
            } catch (\Throwable) {}
        }

        $html .= '</div>';
        return $html;
    }

    private function buildMainCol(array $session): string
    {
        $typeTone = match ($session['session_type'] ?? '') {
            'keynote'   => 'primary',
            'workshop'  => 'accent',
            'reception' => 'warm',
            default     => 'neutral',
        };

        $html = '<div class="session-main">';

        if (!empty($session['session_type'])) {
            $html .= '<div class="session-tags">';
            $html .= '<span class="tag tag-' . $typeTone . '">' . $this->esc($session['session_type']) . '</span>';
            $html .= '</div>';
        }

        $html .= '<h3>' . $this->esc($session['title']) . '</h3>';

        $footItems = [];

        if (!empty($session['location'])) {
            $footItems[] = '<span>'
                . '<svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">'
                . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>'
                . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>'
                . '</svg>'
                . $this->esc($session['location'])
                . '</span>';
        }

        if (!empty($session['speaker'])) {
            $spk  = $session['speaker'];
            $name = trim(($spk['first_name'] ?? '') . ' ' . ($spk['last_name'] ?? ''));
            if ($name !== '') {
                $org  = !empty($spk['organisation']) ? ' · ' . $this->esc($spk['organisation']) : '';
                $footItems[] = '<span>'
                    . '<svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">'
                    . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>'
                    . '</svg>'
                    . $this->esc($name) . $org
                    . '</span>';
            }
        }

        if (!empty($footItems)) {
            $html .= '<div class="session-foot">' . implode('', $footItems) . '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private function buildCapacityBar(int $maxAttendees, int $currentAttendees): string
    {
        if ($maxAttendees <= 0) {
            return '';
        }

        $pct  = (int) round(($currentAttendees / $maxAttendees) * 100);
        $tone = $pct >= 80 ? 'hot' : ($pct >= 60 ? 'warm' : 'cool');

        return '<div class="capacity">'
            . '<div class="capacity-bar"><div class="capacity-fill capacity-' . $tone . '" style="width:' . $pct . '%"></div></div>'
            . '<div class="capacity-meta"><span>' . $currentAttendees . '&thinsp;/&thinsp;' . $maxAttendees . '</span><span class="capacity-pct">' . $pct . '%</span></div>'
            . '</div>';
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

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
