<?php

declare(strict_types=1);

namespace Drupal\registration_form;

/**
 * Stub — no validation logic yet (TDD RED phase).
 * Implement each rule in the GREEN phase to make the tests pass.
 */
class RegistrationValidator
{
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
        // TODO (GREEN phase): implement all validation rules
        return $data;
    }
}
