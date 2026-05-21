<?php

declare(strict_types=1);

namespace Drupal\session_management\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\session_management\Service\SessionService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds and validates the session creation form.
 */
class SessionForm extends FormBase
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
        return 'session_management_session_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $form['title'] = [
            '#type'     => 'textfield',
            '#title'    => $this->t('Session title'),
            '#required' => true,
            '#maxlength' => 255,
        ];

        $form['session_type'] = [
            '#type'    => 'select',
            '#title'   => $this->t('Session type'),
            '#required' => true,
            '#options' => [
                'keynote'   => $this->t('Keynote'),
                'workshop'  => $this->t('Workshop'),
                'reception' => $this->t('Reception'),
                'other'     => $this->t('Other'),
            ],
            '#empty_option' => $this->t('— Select a type —'),
        ];

        $form['start_datetime'] = [
            '#type'     => 'datetime',
            '#title'    => $this->t('Start date & time'),
            '#required' => true,
        ];

        $form['end_datetime'] = [
            '#type'     => 'datetime',
            '#title'    => $this->t('End date & time'),
            '#required' => true,
        ];

        $form['speaker_name'] = [
            '#type'        => 'textfield',
            '#title'       => $this->t('Speaker name'),
            '#required'    => false,
            '#description' => $this->t('Optional. Enter the name of the speaker for this session.'),
            '#maxlength'   => 255,
        ];

        $form['location'] = [
            '#type'        => 'textfield',
            '#title'       => $this->t('Location'),
            '#required'    => false,
            '#description' => $this->t('Optional. Leave empty for online/TBD.'),
            '#maxlength'   => 255,
        ];

        $form['max_attendees'] = [
            '#type'        => 'number',
            '#title'       => $this->t('Maximum attendees'),
            '#required'    => false,
            '#min'         => 1,
            '#description' => $this->t('Optional. Leave empty for unlimited.'),
        ];

        $form['submit'] = [
            '#type'  => 'submit',
            '#value' => $this->t('Create session'),
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
        $start = $form_state->getValue('start_datetime');
        $end   = $form_state->getValue('end_datetime');

        // DrupalDateTime objects need to be formatted to ISO 8601.
        $startFormatted = is_object($start) ? $start->format('c') : (string) $start;
        $endFormatted   = is_object($end)   ? $end->format('c')   : (string) $end;

        $data = [
            'title'          => $form_state->getValue('title'),
            'session_type'   => $form_state->getValue('session_type'),
            'start_datetime' => $startFormatted,
            'end_datetime'   => $endFormatted,
            'speaker_name'   => $form_state->getValue('speaker_name') ?: '',
            'location'       => $form_state->getValue('location') ?: '',
            'max_attendees'  => $form_state->getValue('max_attendees'),
        ];

        try {
            $this->sessionService->createSession($data);
            $this->messenger()->addStatus($this->t('Session "@title" has been created.', [
                '@title' => $data['title'],
            ]));
            $form_state->setRedirectUrl(Url::fromRoute('session_management.confirmation'));
        } catch (\InvalidArgumentException $e) {
            $this->messenger()->addError($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->messenger()->addError($this->t('Session could not be saved. Please try again later.'));
        } catch (\Throwable $e) {
            $this->messenger()->addError($this->t('An unexpected error occurred. Please try again later.'));
        }
    }
}
