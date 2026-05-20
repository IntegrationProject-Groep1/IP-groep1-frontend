<?php

declare(strict_types=1);

namespace Drupal\microsoft_auth\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\microsoft_auth\Controller\MicrosoftAuthController;
use Drupal\registration_form\Service\RegistrationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Collects missing user data after Microsoft OAuth for new users.
 * Pre-fills name/email from Microsoft profile, asks for birth date + terms.
 */
class MicrosoftCompleteForm extends FormBase
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
        return 'microsoft_auth_complete_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $session = \Drupal::request()->getSession();
        $profile = $session->get('microsoft_auth_profile', []);

        if (empty($profile['email'])) {
            $this->messenger()->addError($this->t('Session expired. Please try logging in with Microsoft again.'));
            $form_state->setRedirectUrl(Url::fromRoute('<front>'));
            return $form;
        }

        $form['info'] = [
            '#markup' => '<div class="ms-auth-info" style="margin-bottom:16px;padding:12px;background:#f0f4ff;border-radius:6px;">'
                . '<strong>' . $this->t('Almost there!') . '</strong> '
                . $this->t('We just need a few more details to complete your account.')
                . '</div>',
        ];

        $form['first_name'] = [
            '#type'          => 'textfield',
            '#title'         => $this->t('First name'),
            '#required'      => true,
            '#default_value' => $profile['first_name'] ?? '',
        ];

        $form['last_name'] = [
            '#type'          => 'textfield',
            '#title'         => $this->t('Last name'),
            '#required'      => true,
            '#default_value' => $profile['last_name'] ?? '',
        ];

        $form['email'] = [
            '#type'          => 'email',
            '#title'         => $this->t('Email address'),
            '#default_value' => $profile['email'] ?? '',
            '#attributes'    => ['readonly' => 'readonly'],
            '#description'   => $this->t('This is your Microsoft account email and cannot be changed.'),
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
                'visible' => [':input[name="is_company"]' => ['checked' => true]],
            ],
        ];

        $form['company_fields']['company_name'] = [
            '#type'  => 'textfield',
            '#title' => $this->t('Company name'),
            '#states' => [
                'required' => [':input[name="is_company"]' => ['checked' => true]],
            ],
        ];

        $form['company_fields']['vat_number'] = [
            '#type'  => 'textfield',
            '#title' => $this->t('VAT number'),
            '#states' => [
                'required' => [':input[name="is_company"]' => ['checked' => true]],
            ],
        ];

        $form['terms_accepted'] = [
            '#type'     => 'checkbox',
            '#title'    => $this->t(
                'I agree to the <a href="/algemene-voorwaarden" target="_blank">terms and conditions</a> and <a href="/privacybeleid" target="_blank">privacy policy</a>.'
            ),
            '#required' => false,
        ];

        $form['submit'] = [
            '#type'  => 'submit',
            '#value' => $this->t('Complete registration'),
        ];

        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        if (!$form_state->getValue('terms_accepted')) {
            $form_state->setErrorByName('terms_accepted', $this->t('You must accept the terms and conditions.'));
        }

        $isCompany = (bool) $form_state->getValue('is_company');
        if ($isCompany && empty(trim((string) $form_state->getValue('company_name')))) {
            $form_state->setErrorByName('company_fields][company_name', $this->t('Company name is required.'));
        }
        if ($isCompany && empty(trim((string) $form_state->getValue('vat_number')))) {
            $form_state->setErrorByName('company_fields][vat_number', $this->t('VAT number is required.'));
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $session = \Drupal::request()->getSession();
        $profile = $session->get('microsoft_auth_profile', []);
        $tokens  = $session->get('microsoft_auth_tokens',  []);

        if (empty($profile['email']) || empty($tokens['access_token'])) {
            $this->messenger()->addError($this->t('Session expired. Please try again.'));
            $form_state->setRedirectUrl(Url::fromUri('internal:/auth/microsoft'));
            return;
        }

        // Generate a random password — user will always login via Microsoft.
        $randomPassword = bin2hex(random_bytes(16));

        $data = [
            'email'         => $profile['email'],
            'password'      => $randomPassword,
            'first_name'    => $form_state->getValue('first_name'),
            'last_name'     => $form_state->getValue('last_name'),
            'date_of_birth' => $form_state->getValue('date_of_birth') ?? '',
            'is_company'    => (bool) $form_state->getValue('is_company'),
            'company_name'  => $form_state->getValue('company_name') ?? '',
            'vat_number'    => $form_state->getValue('vat_number')   ?? '',
        ];

        try {
            $this->registrationService->register($data);
        } catch (\InvalidArgumentException $e) {
            $this->messenger()->addError($this->t('Registration failed: @error', ['@error' => $e->getMessage()]));
            return;
        }

        // Load the newly created account and log them in.
        $users   = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => $data['email']]);
        $account = reset($users);

        if ($account && $account->isActive()) {
            user_login_finalize($account);

            // Store Microsoft OAuth tokens in planning service.
            $uid        = (int) $account->id();
            $masterUuid = (string) (\Drupal::service('user.data')->get('registration_form', $uid, 'master_uuid') ?? '');
            $identityId = $masterUuid !== '' ? $masterUuid : (string) $uid;

            MicrosoftAuthController::storeTokensInPlanning($identityId, $tokens);
        }

        // Clear session data.
        $session->remove('microsoft_auth_tokens');
        $session->remove('microsoft_auth_profile');

        $this->messenger()->addStatus($this->t('Welcome! Your account has been created and connected to Microsoft.'));
        $form_state->setRedirectUrl(Url::fromRoute('<front>'));
    }
}
