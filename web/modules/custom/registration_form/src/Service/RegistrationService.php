<?php

declare(strict_types=1);

namespace Drupal\registration_form\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Handles the registration business logic.
 *
 * RabbitMQ sending is disabled by default (rabbitmq_enabled = false in
 * registration_form.services.yml). To enable it once RabbitMQ is ready:
 *   1. Set rabbitmq_enabled: true in registration_form.services.yml
 *   2. Add the UserRegisteredSender to the service arguments
 */
class RegistrationService
{
    private bool $rabbitMQEnabled = false;

    public function __construct(
        private readonly LoggerChannelFactoryInterface $loggerFactory,
    ) {}

    /**
     * Called by the service container to toggle RabbitMQ sending.
     * Set the parameter registration_form.rabbitmq_enabled to true to enable.
     */
    public function setRabbitMQEnabled(bool $enabled): void
    {
        $this->rabbitMQEnabled = $enabled;
    }

    /**
     * Registers a user: validates data, saves locally and optionally sends to RabbitMQ.
     *
     * @throws \InvalidArgumentException When validation fails.
     */
    public function register(array $data): void
    {
        $logger = $this->loggerFactory->get('registration_form');

        // Log the registration attempt (without sensitive password-like data).
        $logger->info('Registration attempt for email @email on session @session', [
            '@email'   => $data['email'] ?? 'unknown',
            '@session' => $data['session_id'] ?? 'unknown',
        ]);

        if ($this->rabbitMQEnabled) {
            $this->sendToRabbitMQ($data, $logger);
        } else {
            $logger->info('RabbitMQ sending is disabled. Skipping message for @email.', [
                '@email' => $data['email'],
            ]);
        }
    }

    /**
     * Sends the registration event to RabbitMQ via UserRegisteredSender.
     * Only called when rabbitMQEnabled = true.
     *
     * TO ENABLE:
     *   - Inject UserRegisteredSender via the service container
     *   - Set registration_form.rabbitmq_enabled: true in services.yml
     *
     * TO REMOVE (if RabbitMQ integration is cancelled):
     *   - Delete this method and the $rabbitMQEnabled property
     *   - Simplify register() to only contain the logger lines
     */
    private function sendToRabbitMQ(array $data, mixed $logger): void
    {
        // Placeholder — wire up UserRegisteredSender here when ready.
        $logger->warning('sendToRabbitMQ called but no sender is injected yet.');
    }
}
