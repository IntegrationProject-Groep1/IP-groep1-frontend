<?php

declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use Drupal\rabbitmq_sender\XmlValidationTrait;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Receives session_deleted messages from the Planning system.
 */
class SessionDeletedReceiver
{
    use XmlValidationTrait;

    private const EXCHANGE      = 'planning.exchange';
    private const EXCHANGE_TYPE = 'topic';
    private const ROUTING_KEY   = 'planning.to.frontend.session.deleted';
    private const QUEUE         = 'frontend.planning.session.deleted';
    private const DLQ           = 'frontend.planning.session.deleted.dlq';
    private const DLX           = 'frontend.planning.dlx';
    private const XSD_PATH      = __DIR__ . '/../../../../../xsd/session_deleted.xsd';

    public function __construct(private readonly RabbitMQClient $client) {}

    /**
     * Parse and validate an incoming session_deleted XML message.
     *
     * @return array{session_id: string, reason: string, deleted_by: string}
     * @throws \InvalidArgumentException
     */
    public function processMessageFromXml(string $xmlString): array
    {
        $this->validateXml($xmlString, self::XSD_PATH);

        $xml = $this->parseXml($xmlString);
        $body = $xml->body;

        $sessionId = trim((string) $body->session_id);
        if ($sessionId === '') {
            throw new \InvalidArgumentException('session_id is required');
        }

        return [
            'session_id' => $sessionId,
            'reason'     => trim((string) $body->reason),
            'deleted_by' => trim((string) $body->deleted_by),
        ];
    }

    /**
     * Subscribe to the session_deleted queue with DLQ support.
     */
    public function listen(): void
    {
        $channel = $this->client->getChannel();

        $args = new AMQPTable([
            'x-dead-letter-exchange'    => self::DLX,
            'x-dead-letter-routing-key' => self::DLQ,
        ]);

        $channel->exchange_declare(self::EXCHANGE, self::EXCHANGE_TYPE, false, true, false);
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
