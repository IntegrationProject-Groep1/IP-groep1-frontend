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
                'keynote'  => $this->t('Keynote'),
                'workshop' => $this->t('Workshop'),
                'panel'    => $this->t('Panel discussion'),
                'breakout' => $this->t('Breakout session'),
                'other'    => $this->t('Other'),
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

        if (!empty($start) && !empty($end)) {
            $startTs = is_object($start) ? $start->getTimestamp() : strtotime((string) $start);
            $endTs   = is_object($end)   ? $end->getTimestamp()   : strtotime((string) $end);

            if ($endTs !== false && $startTs !== false && $endTs <= $startTs) {
                $form_state->setErrorByName('end_datetime', $this->t('End date must be after start date.'));
            }
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
            'location'       => $form_state->getValue('location') ?: '',
            'max_attendees'  => $form_state->getValue('max_attendees'),
        ];

        try {
            $this->sessionService->createSession($data);
            $form_state->setRedirectUrl(Url::fromRoute('session_management.confirmation'));
        } catch (\InvalidArgumentException $e) {
            $this->messenger()->addError($e->getMessage());
        } catch (\Throwable $e) {
            $this->messenger()->addError($this->t('Failed to send session to Planning. Please try again later.'));
        }
    }
}
