<?php

declare(strict_types=1);

namespace Drupal\registration_form\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\rabbitmq_sender\NewRegistrationSender;
use Drupal\rabbitmq_sender\RabbitMQClient;

class RegistrationService
{
    public function __construct(
        private readonly LoggerChannelFactoryInterface $loggerFactory,
    ) {}

    /**
     * Registers a user and sends the event to CRM, Planning and Mailing via RabbitMQ.
     *
     * @throws \InvalidArgumentException When validation fails.
     * @throws \RuntimeException When RabbitMQ is unreachable after retries.
     */
    public function register(array $data): void
    {
        $logger = $this->loggerFactory->get('registration_form');

        $logger->info('Registration attempt for @email on session @session', [
            '@email'   => $data['email'] ?? 'unknown',
            '@session' => $data['session_id'] ?? 'unknown',
        ]);

        $client = new RabbitMQClient(
            (string) (getenv('RABBITMQ_HOST') ?: 'localhost'),
            (int)    (getenv('RABBITMQ_PORT') ?: 5672),
            (string) (getenv('RABBITMQ_USER') ?: 'guest'),
            (string) (getenv('RABBITMQ_PASS') ?: 'guest'),
            (string) (getenv('RABBITMQ_VHOST') ?: '/'),
        );

        $sender = new NewRegistrationSender($client);
        $sender->send($data);

        $logger->info('Registration sent to RabbitMQ for @email.', [
            '@email' => $data['email'],
        ]);
    }
}
