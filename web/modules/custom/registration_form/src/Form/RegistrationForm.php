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
            '#type'        => 'textfield',
            '#title'       => $this->t('BTW-nummer'),
            '#placeholder' => $this->t('BE0123.456.789'),
            '#description' => $this->t('Belgisch ondernemingsnummer, bv. BE0123.456.789'),
            '#attributes'  => ['id' => 'vat-number-field'],
            '#states' => [
                'required' => [
                    ':input[name="is_company"]' => ['checked' => true],
                ],
            ],
        ];

        $form['company_fields']['vat_feedback'] = [
            '#type'       => 'markup',
            '#markup'     => '<div id="vat-feedback" aria-live="polite" style="margin-top:-10px;margin-bottom:8px;font-size:.85em;min-height:1.2em;"></div>',
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

        $form['#attached']['html_head'][] = [
            [
                '#type'       => 'html_tag',
                '#tag'        => 'script',
                '#value'      => $this->buildVatValidationJs(),
                '#attributes' => ['type' => 'text/javascript'],
            ],
            'vat_validation_js',
        ];

        return $form;
    }

    /**
     * Returns an inline JS snippet that provides live BTW-number feedback.
     *
     * The Belgian enterprise number (KBO) is 10 digits, starts with 0 (or 1 for
     * newer numbers), and the last 2 digits equal 97 − (first 8 digits mod 97).
     */
    private function buildVatValidationJs(): string
    {
        return <<<'JS'
(function () {
  'use strict';

  function normalizeBtw(raw) {
    var s = raw.trim().toUpperCase().replace(/\s/g, '');
    if (s.startsWith('BE')) { s = s.slice(2); }
    return s.replace(/[.\- ]/g, '');
  }

  function validateBtw(raw) {
    var digits = normalizeBtw(raw);
    if (!/^\d{10}$/.test(digits)) {
      return { valid: false, msg: 'Moet 10 cijfers bevatten na de "BE"-prefix (bv. BE0123.456.789).' };
    }
    if (digits[0] !== '0' && digits[0] !== '1') {
      return { valid: false, msg: 'Belgisch ondernemingsnummer begint met 0 of 1.' };
    }
    var first8 = parseInt(digits.slice(0, 8), 10);
    var last2  = parseInt(digits.slice(8), 10);
    var expected = 97 - (first8 % 97);
    if (expected !== last2) {
      return { valid: false, msg: 'Controlecijfer klopt niet. Controleer het nummer.' };
    }
    return { valid: true, msg: 'Geldig Belgisch ondernemingsnummer.' };
  }

  function attachVatListener() {
    var field    = document.getElementById('vat-number-field');
    var feedback = document.getElementById('vat-feedback');
    if (!field || !feedback) { return; }

    field.addEventListener('input', function () {
      var val = field.value.trim();
      if (val === '') {
        feedback.textContent = '';
        feedback.style.color = '';
        return;
      }
      var result = validateBtw(val);
      feedback.textContent = result.msg;
      feedback.style.color = result.valid ? '#18a058' : '#d32f2f';
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attachVatListener);
  } else {
    attachVatListener();
  }
})();
JS;
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
        } elseif ($isCompany) {
            $vatError = $this->validateBelgianVatNumber((string) $form_state->getValue('vat_number'));
            if ($vatError !== null) {
                $form_state->setErrorByName('company_fields][vat_number', $vatError);
            }
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

    /**
     * Validates a Belgian BTW/KBO number.
     *
     * Accepts formats: BE0123456789, BE0123.456.789, 0123456789, etc.
     * Returns a translated error string on failure, or null on success.
     */
    private function validateBelgianVatNumber(string $raw): ?string
    {
        // Normalize: strip prefix, dots, dashes, spaces.
        $cleaned = strtoupper(trim($raw));
        if (str_starts_with($cleaned, 'BE')) {
            $cleaned = substr($cleaned, 2);
        }
        $cleaned = preg_replace('/[\.\-\s]/', '', $cleaned);

        if (!preg_match('/^\d{10}$/', $cleaned)) {
            return $this->t('Belgisch BTW-nummer moet 10 cijfers bevatten na de "BE"-prefix (bv. BE0123.456.789).');
        }

        if ($cleaned[0] !== '0' && $cleaned[0] !== '1') {
            return $this->t('Een Belgisch ondernemingsnummer begint altijd met 0 of 1.');
        }

        $first8   = (int) substr($cleaned, 0, 8);
        $last2    = (int) substr($cleaned, 8, 2);
        $expected = 97 - ($first8 % 97);

        if ($expected !== $last2) {
            return $this->t('Het controlecijfer van het BTW-nummer klopt niet. Controleer het nummer.');
        }

        return null;
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
            if ($data['is_company']) {
                // Company registrations are held for admin review before CRM messages are sent.
                $uid = $this->registrationService->registerCompanyPending($data);

                // Log in automatically so they land on the "in review" page.
                $account = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
                if ($account && $account->isActive()) {
                    user_login_finalize($account);
                }

                $form_state->setRedirectUrl(Url::fromRoute('registration_form.company_pending'));
                return;
            }

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
