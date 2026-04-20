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
            $form_state->setErrorByName('company_fields][vat_number', $this->t('VAT number is required for companies.'));
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
        ];

        try {
            $this->registrationService->register($data);

            $this->tempStoreFactory
                ->get('registration_form')
                ->set('confirmation', [
                    'name' => trim($data['first_name'] . ' ' . $data['last_name']),
                ]);

            $form_state->setRedirectUrl(Url::fromRoute('registration_form.confirmation'));
        } catch (\InvalidArgumentException $e) {
            $this->messenger()->addError($this->t('Registration failed: @error', ['@error' => $e->getMessage()]));
        }
    }
}
