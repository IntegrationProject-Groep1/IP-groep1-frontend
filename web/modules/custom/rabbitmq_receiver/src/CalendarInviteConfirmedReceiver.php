<?php

declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Receives calendar_invite_confirmed messages from the Planning system.
 */
class CalendarInviteConfirmedReceiver
{
    private const EXCHANGE      = 'planning.exchange';
    private const EXCHANGE_TYPE = 'topic';
    private const ROUTING_KEY   = 'planning.to.frontend.calendar.invite.confirmed';
    private const QUEUE         = 'frontend.planning.calendar.invite.confirmed';
    private const DLQ       = 'frontend.planning.calendar.invite.confirmed.dlq';
    private const DLX       = 'frontend.planning.dlx';
    private const NAMESPACE = 'urn:integration:planning:v1';

    public function __construct(private readonly RabbitMQClient $client) {}

    /**
     * Parse and validate an incoming calendar_invite_confirmed XML message.
     *
     * @return array{session_id: string, original_message_id: string, status: string}
     * @throws \InvalidArgumentException
     */
    public function processMessageFromXml(string $xmlString): array
    {
        $xml = $this->parseXml($xmlString);

        if (count($xml->body) === 0) {
            throw new \InvalidArgumentException('<body> element is missing');
        }

        $body = $xml->body;

        $sessionId = trim((string) $body->session_id);
        if ($sessionId === '') {
            throw new \InvalidArgumentException('session_id is required');
        }

        $originalMessageId = trim((string) $body->original_message_id);
        if ($originalMessageId === '') {
            throw new \InvalidArgumentException('original_message_id is required');
        }

        $status = trim((string) $body->status);
        if ($status === '') {
            throw new \InvalidArgumentException('status is required');
        }

        if ($status !== 'confirmed' && $status !== 'failed') {
            throw new \InvalidArgumentException('status must be "confirmed" or "failed"');
        }

        return [
            'session_id'          => $sessionId,
            'original_message_id' => $originalMessageId,
            'status'              => $status,
        ];
    }

    /**
     * Subscribe to the calendar_invite_confirmed queue with DLQ support.
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

    /**
     * Parse an XML string, stripping the default namespace for uniform access.
     *
     * @throws \InvalidArgumentException on invalid XML
     */
    private function parseXml(string $xmlString): \SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $cleaned = preg_replace('/ xmlns="[^"]*"/', '', $xmlString);
        $xml = simplexml_load_string($cleaned);
        libxml_clear_errors();

        if ($xml === false) {
            throw new \InvalidArgumentException('Invalid XML received');
        }

        return $xml;
    }
}
