<?php

declare(strict_types=1);

namespace Drupal\session_management\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\rabbitmq_sender\SessionCreateRequestSender;

/**
 * Orchestrates session creation and publishes a session_create_request to Planning via RabbitMQ.
 */
class SessionService
{
    public function __construct(
        private readonly LoggerChannelFactoryInterface $loggerFactory,
        private readonly UuidInterface $uuid,
        private readonly SessionCreateRequestSender $sessionCreateRequestSender,
    ) {}

    /**
     * Creates a session and sends a session_create_request event to the Planning service.
     *
     * @throws \InvalidArgumentException When required fields are missing.
     */
    public function createSession(array $data): string
    {
        $logger = $this->loggerFactory->get('session_management');

        // Generate a local correlation ID; the canonical session ID is assigned by Planning.
        $correlationId = $this->uuid->generate();

        $payload = [
            'session_id'     => $correlationId,
            'title'          => $data['title'] ?? '',
            'start_datetime' => $data['start_datetime'] ?? '',
            'end_datetime'   => $data['end_datetime'] ?? '',
        ];

        if (!empty($data['location'])) {
            $payload['location'] = $data['location'];
        }

        if (!empty($data['session_type'])) {
            $payload['session_type'] = $data['session_type'];
        }

        if (!empty($data['max_attendees'])) {
            $payload['max_attendees'] = $data['max_attendees'];
        }

        $logger->info('Sending session_create_request for "@title" (correlation: @id)', [
            '@title' => $payload['title'],
            '@id'    => $correlationId,
        ]);

        try {
            $this->sessionCreateRequestSender->send($payload);
            $logger->info('session_create_request sent to Planning via planning.exchange (correlation: @id).', ['@id' => $correlationId]);
        } catch (\Throwable $e) {
            $logger->error('Failed to send session_create_request: @message', [
                '@message' => $e->getMessage(),
            ]);
            // Re-throw so the form can show an appropriate error to the user.
            throw $e;
        }

        return $correlationId;
    }
}
