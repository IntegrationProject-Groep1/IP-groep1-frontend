<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Consumes VAT validation error events from RabbitMQ.
 */
class VatValidationErrorReceiver
{
    private RabbitMQClient $client;

    public function __construct(RabbitMQClient $client)
    {
        $this->client = $client;
    }

    public function listen(): void
    {
        $channel = $this->client->getChannel();
        $channel->queue_declare('frontend.incoming', false, true, false, false);

        $channel->basic_consume(
            'frontend.incoming',
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $msg) {
                $this->processMessage($msg);
            }
        );

        echo "Listening for VAT validation errors...\n";

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    public function processMessageFromXml(string $xmlString): bool
    {
        $xml = @simplexml_load_string($xmlString);
        if ($xml === false) {
            throw new \InvalidArgumentException('Invalid XML received');
        }

        $identityUuid = (string) $xml->body->identity_uuid;
        $vatNumber = (string) $xml->body->vat_number;

        if (empty($identityUuid)) {
            throw new \InvalidArgumentException('identity_uuid is required');
        }
        if (empty($vatNumber)) {
            throw new \InvalidArgumentException('vat_number is required');
        }

        return true;
    }

    private function processMessage(AMQPMessage $msg): void
    {
        try {
            $xml = simplexml_load_string($msg->body);

            if ($xml === false) {
                throw new \InvalidArgumentException('Invalid XML received');
            }

            $msgType = (string) $xml->header->type;
            if ($msgType !== 'vat_validation_error') {
                $msg->nack(false, true);
                return;
            }

            $identityUuid = (string) $xml->body->identity_uuid;
            $vatNumber    = (string) $xml->body->vat_number;
            $errorMessage = (string) $xml->body->error_message;

            if (empty($identityUuid)) {
                throw new \InvalidArgumentException('identity_uuid is required');
            }
            if (empty($vatNumber)) {
                throw new \InvalidArgumentException('vat_number is required');
            }

            // Show the VAT validation error to the user in Drupal.
            echo "VAT validation error: {$identityUuid} - {$vatNumber} - {$errorMessage}\n";

            $msg->ack();

        } catch (\Exception $e) {
            error_log('VatValidationErrorReceiver error: ' . $e->getMessage());
            $msg->nack();
        }
    }
}