<?php

declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use Drupal\rabbitmq_sender\XmlValidationTrait;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Receives payment_registered messages from the CRM system.
 */
class PaymentRegisteredReceiver
{
    use XmlValidationTrait;

    private const QUEUE = 'frontend.crm.payment.registered';
    private const DLQ   = 'frontend.crm.payment.registered.dlq';
    private const DLX   = 'frontend.crm.dlx';
    private const XSD_PATH = __DIR__ . '/../../../../../xsd/payment_registered.xsd';

    public function __construct(private readonly RabbitMQClient $client) {}

    /**
     * Parse and validate an incoming payment_registered XML message.
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

        $invoice = $body->invoice;
        $status = trim((string) $invoice->status);
        if ($status === '') {
            throw new \InvalidArgumentException('status is required');
        }

        return true;
    }

    /**
     * Subscribe to the payment_registered queue with DLQ support.
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
