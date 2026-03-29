<?php

declare(strict_types=1);

namespace Drupal\registration_form;

class RegistrationValidator
{
    // Regex for UUID v1–v5 format.
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly RegistrationRepositoryInterface $registrationRepository,
        private readonly SessionRepositoryInterface $sessionRepository,
    ) {}

    /**
     * Validates the registration data and returns it enriched with payment_status.
     *
     * @throws \InvalidArgumentException When any validation rule is violated.
     */
    public function validate(array $data): array
    {
        $this->validateEmail($data);
        $this->validateSessionId($data);
        $this->validateCompanyFields($data);
        $this->validateNoDuplicateRegistration($data);
        $this->validateSessionNotFull($data);

        $data['payment_status'] = 'pending';

        return $data;
    }

    private function validateEmail(array $data): void
    {
        if (empty($data['email'])) {
            throw new \InvalidArgumentException('email is required');
        }

        if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('email must be a valid email address');
        }
    }

    private function validateSessionId(array $data): void
    {
        if (empty($data['session_id'])) {
            throw new \InvalidArgumentException('session_id is required');
        }

        if (!preg_match(self::UUID_PATTERN, $data['session_id'])) {
            throw new \InvalidArgumentException('session_id must be a valid UUID');
        }
    }

    private function validateCompanyFields(array $data): void
    {
        if (!empty($data['is_company']) && empty($data['vat_number'])) {
            throw new \InvalidArgumentException('vat_number is required for companies');
        }
    }

    private function validateNoDuplicateRegistration(array $data): void
    {
        if ($this->registrationRepository->existsByEmailAndSession($data['email'], $data['session_id'])) {
            throw new \InvalidArgumentException('This email address is already registered for this session');
        }
    }

    private function validateSessionNotFull(array $data): void
    {
        if ($this->sessionRepository->isSessionFull($data['session_id'])) {
            throw new \InvalidArgumentException('This session is full');
        }
    }
}
