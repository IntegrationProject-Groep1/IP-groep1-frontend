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
            '#title'       => $this->t('Colleague\'s email address'),
            '#required'    => true,
            '#attributes'  => ['placeholder' => 'colleague@studio-twelve.be'],
        ];

        $form['submit'] = [
            '#type'  => 'submit',
            '#value' => $this->t('Send invite'),
        ];

        // Attach invite list and company name for the Twig template.
        $uid       = (int) $this->currentUser->id();
        $ownerUuid = \Drupal::service('user.data')->get('registration_form', $uid, 'master_uuid') ?: 'uid-' . $uid;
        $rawInvites = $this->inviteService->getInvitesForOwner($ownerUuid);
        $now = time();

        $invites = [];

        // Include the current user as the "owner" row at the top.
        $fullUser = \Drupal\user\Entity\User::load($uid);
        $firstName = $fullUser && $fullUser->hasField('field_first_name') ? (string) $fullUser->get('field_first_name')->value : '';
        $lastName  = $fullUser && $fullUser->hasField('field_last_name')  ? (string) $fullUser->get('field_last_name')->value  : '';
        $ownerName = trim("$firstName $lastName") ?: $this->currentUser->getDisplayName();
        $invites[] = [
            'name'       => $ownerName,
            'email'      => $this->currentUser->getEmail(),
            'sent'       => '—',
            'status'     => 'owner',
            'remove_url' => null,
            'resend_url' => null,
        ];

        foreach ($rawInvites as $invite) {
            $expired = (int) $invite['expires'] < $now;
            $used    = (int) $invite['used'] === 1;
            $status  = $used ? 'accepted' : ($expired ? 'expired' : 'pending');
            try {
                $deleteUrl = \Drupal\Core\Url::fromRoute('company_invite.delete_invite', ['token' => $invite['token']])->toString();
            } catch (\Exception) {
                $deleteUrl = null;
            }
            $invites[] = [
                'name'       => null,
                'email'      => $invite['email'],
                'sent'       => date('j M', (int) $invite['created']),
                'status'     => $status,
                'remove_url' => $deleteUrl,
                'resend_url' => null,
            ];
        }

        $companyName = $fullUser && $fullUser->hasField('field_company_name')
            ? (string) $fullUser->get('field_company_name')->value : '';

        $form['#invites']      = $invites;
        $form['#company_name'] = $companyName;

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
