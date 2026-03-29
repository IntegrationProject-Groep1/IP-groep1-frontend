<?php

declare(strict_types=1);

namespace Drupal\registration_form\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\registration_form\Service\RegistrationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class RegistrationForm extends FormBase
{
    public function __construct(
        private readonly RegistrationService $registrationService,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('registration_form.registration_service'),
        );
    }

    public function getFormId(): string
    {
        return 'registration_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
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

        $form['email'] = [
            '#type'     => 'email',
            '#title'    => $this->t('Email address'),
            '#required' => true,
        ];

        // Sessions are hardcoded for now; replace with a database query once
        // the Planning team's session data is available.
        $form['session_id'] = [
            '#type'     => 'select',
            '#title'    => $this->t('Session'),
            '#required' => true,
            '#options'  => $this->getSessionOptions(),
            '#empty_option' => $this->t('— Select a session —'),
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

        $form['submit'] = [
            '#type'  => 'submit',
            '#value' => $this->t('Register'),
        ];

        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        $isCompany = (bool) $form_state->getValue('is_company');

        if ($isCompany && empty(trim((string) $form_state->getValue('company_name')))) {
            $form_state->setErrorByName('company_fields][company_name', $this->t('Company name is required for companies.'));
        }

        if ($isCompany && empty(trim((string) $form_state->getValue('vat_number')))) {
            $form_state->setErrorByName('company_fields][vat_number', $this->t('VAT number is required for companies.'));
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $sessionId = $form_state->getValue('session_id');

        $data = [
            'first_name'   => $form_state->getValue('first_name'),
            'last_name'    => $form_state->getValue('last_name'),
            'email'        => $form_state->getValue('email'),
            'session_id'   => $sessionId,
            'session_name' => $this->getSessionOptions()[$sessionId] ?? $sessionId,
            'is_company'   => (bool) $form_state->getValue('is_company'),
            'company_name' => $form_state->getValue('company_name') ?? '',
            'vat_number'   => $form_state->getValue('vat_number') ?? '',
        ];

        try {
            $this->registrationService->register($data);
            $this->messenger()->addStatus($this->t('You have been successfully registered!'));
        } catch (\InvalidArgumentException $e) {
            $this->messenger()->addError($this->t('Registration failed: @error', ['@error' => $e->getMessage()]));
        }
    }

    /**
     * Returns the available sessions as a select list.
     * Replace this with a database/API call once session data is available.
     */
    private function getSessionOptions(): array
    {
        return [
            '550e8400-e29b-41d4-a716-446655440000' => 'Workshop AI — 10 April 2026',
            '550e8400-e29b-41d4-a716-446655440001' => 'Workshop Cloud — 17 April 2026',
            '550e8400-e29b-41d4-a716-446655440002' => 'Workshop Security — 24 April 2026',
        ];
    }
}
