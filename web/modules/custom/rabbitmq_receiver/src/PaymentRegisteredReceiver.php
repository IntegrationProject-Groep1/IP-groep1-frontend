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
    use ReceiverLogTrait;

    private const QUEUE = 'frontend.incoming';
    private const DLQ   = 'frontend.incoming.dlq';
    private const DLX   = 'frontend.crm.dlx';
    private const XSD_PATH = __DIR__ . '/../../../../../xsd/payment_registered_receiver.xsd';

    public function __construct(private readonly RabbitMQClient $client) {}

    /**
     * @return true
     * @throws \InvalidArgumentException
     */
    public function processMessageFromXml(string $xmlString): mixed
    {
        $this->validateXml($xmlString, self::XSD_PATH);
        $this->logReceiverSuccess(
            $this->extractXmlValue($xmlString, 'type') ?: 'payment_registered',
            $this->extractXmlValue($xmlString, 'source') ?: 'CRM'
        );

        $xmlString = preg_replace('/ xmlns="[^"]*"/', '', $xmlString) ?? $xmlString;
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        libxml_clear_errors();

        if ($xml === false) {
            throw new \InvalidArgumentException('Invalid XML received');
        }

        $body = $xml->body;

        $identityUuid = trim((string) $body->identity_uuid);
        if ($identityUuid === '') {
            throw new \InvalidArgumentException('identity_uuid is required');
        }

        $invoice = $body->invoice;
        $invoiceId  = trim((string) $invoice->id);
        $amountPaid = trim((string) $invoice->amount_paid);
        $currency   = trim((string) ($invoice->amount_paid->attributes()['currency'] ?? 'eur'));
        $status     = trim((string) $invoice->status);
        if ($status === '') {
            throw new \InvalidArgumentException('status is required');
        }

        $paymentContext = trim((string) $body->payment_context);

        $transactionId     = isset($body->transaction) ? trim((string) $body->transaction->id) : '';
        $paymentMethod     = isset($body->transaction) ? trim((string) $body->transaction->payment_method) : '';

        \Drupal::logger('rabbitmq_receiver')->info(
            'payment_registered: identity_uuid=@uuid, invoice=@inv, amount=@amount @currency, context=@ctx, method=@method.',
            [
                '@uuid'     => $identityUuid,
                '@inv'      => $invoiceId,
                '@amount'   => $amountPaid,
                '@currency' => strtoupper($currency),
                '@ctx'      => $paymentContext,
                '@method'   => $paymentMethod ?: 'n/a',
            ]
        );

        $uid = $this->findUidByMasterUuid($identityUuid);
        if ($uid !== null) {
            $userData = \Drupal::service('user.data');
            $userData->set('registration_form', $uid, 'last_payment', [
                'invoice_id'      => $invoiceId,
                'amount_paid'     => $amountPaid,
                'currency'        => $currency,
                'status'          => $status,
                'payment_context' => $paymentContext,
                'transaction_id'  => $transactionId,
                'payment_method'  => $paymentMethod,
                'received_at'     => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            ]);
        }

        return true;
    }

    private function findUidByMasterUuid(string $masterUuid): ?int
    {
        $rows = \Drupal::database()
            ->select('users_data', 'ud')
            ->fields('ud', ['uid', 'value'])
            ->condition('module', 'registration_form')
            ->condition('name', 'master_uuid')
            ->execute()
            ->fetchAll();

        foreach ($rows as $row) {
            if (unserialize($row->value) === $masterUuid) {
                return (int) $row->uid;
            }
        }

        return null;
    }

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
                    $this->logReceiverError($e, self::QUEUE, $msg->body);
                    $msg->nack(false, false);
                }
            }
        );

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }
}
