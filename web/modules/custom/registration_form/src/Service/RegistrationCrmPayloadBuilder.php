<?php

declare(strict_types=1);

namespace Drupal\registration_form\Service;

/**
 * Maps registration data to a sanitized CRM payload structure.
 */
class RegistrationCrmPayloadBuilder
{
    /**
     * Builds a sanitized payload for CRM publication and excludes auth-only fields.
     */
    public function build(array $data, string $userId): array
    {
        $registrationDate = (string) ($data['registration_date'] ?? '');
        if ($registrationDate === '') {
            $registrationDate = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        }

        return [
            'email' => (string) ($data['email'] ?? ''),
            'first_name' => (string) ($data['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? ''),
            'date_of_birth' => (string) ($data['date_of_birth'] ?? ''),
            'registration_date' => $registrationDate,
            'session_id' => is_array($data['session_ids'] ?? null)
                ? implode(',', $data['session_ids'])
                : (string) ($data['session_id'] ?? ''),
            'user_id' => $userId,
            'type' => !empty($data['is_company']) ? 'company' : 'private',
            'is_company_linked' => !empty($data['is_company']),
            'company_name' => (string) ($data['company_name'] ?? ''),
            'vat_number' => (string) ($data['vat_number'] ?? ''),
            'address' => is_array($data['address'] ?? null) ? $data['address'] : [],
            'registration_fee' => is_array($data['registration_fee'] ?? null) ? $data['registration_fee'] : [],
            'badge_id' => (string) ($data['badge_id'] ?? ''),
        ];
    }
}
