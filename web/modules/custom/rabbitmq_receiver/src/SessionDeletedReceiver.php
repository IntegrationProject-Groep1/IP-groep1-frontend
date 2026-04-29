<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable; // ✅ toegevoegd

/**
 * Consumes session_deleted events from Planning via the planning.exchange topic exchange.
 *
 * Planning's producer publishes on:
 *   Exchange:    planning.exchange       (topic, durable)
 *   Routing key: planning.session.deleted
 *
 * We bind our own durable queue to that exchange so we survive broker restarts.
 *   Queue:       frontend.planning.session.deleted
 *
 * Expected XML body fields: session_id (required), reason (optional), deleted_by (optional)
 */
class SessionDeletedReceiver
{
    private const EXCHANGE      = 'planning.exchange';
    private const EXCHANGE_TYPE = 'topic';
    private const ROUTING_KEY   = 'planning.to.frontend.session.deleted';
    private const QUEUE         = 'frontend.planning.session.deleted';

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

        echo 'Listening for planning.session.deleted on ' . self::EXCHANGE . ' → ' . self::QUEUE . "\n";

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    /**
     * Parses and validates a session_deleted XML string from Planning.
     * Returns an associative array with session_id, reason and deleted_by.
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

        return [
            'session_id' => $sessionId,
            'reason'     => trim((string) ($body->reason ?? '')),
            'deleted_by' => trim((string) ($body->deleted_by ?? '')),
        ];
    }

    private function processMessage(AMQPMessage $msg): void
    {
        try {
            $data = $this->processMessageFromXml($msg->body);

            echo sprintf(
                "session.deleted received: %s | reason: %s | by: %s\n",
                $data['session_id'],
                $data['reason'] ?: '(none)',
                $data['deleted_by'] ?: '(none)'
            );

            $msg->ack();
        } catch (\Exception $e) {
            error_log('SessionDeletedReceiver error: ' . $e->getMessage());

            $msg->nack(false, false); // 🔥 aangepast → DLQ
        }
    }
}