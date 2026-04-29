<?php

declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Receives vat_validation_error messages from the CRM system.
 */
class VatValidationErrorReceiver
{
    private const QUEUE = 'frontend.crm.vat.validation.error';
    private const DLQ   = 'frontend.crm.vat.validation.error.dlq';
    private const DLX   = 'frontend.crm.dlx';

    public function __construct(private readonly RabbitMQClient $client) {}

    /**
     * Parse and validate an incoming vat_validation_error XML message.
     *
     * @return true
     * @throws \InvalidArgumentException
     */
    public function processMessageFromXml(string $xmlString): mixed
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        libxml_clear_errors();

        if ($xml === false) {
            throw new \InvalidArgumentException('Invalid XML received');
        }

        $body = $xml->body;

        $userId = trim((string) $body->user_id);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id is required');
        }

        $vatNumber = trim((string) $body->vat_number);
        if ($vatNumber === '') {
            throw new \InvalidArgumentException('vat_number is required');
        }

        return true;
    }

    /**
     * Subscribe to the vat_validation_error queue with DLQ support.
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
