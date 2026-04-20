<?php

declare(strict_types=1);

namespace Drupal\session_management\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\rabbitmq_sender\CalendarInviteSender;

/**
 * Orchestrates session creation and publishes a calendar invite to Planning via RabbitMQ.
 */
class SessionService
{
    public function __construct(
        private readonly LoggerChannelFactoryInterface $loggerFactory,
        private readonly UuidInterface $uuid,
        private readonly CalendarInviteSender $calendarInviteSender,
    ) {}

    /**
     * Creates a session and sends a calendar.invite event to the Planning service.
     *
     * @throws \InvalidArgumentException When required fields are missing.
     */
    public function createSession(array $data): string
    {
        $logger = $this->loggerFactory->get('session_management');

        $sessionId = $this->uuid->generate();

        $payload = [
            'session_id'     => $sessionId,
            'title'          => $data['title'] ?? '',
            'start_datetime' => $data['start_datetime'] ?? '',
            'end_datetime'   => $data['end_datetime'] ?? '',
        ];

        if (!empty($data['location'])) {
            $payload['location'] = $data['location'];
        }

        $logger->info('Creating session @id: @title', [
            '@id'    => $sessionId,
            '@title' => $payload['title'],
        ]);

        try {
            $this->calendarInviteSender->send($payload);
            $logger->info('Session @id sent to Planning via calendar.exchange.', ['@id' => $sessionId]);
        } catch (\Throwable $e) {
            $logger->error('Failed to send session @id to Planning: @message', [
                '@id'      => $sessionId,
                '@message' => $e->getMessage(),
            ]);
            // Re-throw so the form can show an appropriate error to the user.
            throw $e;
        }

        return $sessionId;
    }
}
