<?php

declare(strict_types=1);

namespace Drupal\registration_form;

interface RegistrationRepositoryInterface
{
    /**
     * Checks whether a registration already exists for the given email and session.
     */
    public function existsByEmailAndSession(string $email, string $sessionId): bool;
}
