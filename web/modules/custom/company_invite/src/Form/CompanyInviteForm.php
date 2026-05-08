<?php

declare(strict_types=1);

namespace Drupal\company_invite\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\company_invite\Service\InviteService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dashboard form for company admins to invite team members by email.
 */
class CompanyInviteForm extends FormBase
{
    public function __construct(
        private readonly InviteService $inviteService,
        private readonly AccountProxyInterface $currentUser,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('company_invite.invite_service'),
            $container->get('current_user'),
        );
    }

    public function getFormId(): string
    {
        return 'company_invite_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $form['invite_email'] = [
            '#type'        => 'email',
            '#title'       => $this->t('Email address'),
            '#description' => $this->t('Enter the email address of the person you want to invite to your company account.'),
            '#required'    => true,
            '#attributes'  => ['placeholder' => 'colleague@example.com'],
        ];

        $form['submit'] = [
            '#type'  => 'submit',
            '#value' => $this->t('Send invite'),
        ];

        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        $email = strtolower(trim((string) $form_state->getValue('invite_email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $form_state->setErrorByName('invite_email', $this->t('Please enter a valid email address.'));
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $email = strtolower(trim((string) $form_state->getValue('invite_email')));
        $uid   = (int) $this->currentUser->id();

        try {
            $this->inviteService->sendInvite($email, $uid);
            $this->messenger()->addStatus($this->t(
                'An invitation has been sent to @email.',
                ['@email' => $email],
            ));
        } catch (\InvalidArgumentException $e) {
            $this->messenger()->addError($this->t('Could not send invite: @error', ['@error' => $e->getMessage()]));
        }
    }
}
