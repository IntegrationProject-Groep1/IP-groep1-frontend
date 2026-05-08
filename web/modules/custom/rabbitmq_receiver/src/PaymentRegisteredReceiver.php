<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Consumes payment registration events from RabbitMQ.
 */
class PaymentRegisteredReceiver
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

        echo "Listening for payment registrations...\n";

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

        $identityUuid    = (string) $xml->body->identity_uuid;
        $invoiceId       = (string) $xml->body->invoice->id;
        $paymentContext  = (string) $xml->body->payment_context;

        if (empty($identityUuid)) {
            throw new \InvalidArgumentException('identity_uuid is required');
        }
        if (empty($invoiceId)) {
            throw new \InvalidArgumentException('invoice.id is required');
        }
        if (empty($paymentContext)) {
            throw new \InvalidArgumentException('payment_context is required');
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
            if ($msgType !== 'payment_registered') {
                $msg->nack(false, true);
                return;
            }

            $identityUuid   = (string) $xml->body->identity_uuid;
            $invoiceId      = (string) $xml->body->invoice->id;
            $amountPaid     = (string) $xml->body->invoice->amount_paid;
            $paymentContext = (string) $xml->body->payment_context;

            if (empty($identityUuid)) {
                throw new \InvalidArgumentException('identity_uuid is required');
            }
            if (empty($invoiceId)) {
                throw new \InvalidArgumentException('invoice.id is required');
            }

            // Update payment status in Drupal storage.
            echo "Payment registered: {$identityUuid} - {$invoiceId} - {$amountPaid} ({$paymentContext})\n";

            $msg->ack();

        } catch (\Exception $e) {
            error_log('PaymentRegisteredReceiver error: ' . $e->getMessage());
            $msg->nack();
        }
    }
}