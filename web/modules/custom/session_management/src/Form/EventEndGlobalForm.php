<?php

declare(strict_types=1);

namespace Drupal\session_management\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rabbitmq_sender\EventEndedSender;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form allowing an admin to mark the entire event as ended.
 * Requires admin password confirmation.
 */
class EventEndGlobalForm extends FormBase
{
    public function __construct(
        private readonly EventEndedSender $eventEndedSender
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('rabbitmq_sender.event_ended_sender')
        );
    }

    public function getFormId(): string
    {
        return 'session_management_event_end_global_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $form['description'] = [
            '#markup' => '<p><strong>' . $this->t('Waarschuwing:') . '</strong> ' . $this->t('Hiermee beëindig je het gehele event. Dit is de trigger voor facturatie om alle facturen te versturen. Deze actie kan niet ongedaan worden gemaakt.') . '</p>',
        ];

        $form['password'] = [
            '#type' => 'password',
            '#title' => $this->t('Admin wachtwoord'),
            '#description' => $this->t('Voer uw wachtwoord in om te bevestigen dat u het event wilt beëindigen.'),
            '#required' => true,
        ];

        $form['actions'] = [
            '#type' => 'actions',
        ];

        $form['actions']['submit'] = [
            '#type'  => 'submit',
            '#value' => $this->t('Event beëindigen'),
            '#attributes' => ['class' => ['button', 'button--danger']],
        ];
        
        $form['actions']['cancel'] = [
            '#type' => 'link',
            '#title' => $this->t('Annuleren'),
            '#url' => \Drupal\Core\Url::fromRoute('session_management.admin'),
            '#attributes' => ['class' => ['button']],
        ];

        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        $password = $form_state->getValue('password');
        $account = $this->currentUser();
        
        // Use user.auth service to authenticate
        /** @var \Drupal\user\UserAuthInterface $user_auth */
        $user_auth = \Drupal::service('user.auth');
        
        $uid = $user_auth->authenticate($account->getAccountName(), $password);
        
        if (!$uid) {
            $form_state->setErrorByName('password', $this->t('Ongeldig wachtwoord. U kunt het event niet beëindigen.'));
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        try {
            // Send the event_ended message for the global event.
            // Using 'GLOBAL' as session_id to indicate the whole event.
            $this->eventEndedSender->send(['session_id' => 'GLOBAL']);
            $this->messenger()->addStatus($this->t('Het event is succesvol beëindigd. Facturen worden verstuurd.'));
        } catch (\Exception $e) {
            $this->messenger()->addError($this->t('Er ging iets mis bij het beëindigen van het event: @error', ['@error' => $e->getMessage()]));
        }

        $form_state->setRedirect('session_management.admin');
    }
}
