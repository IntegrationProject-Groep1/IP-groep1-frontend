<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Consumes calendar.invite.confirmed events from Planning.
 *
 * Planning sends this in response to a calendar.invite from the frontend,
 * confirming or rejecting the calendar entry.
 *
 * Exchange:    planning.exchange                          (topic, durable)
 * Routing key: planning.calendar.invite.confirmed
 * Queue:       frontend.planning.calendar.invite.confirmed
 *
 * Expected XML body fields: session_id, original_message_id, status (confirmed|failed)
 */
class CalendarInviteConfirmedReceiver
{
    private const EXCHANGE      = 'planning.exchange';
    private const EXCHANGE_TYPE = 'topic';
    private const ROUTING_KEY   = 'planning.to.frontend.calendar.invite.confirmed';
    private const QUEUE         = 'frontend.planning.calendar.invite.confirmed';

    private RabbitMQClient $client;

    public function __construct(RabbitMQClient $client)
    {
        $this->client = $client;
    }

    public function listen(): void
    {
        $channel = $this->client->getChannel();

        $channel->exchange_declare(self::EXCHANGE, self::EXCHANGE_TYPE, false, true, false);

        $channel->exchange_declare('dlx_exchange', 'direct', false, true, false);
        $channel->queue_declare(self::QUEUE . '.dlq', false, true, false, false);
        $channel->queue_bind(self::QUEUE . '.dlq', 'dlx_exchange', self::QUEUE . '.dlq');

        $args = new AMQPTable([
            'x-dead-letter-exchange'    => 'dlx_exchange',
            'x-dead-letter-routing-key' => self::QUEUE . '.dlq',
        ]);

        $channel->queue_declare(self::QUEUE, false, true, false, false, false, $args);
        $channel->queue_bind(self::QUEUE, self::EXCHANGE, self::ROUTING_KEY);

        $channel->basic_consume(
            self::QUEUE,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $msg): void {
                $this->processMessage($msg);
            }
        );

        echo 'Listening for planning.calendar.invite.confirmed on ' . self::EXCHANGE . ' → ' . self::QUEUE . "\n";

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    /**
     * Parses and validates a calendar.invite.confirmed XML string from Planning.
     * Returns an associative array with session_id, original_message_id and status.
     *
     * @throws \InvalidArgumentException on invalid or incomplete XML.
     */
    public function processMessageFromXml(string $xmlString): array
    {
        $xml = @simplexml_load_string($xmlString);
        if ($xml === false) {
            throw new \InvalidArgumentException('Invalid XML received');
        }

        $namespaces = $xml->getNamespaces(true);
        $ns = reset($namespaces) ?: null;

        if ($ns !== null) {
            $xml->registerXPathNamespace('ns', $ns);
            $bodyNodes = $xml->xpath('ns:body') ?: $xml->xpath('body');
        } else {
            $bodyNodes = $xml->xpath('body');
        }

        $body = ($bodyNodes && count($bodyNodes) > 0) ? $bodyNodes[0] : $xml->body ?? null;

        if ($body === null) {
            throw new \InvalidArgumentException('<body> element is missing');
        }

        $sessionId = trim((string) ($body->session_id ?? ''));
        if (empty($sessionId)) {
            throw new \InvalidArgumentException('session_id is required');
        }

        $originalMessageId = trim((string) ($body->original_message_id ?? ''));
        if (empty($originalMessageId)) {
            throw new \InvalidArgumentException('original_message_id is required');
        }

        $status = trim((string) ($body->status ?? ''));
        if (empty($status)) {
            throw new \InvalidArgumentException('status is required');
        }

        if (!in_array($status, ['confirmed', 'failed'], true)) {
            throw new \InvalidArgumentException('status must be "confirmed" or "failed"');
        }

        return [
            'session_id'          => $sessionId,
            'original_message_id' => $originalMessageId,
            'status'              => $status,
            'ics_url'             => trim((string) ($body->ics_url ?? '')),
        ];
    }

    private function processMessage(AMQPMessage $msg): void
    {
        try {
            $data = $this->processMessageFromXml($msg->body);

            echo sprintf(
                "calendar.invite.confirmed received: session=%s status=%s\n",
                $data['session_id'],
                $data['status']
            );

            $msg->ack();
        } catch (\Exception $e) {
            error_log('CalendarInviteConfirmedReceiver error: ' . $e->getMessage());

            $msg->nack(false, false);
        }
    }
}
