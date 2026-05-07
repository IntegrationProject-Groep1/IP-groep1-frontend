<?php

declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use Drupal\rabbitmq_sender\UserCheckinSender;
use Drupal\rabbitmq_sender\XmlValidationTrait;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Receives badge_scanned messages from the CRM system and forwards user_checkin to CRM.
 */
class BadgeScannedReceiver
{
    use XmlValidationTrait;

    private const QUEUE = 'frontend.crm.badge.scanned';
    private const DLQ   = 'frontend.crm.badge.scanned.dlq';
    private const DLX   = 'frontend.crm.dlx';
    private const XSD_PATH = __DIR__ . '/../../../../../xsd/badge_scanned.xsd';

    private ?RabbitMQClient $client;
    private ?UserCheckinSender $userCheckinSender;

    public function __construct(
        ?RabbitMQClient $client = null,
        ?UserCheckinSender $userCheckinSender = null,
    ) {
        $this->client = $client;
        $this->userCheckinSender = $userCheckinSender;
    }

    public function setUserCheckinSender(UserCheckinSender $sender): void
    {
        $this->userCheckinSender = $sender;
    }

    /**
     * Parse and validate an incoming badge_scanned XML message, then forward as user_checkin.
     *
     * @return true
     * @throws \InvalidArgumentException
     */
    public function processMessageFromXml(string $xmlString): mixed
    {
        $this->validateXml($xmlString, self::XSD_PATH);
        
        $xmlString = preg_replace('/ xmlns="[^"]*"/', '', $xmlString) ?? $xmlString;
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        libxml_clear_errors();

        if ($xml === false) {
            throw new \InvalidArgumentException('Invalid XML received');
        }

        $body = $xml->body;

        $badgeId = trim((string) $body->badge_id);
        if ($badgeId === '') {
            throw new \InvalidArgumentException('badge_id is required');
        }

        $location = trim((string) $body->location);
        if ($location === '') {
            throw new \InvalidArgumentException('location is required');
        }

        return true;
    }

    /**
     * Poll the badge_scanned queue once (non-blocking) and process any waiting message.
     *
     * Returns true when a message was processed, false when the queue was empty.
     */
    public function pollOnce(): bool
    {
        $channel = $this->resolveClient()->getChannel();

        $args = new AMQPTable([
            'x-dead-letter-exchange'    => self::DLX,
            'x-dead-letter-routing-key' => self::DLQ,
        ]);

        $channel->queue_declare(self::QUEUE, false, true, false, false, false, $args);

        $msg = $channel->basic_get(self::QUEUE);

        if ($msg === null) {
            return false;
        }

        try {
            $this->processMessageFromXml($msg->body);
            $msg->ack();
        } catch (\Throwable $e) {
            $msg->nack(false, false);
        }

        return true;
    }

    /**
     * Subscribe to the badge_scanned queue with DLQ support (blocking loop for worker scripts).
     */
    public function listen(): void
    {
        $channel = $this->resolveClient()->getChannel();

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

    private function resolveClient(): RabbitMQClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->client = new RabbitMQClient(
            getenv('RABBITMQ_HOST') ?: 'rabbitmq_broker',
            (int) (getenv('RABBITMQ_PORT') ?: '5672'),
            getenv('RABBITMQ_USER') ?: 'guest',
            getenv('RABBITMQ_PASS') ?: 'guest',
            getenv('RABBITMQ_VHOST') ?: '/'
        );

        return $this->client;
    }
}
