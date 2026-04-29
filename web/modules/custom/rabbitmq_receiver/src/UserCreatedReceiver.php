<?php

declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Receives UserCreated messages from the CRM system.
 */
class UserCreatedReceiver
{
    private const QUEUE = 'frontend.crm.user.created';
    private const DLQ   = 'frontend.crm.user.created.dlq';
    private const DLX   = 'frontend.crm.dlx';

    public function __construct(private readonly RabbitMQClient $client) {}

    /**
     * Parse and validate an incoming UserCreated XML message.
     *
     * Root element: <user_event>
     *
     * @return array{event: string, master_uuid: string, email: string, source_system: string, timestamp: string}
     * @throws \InvalidArgumentException
     */
    public function processMessageFromXml(string $xmlString): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        libxml_clear_errors();

        if ($xml === false) {
            throw new \InvalidArgumentException('Invalid XML received');
        }

        $event = trim((string) $xml->event);
        if ($event !== 'UserCreated') {
            throw new \InvalidArgumentException("Unexpected event type: {$event}");
        }

        $masterUuid = trim((string) $xml->master_uuid);
        if ($masterUuid === '') {
            throw new \InvalidArgumentException('master_uuid is required');
        }

        $email = trim((string) $xml->email);
        if ($email === '') {
            throw new \InvalidArgumentException('email is required');
        }

        return [
            'event'         => $event,
            'master_uuid'   => $masterUuid,
            'email'         => strtolower($email),
            'source_system' => trim((string) $xml->source_system),
            'timestamp'     => trim((string) $xml->timestamp),
        ];
    }

    /**
     * Subscribe to the user_created queue with DLQ support.
     */
    public function listen(): void
    {
        $channel = $this->client->getChannel();

        $args = new AMQPTable([
            'x-dead-letter-exchange'    => self::DLX,
            'x-dead-letter-routing-key' => self::DLQ,
        ]);

        $channel->queue_declare(self::QUEUE, false, true, false, false, false, $args);

        $channel->basic_consume(
            self::QUEUE,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $msg): void {
                try {
                    $this->processMessageFromXml($msg->body);
                    $msg->ack();
                } catch (\Throwable $e) {
                    $msg->nack(false, false);
                }
            }
        );

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }
}
