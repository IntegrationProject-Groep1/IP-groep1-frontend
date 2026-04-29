<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable; // ✅ toegevoegd

/**
 * Consumes session_updated events from Planning via the planning.exchange topic exchange.
 *
 * Planning's producer publishes on:
 *   Exchange:    planning.exchange       (topic, durable)
 *   Routing key: planning.session.updated
 *
 * We bind our own durable queue to that exchange so we survive broker restarts.
 *   Queue:       frontend.planning.session.updated
 *
 * Expected XML body fields: session_id, title, start_datetime, end_datetime,
 *                           location, session_type, status, max_attendees, current_attendees
 */
class SessionUpdateReceiver
{
    private const EXCHANGE      = 'planning.exchange';
    private const EXCHANGE_TYPE = 'topic';
    private const ROUTING_KEY   = 'planning.to.frontend.session.updated';
    private const QUEUE         = 'frontend.planning.session.updated';

    private RabbitMQClient $client;

    public function __construct(RabbitMQClient $client)
    {
        $this->client = $client;
    }

    public function listen(): void
    {
        $channel = $this->client->getChannel();

        $channel->exchange_declare(self::EXCHANGE, self::EXCHANGE_TYPE, false, true, false);

        // ✅ DLX + DLQ toegevoegd
        $channel->exchange_declare('dlx_exchange', 'direct', false, true, false);
        $channel->queue_declare(self::QUEUE . '.dlq', false, true, false, false);
        $channel->queue_bind(self::QUEUE . '.dlq', 'dlx_exchange', self::QUEUE . '.dlq');

        // ✅ Main queue aangepast
        $args = new AMQPTable([
            'x-dead-letter-exchange' => 'dlx_exchange',
            'x-dead-letter-routing-key' => self::QUEUE . '.dlq'
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

        echo 'Listening for planning.session.updated on ' . self::EXCHANGE . ' → ' . self::QUEUE . "\n";

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    /**
     * Parses and validates a session_updated XML string from Planning.
     * Returns an associative array of the updated session data.
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

        $title = trim((string) ($body->title ?? ''));
        if (empty($title)) {
            throw new \InvalidArgumentException('title is required');
        }

        $startDatetime = trim((string) ($body->start_datetime ?? ''));
        if (empty($startDatetime)) {
            throw new \InvalidArgumentException('start_datetime is required');
        }

        $endDatetime = trim((string) ($body->end_datetime ?? ''));
        if (empty($endDatetime)) {
            throw new \InvalidArgumentException('end_datetime is required');
        }

        return [
            'session_id'        => $sessionId,
            'title'             => $title,
            'start_datetime'    => $startDatetime,
            'end_datetime'      => $endDatetime,
            'location'          => trim((string) ($body->location ?? '')),
            'session_type'      => trim((string) ($body->session_type ?? '')),
            'status'            => trim((string) ($body->status ?? '')),
            'max_attendees'     => (int) ($body->max_attendees ?? 0),
            'current_attendees' => (int) ($body->current_attendees ?? 0),
        ];
    }

    private function processMessage(AMQPMessage $msg): void
    {
        try {
            $data = $this->processMessageFromXml($msg->body);

            echo sprintf(
                "session.updated received: %s | %s | %s → %s | attendees: %d/%d\n",
                $data['session_id'],
                $data['title'],
                $data['start_datetime'],
                $data['end_datetime'],
                $data['current_attendees'],
                $data['max_attendees']
            );

            $msg->ack();
        } catch (\Exception $e) {
            error_log('SessionUpdateReceiver error: ' . $e->getMessage());

            $msg->nack(false, false); // 🔥 aangepast → DLQ
        }
    }
}