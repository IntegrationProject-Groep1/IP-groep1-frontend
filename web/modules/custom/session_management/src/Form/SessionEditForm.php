<?php

declare(strict_types=1);

namespace Drupal\session_management\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\session_management\Service\SessionService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for editing an existing session (writes directly to MariaDB).
 */
class SessionEditForm extends FormBase
{
    public function __construct(
        private readonly SessionService $sessionService,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('session_management.session_service'),
        );
    }

    public function getFormId(): string
    {
        return 'session_management_session_edit_form';
    }

    /**
     * Drupal passes route parameters as extra arguments to buildForm().
     */
    public function buildForm(array $form, FormStateInterface $form_state, string $session_id = ''): array
    {
        \Drupal::logger('session_management')->info('buildForm called with session_id=[@id]', ['@id' => $session_id]);
        $session = $this->loadSession($session_id);

        if ($session === null) {
            $this->messenger()->addError($this->t('Session @id not found. The session list may be out of date.', ['@id' => $session_id]));
            $form['#redirect'] = Url::fromRoute('session_management.admin');
            return $form;
        }

        $form_state->set('session_id', $session_id);

        $form['#session'] = $session;

        $form['title'] = [
            '#type'          => 'textfield',
            '#title'         => $this->t('Session title'),
            '#required'      => true,
            '#maxlength'     => 255,
            '#default_value' => $session['title'] ?? '',
        ];

        $form['session_type'] = [
            '#type'          => 'select',
            '#title'         => $this->t('Session type'),
            '#required'      => true,
            '#options'       => [
                'keynote'   => $this->t('Keynote'),
                'workshop'  => $this->t('Workshop'),
                'talk'      => $this->t('Talk'),
                'panel'     => $this->t('Panel'),
                'reception' => $this->t('Reception'),
                'other'     => $this->t('Other'),
            ],
            '#empty_option'  => $this->t('— Select a type —'),
            '#default_value' => $session['session_type'] ?? '',
        ];

        $form['status'] = [
            '#type'          => 'select',
            '#title'         => $this->t('Status'),
            '#required'      => true,
            '#options'       => [
                'draft'     => $this->t('Draft'),
                'published' => $this->t('Published'),
                'cancelled' => $this->t('Cancelled'),
            ],
            '#default_value' => $session['status'] ?? 'published',
        ];

        $form['start_datetime'] = [
            '#type'          => 'datetime',
            '#title'         => $this->t('Start date & time'),
            '#required'      => true,
            '#default_value' => $this->parseDatetime($session['start_datetime'] ?? ''),
        ];

        $form['end_datetime'] = [
            '#type'          => 'datetime',
            '#title'         => $this->t('End date & time'),
            '#required'      => true,
            '#default_value' => $this->parseDatetime($session['end_datetime'] ?? ''),
        ];

        $form['speaker_name'] = [
            '#type'          => 'textfield',
            '#title'         => $this->t('Speaker name'),
            '#required'      => false,
            '#description'   => $this->t('Optional. Enter the name of the speaker for this session.'),
            '#maxlength'     => 255,
            '#default_value' => $session['speaker_name'] ?? '',
        ];

        $form['location'] = [
            '#type'          => 'textfield',
            '#title'         => $this->t('Location'),
            '#required'      => false,
            '#description'   => $this->t('Optional. Leave empty for online / TBD.'),
            '#maxlength'     => 255,
            '#default_value' => $session['location'] ?? '',
        ];

        $form['max_attendees'] = [
            '#type'          => 'number',
            '#title'         => $this->t('Maximum attendees'),
            '#required'      => false,
            '#min'           => 1,
            '#default_value' => !empty($session['max_attendees']) ? (int) $session['max_attendees'] : null,
        ];

        $form['price'] = [
            '#type'          => 'number',
            '#title'         => $this->t('Price (EUR)'),
            '#required'      => false,
            '#min'           => 0,
            '#step'          => '0.01',
            '#description'   => $this->t('Leave empty for free sessions.'),
            '#default_value' => isset($session['price']) && $session['price'] !== null ? number_format((float) $session['price'], 2, '.', '') : '',
        ];

        $form['actions'] = [
            '#type' => 'actions',
        ];

        $form['actions']['submit'] = [
            '#type'  => 'submit',
            '#value' => $this->t('Save changes'),
        ];

        $form['actions']['cancel'] = [
            '#type'       => 'link',
            '#title'      => $this->t('Cancel'),
            '#url'        => Url::fromRoute('session_management.admin'),
            '#attributes' => ['class' => ['button', 'button--ghost']],
        ];

        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        $start = $form_state->getValue('start_datetime');
        $end   = $form_state->getValue('end_datetime');

        if (!empty($start)) {
            $this->validateSessionDatetime('start_datetime', $start, $form_state);
            $this->validateSessionTime('start_datetime', $start, $form_state);
        }

        if (!empty($end)) {
            $this->validateSessionDatetime('end_datetime', $end, $form_state);
            $this->validateSessionTime('end_datetime', $end, $form_state);
        }

        if (!empty($start) && !empty($end)) {
            $startDt = $this->toDateTimeImmutable($start);
            $endDt   = $this->toDateTimeImmutable($end);

            if ($startDt !== null && $endDt !== null && $endDt->getTimestamp() <= $startDt->getTimestamp()) {
                $form_state->setErrorByName('end_datetime', $this->t('De eindtijd moet na de begintijd liggen.'));
            }
        }
    }

    private function validateSessionDatetime(string $field, mixed $value, FormStateInterface $form_state): void
    {
        $dt = $this->toDateTimeImmutable($value);
        if ($dt === null) {
            return;
        }

        $config    = \Drupal::config('shift_festival.settings');
        $startDate = $config->get('festival_start_date');
        $endDate   = $config->get('festival_end_date');

        if (empty($startDate) || empty($endDate)) {
            $form_state->setErrorByName($field, $this->t('No festival dates configured. Please set the festival dates on the Manage Sessions page.'));
            return;
        }

        $sessionDate = $dt->format('Y-m-d');
        if ($sessionDate < $startDate || $sessionDate > $endDate) {
            $form_state->setErrorByName($field, $this->t('Sessions can only be created between @start and @end.', [
                '@start' => $startDate,
                '@end'   => $endDate,
            ]));
        }
    }

    private function validateSessionTime(string $field, mixed $value, FormStateInterface $form_state): void
    {
        $dt = $this->toDateTimeImmutable($value);
        if ($dt === null) {
            return;
        }

        $config    = \Drupal::config('shift_festival.settings');
        $startTime = $config->get('festival_start_time');
        $endTime   = $config->get('festival_end_time');

        if (empty($startTime) || empty($endTime)) {
            $form_state->setErrorByName($field, $this->t('No festival times configured. Please set the festival times on the Manage Sessions page.'));
            return;
        }

        $sessionTime = $dt->format('H:i');

        if ($field === 'start_datetime' && $sessionTime < $startTime) {
            $form_state->setErrorByName($field, $this->t('Sessions can only be created between @start and @end.', [
                '@start' => $startTime,
                '@end'   => $endTime,
            ]));
        }

        if ($field === 'end_datetime' && $sessionTime > $endTime) {
            $form_state->setErrorByName($field, $this->t('Sessions can only be created between @start and @end.', [
                '@start' => $startTime,
                '@end'   => $endTime,
            ]));
        }
    }

    private function toDateTimeImmutable(mixed $value): ?\DateTimeImmutable
    {
        try {
            if ($value instanceof \DateTimeImmutable) {
                return $value;
            }
            if ($value instanceof \DateTime) {
                return \DateTimeImmutable::createFromMutable($value);
            }
            return new \DateTimeImmutable((string) $value);
        }
        catch (\Throwable) {
            return null;
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $sessionId = (string) $form_state->get('session_id');

        $start = $form_state->getValue('start_datetime');
        $end   = $form_state->getValue('end_datetime');

        $startFormatted = is_object($start) ? $start->format('c') : (string) $start;
        $endFormatted   = is_object($end)   ? $end->format('c')   : (string) $end;

        $priceRaw = $form_state->getValue('price');
        $price    = ($priceRaw !== null && $priceRaw !== '') ? (float) $priceRaw : null;

        $speakerName = (string) ($form_state->getValue('speaker_name') ?? '');

        $data = [
            'session_id'     => $sessionId,
            'title'          => $form_state->getValue('title'),
            'session_type'   => $form_state->getValue('session_type') ?: null,
            'status'         => $form_state->getValue('status') ?: null,
            'start_datetime' => $startFormatted,
            'end_datetime'   => $endFormatted,
            'location'       => $form_state->getValue('location') ?: null,
            'max_attendees'  => $form_state->getValue('max_attendees') ?: null,
            'price'          => $price,
        ];

        // Strip null optional fields.
        $data = array_filter($data, fn($v) => $v !== null && $v !== '');
        $data['session_id']   = $sessionId;   // must always be present
        $data['speaker_name'] = $speakerName; // always update, even to empty

        try {
            $this->sessionService->updateSession($sessionId, $data);
            $this->messenger()->addStatus($this->t('Session "@title" has been updated.', [
                '@title' => $form_state->getValue('title'),
            ]));
            $form_state->setRedirectUrl(Url::fromRoute('session_management.admin'));
        } catch (\Throwable $e) {
            $this->messenger()->addError($this->t('Failed to update session: @error', ['@error' => $e->getMessage()]));
        }
    }

    /**
     * Look up a session from Drupal state by session_id.
     */
    private function loadSession(string $sessionId): ?array
    {
        if ($sessionId === '') {
            return null;
        }
        return $this->sessionService->loadSession($sessionId);
    }

    /**
     * Parse an ISO datetime string into a DrupalDateTime (or null on failure).
     */
    private function parseDatetime(string $raw): ?DrupalDateTime
    {
        if ($raw === '') {
            return null;
        }
        try {
            return new DrupalDateTime($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
