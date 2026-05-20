<?php

declare(strict_types=1);

namespace Drupal\company_invite\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;

/**
 * Dashboard form for company admins to invite team members by email.
 */
class CompanyInviteForm extends FormBase
{
    public function getFormId(): string
    {
        return 'company_invite_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        // Email invitation field
        $form['invite_email'] = [
            '#type' => 'email',
            '#title' => $this->t('Email address'),
            '#description' => $this->t('Enter the email address of the person you want to invite.'),
            '#maxlength' => 254,
        ];

        // CSV file upload field
        $form['csv_file'] = [
            '#type' => 'managed_file',
            '#title' => $this->t('Or import from CSV file'),
            '#description' => $this->t('Upload a CSV file with columns: email, firstName, lastName'),
            '#upload_location' => 'public://csv_imports/',
            '#upload_validators' => [
                'file_validate_extensions' => ['csv'],
                'file_validate_size' => [5242880], // 5 MB
            ],
        ];

        // Submit button
        $form['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Send invite'),
        ];

        // Attach invite list and company name for the Twig template.
        $currentUser = \Drupal::currentUser();
        $uid = (int) $currentUser->id();
        $ownerUuid = \Drupal::service('user.data')->get('registration_form', $uid, 'master_uuid') ?: 'uid-' . $uid;
        
        $inviteService = \Drupal::service('company_invite.invite_service');
        $rawInvites = $inviteService->getInvitesForOwner($ownerUuid);
        $now = time();

        $invites = [];

        // Include the current user as the "owner" row at the top.
        $fullUser = \Drupal\user\Entity\User::load($uid);
        $firstName = $fullUser && $fullUser->hasField('field_first_name') ? (string) $fullUser->get('field_first_name')->value : '';
        $lastName  = $fullUser && $fullUser->hasField('field_last_name')  ? (string) $fullUser->get('field_last_name')->value  : '';
        $ownerName = trim("$firstName $lastName") ?: $currentUser->getDisplayName();
        $invites[] = [
            'name'       => $ownerName,
            'email'      => $currentUser->getEmail(),
            'sent'       => '–',
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

        $form['invites_data'] = [
            '#type' => 'value',
            '#value' => $invites,
        ];
        
        $form['company_name_data'] = [
            '#type' => 'value',
            '#value' => $companyName,
        ];
        
        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        $email = strtolower(trim((string) $form_state->getValue('invite_email')));
        $csv_file = $form_state->getValue('csv_file');

        if (empty($email) && empty($csv_file)) {
            $form_state->setError($form, $this->t('Please provide either an email address or upload a CSV file.'));
            return;
        }

        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $form_state->setErrorByName('invite_email', $this->t('Please enter a valid email address.'));
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $inviteService = \Drupal::service('company_invite.invite_service');
        $currentUser = \Drupal::currentUser();
        $uid = (int) $currentUser->id();
        $emails_to_invite = [];

        $email = strtolower(trim((string) $form_state->getValue('invite_email')));
        if (!empty($email)) {
            $emails_to_invite[] = [
                'email'      => $email,
                'first_name' => '',
                'last_name'  => '',
            ];
        }

        $csv_file = $form_state->getValue('csv_file');
        if (!empty($csv_file)) {
            $file_id = $csv_file[0] ?? null;
            if ($file_id) {
                $file = File::load($file_id);
                if ($file) {
                    $file_uri = $file->getFileUri();
                    $csv_emails = $this->parseCsvFile($file_uri);
                    $emails_to_invite = array_merge($emails_to_invite, $csv_emails);
                }
            }
        }

        $success_count = 0;
        $failed_emails = [];

        foreach ($emails_to_invite as $email_data) {
            try {
                $inviteService->sendInvite(
                    $email_data['email'],
                    $uid,
                    $email_data['first_name'],
                    $email_data['last_name'],
                );
                $success_count++;
            } catch (\Exception $e) {
                $failed_emails[$email_data['email']] = $e->getMessage();
            }
        }

        if ($success_count > 0) {
            $this->messenger()->addStatus($this->t(
                '@count invitation(s) sent successfully.',
                ['@count' => $success_count],
            ));
        }

        if (!empty($failed_emails)) {
            foreach ($failed_emails as $email => $reason) {
                $this->messenger()->addWarning($this->t(
                    'Failed to send invite to @email: @reason',
                    ['@email' => $email, '@reason' => $reason],
                ));
            }
        }
    }

    private function parseCsvFile(string $file_uri): array
    {
        $emails = [];
        $file_path = \Drupal::service('file_system')->realpath($file_uri);

        if (!$file_path || !file_exists($file_path)) {
            return [];
        }

        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            return [];
        }

        $row_num = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;

            // Skip header row (first row) if it contains text instead of email
            if ($row_num === 1 && !filter_var(trim((string) $row[0]), FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            if (empty($row[0])) {
                continue;
            }

            $email = strtolower(trim((string) $row[0]));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $first_name = trim((string) ($row[1] ?? ''));
            $last_name  = trim((string) ($row[2] ?? ''));

            $emails[] = [
                'email'      => $email,
                'first_name' => $first_name,
                'last_name'  => $last_name,
            ];
        }

        fclose($handle);
        return $emails;
    }
}