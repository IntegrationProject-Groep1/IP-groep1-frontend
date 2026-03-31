<?php

declare(strict_types=1);

namespace Drupal\registration_form;

/**
 * Repository contract for session capacity checks.
 */
interface SessionRepositoryInterface
{
    /**
     * Returns true if the session has reached its maximum capacity.
     */
    public function isSessionFull(string $sessionId): bool;
}
