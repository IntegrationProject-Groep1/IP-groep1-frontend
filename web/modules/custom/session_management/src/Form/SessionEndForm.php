<?php

declare(strict_types=1);

namespace Drupal\session_management\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rabbitmq_sender\EventEndedSender;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form allowing an admin to mark a session as ended.
 * Sends an event_ended message to the Facturatie queue via RabbitMQ.
 */
class SessionEndForm extends FormBase
{
    public function __construct(
        private readonly EventEndedSender $eventEndedSender,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('rabbitmq_sender.event_ended_sender'),
        );
    }

    public function getFormId(): string
    {
        return 'session_management_session_end_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $options = $this->getSessionOptions();

        if (empty($options)) {
            $form['notice'] = [
                '#markup' => '<p>' . $this->t('No active sessions available.') . '</p>',
            ];
            return $form;
        }

        $form['session_id'] = [
            '#type'         => 'select',
            '#title'        => $this->t('Select session to end'),
            '#options'      => $options,
            '#required'     => true,
            '#empty_option' => $this->t('— Select a session —'),
        ];

        $form['submit'] = [
            '#type'  => 'submit',
            '#value' => $this->t('End session'),
            '#attributes' => ['class' => ['button', 'button--danger']],
        ];

        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        if (empty($form_state->getValue('session_id'))) {
            $form_state->setErrorByName('session_id', $this->t('Please select a session.'));
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $sessionId = (string) $form_state->getValue('session_id');

        try {
            $this->eventEndedSender->send(['session_id' => $sessionId]);
            $this->messenger()->addStatus($this->t('Session @id has been marked as ended.', ['@id' => $sessionId]));
        } catch (\InvalidArgumentException $e) {
            $this->messenger()->addError($e->getMessage());
        } catch (\Throwable $e) {
            $this->messenger()->addError($this->t('Failed to end session. Please try again later.'));
        }
    }

    /**
     * Builds a session options list from Drupal State (populated by SessionViewResponseReceiver).
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
}
