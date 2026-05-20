<?php

declare(strict_types=1);

namespace Drupal\session_management\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Festival date settings form, embedded at the top of /session/admin.
 */
class FestivalSettingsForm extends ConfigFormBase
{
    public function getFormId(): string
    {
        return 'session_management_festival_settings_form';
    }

    protected function getEditableConfigNames(): array
    {
        return ['shift_festival.settings'];
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $config = $this->config('shift_festival.settings');

        $form['festival_dates'] = [
            '#type'  => 'fieldset',
            '#title' => $this->t('Festival dates'),
        ];

        $form['festival_dates']['festival_start_date'] = [
            '#type'          => 'date',
            '#title'         => $this->t('Festival start date'),
            '#required'      => true,
            '#default_value' => $config->get('festival_start_date') ?? '',
        ];

        $form['festival_dates']['festival_end_date'] = [
            '#type'          => 'date',
            '#title'         => $this->t('Festival end date'),
            '#required'      => true,
            '#default_value' => $config->get('festival_end_date') ?? '',
        ];

        $form['festival_times'] = [
            '#type'  => 'fieldset',
            '#title' => $this->t('Festival times'),
        ];

        $form['festival_times']['festival_start_time'] = [
            '#type'          => 'textfield',
            '#title'         => $this->t('Festival start time'),
            '#required'      => true,
            '#maxlength'     => 5,
            '#size'          => 8,
            '#placeholder'   => 'HH:MM',
            '#pattern'       => '^([01]\d|2[0-3]):([0-5]\d)$',
            '#default_value' => $config->get('festival_start_time') ?? '',
            '#description'   => $this->t('24-hour format, e.g. 17:00'),
        ];

        $form['festival_times']['festival_end_time'] = [
            '#type'          => 'textfield',
            '#title'         => $this->t('Festival end time'),
            '#required'      => true,
            '#maxlength'     => 5,
            '#size'          => 8,
            '#placeholder'   => 'HH:MM',
            '#pattern'       => '^([01]\d|2[0-3]):([0-5]\d)$',
            '#default_value' => $config->get('festival_end_time') ?? '',
            '#description'   => $this->t('24-hour format, e.g. 21:30'),
        ];

        $form['actions'] = [
            '#type' => 'actions',
        ];

        $form['actions']['submit'] = [
            '#type'  => 'submit',
            '#value' => $this->t('Save festival dates'),
        ];

        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        $startDate = $form_state->getValue('festival_start_date');
        $endDate   = $form_state->getValue('festival_end_date');

        if ($startDate && $endDate && $endDate < $startDate) {
            $form_state->setErrorByName('festival_end_date', $this->t('The festival end date must be after the start date.'));
        }

        $startTime = $form_state->getValue('festival_start_time');
        $endTime   = $form_state->getValue('festival_end_time');
        $timePattern = '/^([01]\d|2[0-3]):([0-5]\d)$/';

        if ($startTime && !preg_match($timePattern, $startTime)) {
            $form_state->setErrorByName('festival_start_time', $this->t('Festival start time must be in HH:MM format (e.g. 17:00).'));
        }

        if ($endTime && !preg_match($timePattern, $endTime)) {
            $form_state->setErrorByName('festival_end_time', $this->t('Festival end time must be in HH:MM format (e.g. 21:30).'));
        }

        if ($startTime && $endTime && preg_match($timePattern, $startTime) && preg_match($timePattern, $endTime) && $endTime <= $startTime) {
            $form_state->setErrorByName('festival_end_time', $this->t('The festival end time must be after the start time.'));
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $this->config('shift_festival.settings')
            ->set('festival_start_date', $form_state->getValue('festival_start_date'))
            ->set('festival_end_date', $form_state->getValue('festival_end_date'))
            ->set('festival_start_time', $form_state->getValue('festival_start_time'))
            ->set('festival_end_time', $form_state->getValue('festival_end_time'))
            ->save();

        $this->messenger()->addStatus($this->t('Festival dates have been saved.'));
    }
}
