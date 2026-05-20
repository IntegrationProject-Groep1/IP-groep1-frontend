<?php

declare(strict_types=1);

namespace Drupal\registration_form\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\registration_form\Service\RegistrationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds and validates the public registration form.
 */
class RegistrationForm extends FormBase
{
    public function __construct(
        private readonly RegistrationService $registrationService,
        private readonly PrivateTempStoreFactory $tempStoreFactory,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('registration_form.registration_service'),
            $container->get('tempstore.private'),
        );
    }

    public function getFormId(): string
    {
        return 'registration_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        // Check for an invite token in the query string.
        // FormBase already owns $requestStack; use \Drupal::request() to avoid redeclaring it.
        $inviteToken  = (string) (\Drupal::request()->query->get('invite_token') ?? '');
        $invitedEmail = '';
        if ($inviteToken !== '' && \Drupal::hasService('company_invite.invite_service')) {
            /** @var \Drupal\company_invite\Service\InviteService $inviteService */
            $inviteService = \Drupal::service('company_invite.invite_service');
            $invitedEmail  = $inviteService->getEmailForToken($inviteToken) ?? '';
        }

        // Store the validated token in form state for use during submit.
        if ($invitedEmail !== '') {
            $form_state->set('invite_token', $inviteToken);
        }

        $form['first_name'] = [
            '#type'     => 'textfield',
            '#title'    => $this->t('First name'),
            '#required' => true,
        ];

        $form['last_name'] = [
            '#type'     => 'textfield',
            '#title'    => $this->t('Last name'),
            '#required' => true,
        ];

        $emailAttributes = [];
        if ($invitedEmail !== '') {
            // Lock the email field when the user arrives via a company invite link.
            $emailAttributes = [
                'readonly' => 'readonly',
                'class'    => ['invited-email'],
            ];
        }

        $form['email'] = [
            '#type'          => 'email',
            '#title'         => $this->t('Email address'),
            '#required'      => true,
            '#default_value' => $invitedEmail,
            '#attributes'    => $emailAttributes,
        ];

        $form['password'] = [
            '#type'     => 'password',
            '#title'    => $this->t('Password'),
            '#required' => true,
            '#attributes' => [
                'autocomplete' => 'new-password',
            ],
        ];

        $form['password_confirm'] = [
            '#type'     => 'password',
            '#title'    => $this->t('Confirm password'),
            '#required' => true,
            '#attributes' => [
                'autocomplete' => 'new-password',
            ],
        ];

        $form['date_of_birth'] = [
            '#type'     => 'date',
            '#title'    => $this->t('Date of birth'),
            '#required' => true,
        ];

        $form['is_company'] = [
            '#type'  => 'checkbox',
            '#title' => $this->t('I am registering as a company'),
        ];

        $form['company_fields'] = [
            '#type'  => 'fieldset',
            '#title' => $this->t('Company details'),
            '#states' => [
                'visible' => [
                    ':input[name="is_company"]' => ['checked' => true],
                ],
            ],
        ];

        $form['company_fields']['company_name'] = [
            '#type'  => 'textfield',
            '#title' => $this->t('Company name'),
            '#states' => [
                'required' => [
                    ':input[name="is_company"]' => ['checked' => true],
                ],
            ],
        ];

        $form['company_fields']['vat_number'] = [
            '#type'  => 'textfield',
            '#title' => $this->t('VAT number'),
            '#states' => [
                'required' => [
                    ':input[name="is_company"]' => ['checked' => true],
                ],
            ],
        ];

        $form['company_fields']['street'] = [
            '#type'        => 'textfield',
            '#title'       => $this->t('Street and number'),
            '#placeholder' => $this->t('e.g. Keizersgracht 123'),
            '#states' => [
                'required' => [
                    ':input[name="is_company"]' => ['checked' => true],
                ],
            ],
        ];

        $form['company_fields']['postal_code'] = [
            '#type'        => 'textfield',
            '#title'       => $this->t('Postal code'),
            '#placeholder' => $this->t('e.g. 1234 AB'),
            '#states' => [
                'required' => [
                    ':input[name="is_company"]' => ['checked' => true],
                ],
            ],
        ];

        $form['company_fields']['municipality'] = [
            '#type'        => 'textfield',
            '#title'       => $this->t('Municipality / City'),
            '#placeholder' => $this->t('e.g. Amsterdam'),
            '#states' => [
                'required' => [
                    ':input[name="is_company"]' => ['checked' => true],
                ],
            ],
        ];

        $form['terms_accepted'] = [
            '#type'     => 'checkbox',
            '#title'    => $this->t(
                'Ik ga akkoord met de <a href="/algemene-voorwaarden" target="_blank">algemene voorwaarden</a> en het <a href="/privacybeleid" target="_blank">privacybeleid</a>.'
            ),
            '#required' => false,
        ];

        $form['submit'] = [
            '#type'  => 'submit',
            '#value' => $this->t('Register'),
        ];

        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        $isCompany = (bool) $form_state->getValue('is_company');
        $password = (string) $form_state->getValue('password');
        $passwordConfirm = (string) $form_state->getValue('password_confirm');

        if (strlen($password) < 8) {
            $form_state->setErrorByName('password', $this->t('Password must be at least 8 characters long.'));
        }

        if ($password !== $passwordConfirm) {
            $form_state->setErrorByName('password_confirm', $this->t('Password confirmation does not match.'));
        }

        if ($isCompany && empty(trim((string) $form_state->getValue('company_name')))) {
            $form_state->setErrorByName('company_fields][company_name', $this->t('Company name is required for companies.'));
        }

        if ($isCompany && empty(trim((string) $form_state->getValue('vat_number')))) {
            $form_state->setErrorByName('company_fields][vat_number', $this->t('BTW-nummer is verplicht voor bedrijven.'));
        }

        if ($isCompany && empty(trim((string) $form_state->getValue('street')))) {
            $form_state->setErrorByName('company_fields][street', $this->t('Straat is verplicht voor bedrijven.'));
        }

        if ($isCompany && empty(trim((string) $form_state->getValue('postal_code')))) {
            $form_state->setErrorByName('company_fields][postal_code', $this->t('Postcode is verplicht voor bedrijven.'));
        }

        if ($isCompany && empty(trim((string) $form_state->getValue('municipality')))) {
            $form_state->setErrorByName('company_fields][municipality', $this->t('Gemeente is verplicht voor bedrijven.'));
        }

        if (!$form_state->getValue('terms_accepted')) {
            $form_state->setErrorByName('terms_accepted', $this->t('Je moet akkoord gaan met de algemene voorwaarden en het privacybeleid om je te kunnen registreren.'));
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $data = [
            'first_name'    => $form_state->getValue('first_name'),
            'last_name'     => $form_state->getValue('last_name'),
            'email'         => $form_state->getValue('email'),
            'password'      => $form_state->getValue('password'),
            'date_of_birth' => $form_state->getValue('date_of_birth') ?? '',
            'is_company'    => (bool) $form_state->getValue('is_company'),
            'company_name'  => $form_state->getValue('company_name') ?? '',
            'vat_number'    => $form_state->getValue('vat_number') ?? '',
            'street'        => $form_state->getValue('street') ?? '',
            'postal_code'   => $form_state->getValue('postal_code') ?? '',
            'municipality'  => $form_state->getValue('municipality') ?? '',
        ];

        try {
            $this->registrationService->register($data);

            // Mark the invite token as used so it cannot be replayed.
            $inviteToken = (string) ($form_state->get('invite_token') ?? '');
            if ($inviteToken !== '' && \Drupal::hasService('company_invite.invite_service')) {
                /** @var \Drupal\company_invite\Service\InviteService $inviteService */
                $inviteService = \Drupal::service('company_invite.invite_service');
                $inviteService->markTokenUsed($inviteToken);
            }

            // Log the user in automatically after registration.
            $users = \Drupal::entityTypeManager()->getStorage('user')
                ->loadByProperties(['mail' => $data['email']]);
            $account = reset($users);
            if ($account && $account->isActive()) {
                user_login_finalize($account);
            }

            $form_state->setRedirectUrl(Url::fromRoute('<front>'));
        } catch (\InvalidArgumentException $e) {
            $this->messenger()->addError($this->t('Registration failed: @error', ['@error' => $e->getMessage()]));
        }
    }
}
