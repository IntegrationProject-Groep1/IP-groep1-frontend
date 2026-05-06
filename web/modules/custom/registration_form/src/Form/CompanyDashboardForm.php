<?php

declare(strict_types=1);

namespace Drupal\registration_form\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\registration_form\Service\CompanyInvitationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Dashboard form where company users can invite attendees by email.
 */
class CompanyDashboardForm extends FormBase
{
    public function __construct(
        private readonly CompanyInvitationService $companyInvitationService,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('registration_form.company_invitation_service'),
        );
    }

    public function getFormId(): string
    {
        return 'registration_form_company_dashboard_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        if (!$this->companyInvitationService->currentUserIsCompany()) {
            throw new AccessDeniedHttpException('This dashboard is only available for company accounts.');
        }

        $companyName = $this->companyInvitationService->getCurrentCompanyName();
        $inviteeCount = (int) ($form_state->get('invitee_count') ?? 1);
        if ($inviteeCount < 1) {
            $inviteeCount = 1;
        }

        $form['header'] = [
            '#type' => 'item',
            '#markup' => '<div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">'
                . '<div>'
                . '<p class="section-label">' . $this->t('Company dashboard') . '</p>'
                . '<h1 class="text-4xl font-bold tracking-tight leading-tight" style="color:var(--color-text);">' . $companyName . '</h1>'
                . '<p class="mt-2 text-sm max-w-2xl" style="color:var(--color-muted);">' . $this->t('Invite people to your company account by entering one or more email addresses.') . '</p>'
                . '</div>'
                . '<div class="msg-warning max-w-md">' . $this->t('Invitations are sent as XML to CRM through crm.incoming.') . '</div>'
                . '</div>',
        ];

        $form['overview'] = [
            '#type' => 'container',
            '#attributes' => [
                'class' => ['grid', 'grid-cols-1', 'sm:grid-cols-2', 'lg:grid-cols-3', 'gap-4', 'mb-8'],
            ],
        ];

        $form['overview']['active_members'] = [
            '#type' => 'item',
            '#markup' => '<div class="card p-4"><p class="text-sm" style="color:var(--color-muted);">' . $this->t('Active members') . '</p><p class="mt-2 text-4xl font-bold" style="color:var(--color-text);">' . $this->t('18') . '</p></div>',
        ];

        $form['overview']['pending_invites'] = [
            '#type' => 'item',
            '#markup' => '<div class="card p-4"><p class="text-sm" style="color:var(--color-muted);">' . $this->t('Pending invites') . '</p><p class="mt-2 text-4xl font-bold" style="color:var(--color-text);">' . $this->t('4') . '</p></div>',
        ];

        $form['overview']['sent_this_month'] = [
            '#type' => 'item',
            '#markup' => '<div class="card p-4"><p class="text-sm" style="color:var(--color-muted);">' . $this->t('Sent this month') . '</p><p class="mt-2 text-4xl font-bold" style="color:var(--color-text);">' . $this->t('27') . '</p></div>',
        ];

        $form['layout'] = [
            '#type' => 'container',
            '#attributes' => [
                'class' => ['grid', 'grid-cols-1', 'lg:grid-cols-3', 'gap-6'],
            ],
        ];

        $form['layout']['invite_panel'] = [
            '#type' => 'container',
            '#attributes' => [
                'class' => ['card', 'p-8', 'lg:col-span-2'],
            ],
        ];

        $form['layout']['invite_panel']['invite_header'] = [
            '#type' => 'item',
            '#markup' => '<div class="flex items-center justify-between mb-6"><div><p class="section-label">' . $this->t('Invite people') . '</p><h2 class="text-2xl font-bold tracking-tight" style="color:var(--color-text);">' . $this->t('Quick invite panel') . '</h2></div><button class="button--primary pointer-events-none" type="button" aria-hidden="true">+</button></div>',
        ];

        $form['layout']['invite_panel']['invitees_container'] = [
            '#type' => 'container',
            '#attributes' => ['id' => 'invitees-wrapper'],
            '#tree' => true,
        ];

        for ($i = 0; $i < $inviteeCount; $i++) {
            $form['layout']['invite_panel']['invitees_container']['invitees'][$i] = [
                '#type' => 'email',
                '#title' => $this->t('Email @number', ['@number' => (string) ($i + 1)]),
                '#required' => false,
            ];
        }

        $form['layout']['invite_panel']['invite_actions'] = [
            '#type' => 'container',
            '#attributes' => [
                'class' => ['mt-4'],
            ],
        ];

        $form['layout']['invite_panel']['invite_actions']['actions'] = [
            '#type' => 'actions',
        ];

        $form['layout']['invite_panel']['invite_actions']['actions']['add_invitee'] = [
            '#type' => 'submit',
            '#value' => $this->t('+'),
            '#submit' => ['::addInvitee'],
            '#limit_validation_errors' => [],
            '#ajax' => [
                'callback' => '::refreshInvitees',
                'wrapper' => 'invitees-wrapper',
            ],
        ];

        $form['layout']['invite_panel']['invite_actions']['actions']['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Send invitations'),
            '#button_type' => 'primary',
        ];

        $form['layout']['side_panel'] = [
            '#type' => 'container',
            '#attributes' => [
                'class' => ['card', 'p-8'],
            ],
        ];

        $form['layout']['side_panel']['overview_title'] = [
            '#type' => 'item',
            '#markup' => '<p class="section-label">' . $this->t('Company overview') . '</p><div class="mb-6 rounded-lg p-4" style="background:linear-gradient(135deg, color-mix(in srgb, var(--color-primary) 15%, white) 0%, color-mix(in srgb, var(--color-accent) 10%, white) 100%);"><p class="text-sm" style="color:var(--color-muted);">' . $this->t('Company name') . '</p><p class="mt-1 text-lg font-bold" style="color:var(--color-text);">' . $companyName . '</p><p class="mt-2 text-sm" style="color:var(--color-muted);">' . $this->t('This panel reflects the logged-in company profile.') . '</p></div>',
        ];

        $form['layout']['side_panel']['overview_items'] = [
            '#type' => 'item',
            '#markup' => '<div class="space-y-4">'
                . '<div class="rounded-lg border p-4" style="border-color:var(--color-border);"><p class="text-sm" style="color:var(--color-muted);">' . $this->t('Primary action') . '</p><p class="mt-1 font-semibold" style="color:var(--color-text);">' . $this->t('Invite colleagues by email') . '</p></div>'
                . '<div class="rounded-lg border p-4" style="border-color:var(--color-border);"><p class="text-sm" style="color:var(--color-muted);">' . $this->t('Access rule') . '</p><p class="mt-1 font-semibold" style="color:var(--color-text);">' . $this->t('Company accounts only') . '</p></div>'
                . '<div class="rounded-lg border p-4" style="border-color:var(--color-border);"><p class="text-sm" style="color:var(--color-muted);">' . $this->t('CRM sync') . '</p><p class="mt-1 font-semibold" style="color:var(--color-text);">' . $this->t('XML message sent to crm.incoming') . '</p></div>'
                . '</div>',
        ];

        return $form;
    }

    public function addInvitee(array &$form, FormStateInterface $form_state): void
    {
        $inviteeCount = (int) ($form_state->get('invitee_count') ?? 1);
        $form_state->set('invitee_count', $inviteeCount + 1);
        $form_state->setRebuild(true);
    }

    public function refreshInvitees(array &$form, FormStateInterface $form_state): array
    {
        return $form['layout']['invite_panel']['invitees_container'];
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        $emails = $this->extractUniqueEmails($form_state);

        if (count($emails) === 0) {
            $form_state->setErrorByName('invitees_container', $this->t('Enter at least one email address.'));
            return;
        }

        foreach ($emails as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $form_state->setErrorByName('invitees_container', $this->t('The email address %mail is not valid.', ['%mail' => $email]));
                return;
            }
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $emails = $this->extractUniqueEmails($form_state);

        try {
            $sentCount = $this->companyInvitationService->sendInvitations($emails);
            $this->messenger()->addStatus($this->t('Sent @count invitation(s) to CRM.', ['@count' => (string) $sentCount]));
        } catch (\Throwable $e) {
            $this->messenger()->addError($this->t('Failed to send invitation(s): @error', ['@error' => $e->getMessage()]));
        }
    }

    /**
     * @return list<string>
     */
    private function extractUniqueEmails(FormStateInterface $form_state): array
    {
        $values = $form_state->getValue(['invitees_container', 'invitees']);
        if (!is_array($values)) {
            return [];
        }

        $emails = [];
        foreach ($values as $value) {
            $email = strtolower(trim((string) $value));
            if ($email === '') {
                continue;
            }
            $emails[] = $email;
        }

        return array_values(array_unique($emails));
    }
}
