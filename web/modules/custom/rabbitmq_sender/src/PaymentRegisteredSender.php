<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Sends payment_registered messages to Facturatie via facturatie.incoming (contract §11.5).
 *
 * Triggered after a successful online invoice payment via the webshop.
 */
class PaymentRegisteredSender
{
    use RetryTrait;

    private ?RabbitMQClient $client;

    private const QUEUE_NAME = 'facturatie.incoming';
    private const SOURCE     = 'frontend';
    private const TYPE       = 'payment_registered';
    private const VERSION    = '2.0';

    private const VALID_STATUSES       = ['paid', 'pending', 'cancelled'];
    private const VALID_CONTEXTS       = ['registration', 'consumption', 'online_invoice', 'session_registration'];
    private const VALID_PAYMENT_METHODS = ['company_link', 'on_site', 'online'];

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    public function send(array $data): void
    {
        $this->validate($data);

        $xml = $this->buildXml($data);

        $this->sendWithRetry(function () use ($xml): void {
            $this->resolveClient()->declareQueue(self::QUEUE_NAME);
            $msg = new AMQPMessage($xml, [
                'delivery_mode' => 2,
                'content_type'  => 'application/xml',
            ]);
            $this->resolveClient()->getChannel()->basic_publish($msg, '', self::QUEUE_NAME);
        });
    }

    public function buildXml(array $data): string
    {
        $this->validate($data);

        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $message = $dom->createElement('message');
        $dom->appendChild($message);

        $header = $dom->createElement('header');
        $header->appendChild($dom->createElement('message_id', $messageId));
        $header->appendChild($dom->createElement('timestamp', $timestamp));
        $header->appendChild($dom->createElement('source', self::SOURCE));
        $header->appendChild($dom->createElement('type', self::TYPE));
        $header->appendChild($dom->createElement('version', self::VERSION));

        if (!empty($data['correlation_id'])) {
            $header->appendChild($dom->createElement('correlation_id', htmlspecialchars((string) $data['correlation_id'], ENT_XML1, 'UTF-8')));
        }

        $message->appendChild($header);

        $body = $dom->createElement('body');

        if (!empty($data['identity_uuid'])) {
            $body->appendChild($dom->createElement('identity_uuid', htmlspecialchars((string) $data['identity_uuid'], ENT_XML1, 'UTF-8')));
        }

        $invoice      = $data['invoice'];
        $invoiceEl    = $dom->createElement('invoice');

        if (!empty($invoice['id'])) {
            $invoiceEl->appendChild($dom->createElement('id', htmlspecialchars((string) $invoice['id'], ENT_XML1, 'UTF-8')));
        }

        $amountEl = $dom->createElement('amount_paid', htmlspecialchars((string) $invoice['amount_paid'], ENT_XML1, 'UTF-8'));
        $amountEl->setAttribute('currency', 'eur');
        $invoiceEl->appendChild($amountEl);

        $invoiceEl->appendChild($dom->createElement('status', htmlspecialchars((string) $invoice['status'], ENT_XML1, 'UTF-8')));

        if (!empty($invoice['due_date'])) {
            $invoiceEl->appendChild($dom->createElement('due_date', htmlspecialchars((string) $invoice['due_date'], ENT_XML1, 'UTF-8')));
        }

        $body->appendChild($invoiceEl);

        $body->appendChild($dom->createElement('payment_context', htmlspecialchars((string) $data['payment_context'], ENT_XML1, 'UTF-8')));

        if (!empty($data['transaction'])) {
            $tx   = $data['transaction'];
            $txEl = $dom->createElement('transaction');
            $txEl->appendChild($dom->createElement('id', htmlspecialchars((string) $tx['id'], ENT_XML1, 'UTF-8')));
            $txEl->appendChild($dom->createElement('payment_method', htmlspecialchars((string) $tx['payment_method'], ENT_XML1, 'UTF-8')));
            $body->appendChild($txEl);
        }

        $message->appendChild($body);

        return $dom->saveXML() ?: '';
    }

    private function validate(array $data): void
    {
        if (empty($data['invoice']) || !is_array($data['invoice'])) {
            throw new \InvalidArgumentException('invoice is required');
        }

        $invoice = $data['invoice'];

        if (!isset($invoice['amount_paid']) || $invoice['amount_paid'] === '') {
            throw new \InvalidArgumentException('invoice.amount_paid is required');
        }
        if (!is_numeric($invoice['amount_paid'])) {
            throw new \InvalidArgumentException('invoice.amount_paid must be numeric');
        }
        if (empty($invoice['status'])) {
            throw new \InvalidArgumentException('invoice.status is required');
        }
        if (!in_array($invoice['status'], self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException('invoice.status must be paid, pending, or cancelled');
        }
        if (empty($data['payment_context'])) {
            throw new \InvalidArgumentException('payment_context is required');
        }
        if (!in_array($data['payment_context'], self::VALID_CONTEXTS, true)) {
            throw new \InvalidArgumentException('payment_context must be registration, consumption, online_invoice, or session_registration');
        }
        if (!empty($data['transaction'])) {
            $tx = $data['transaction'];
            if (empty($tx['id'])) {
                throw new \InvalidArgumentException('transaction.id is required when transaction is provided');
            }
            if (empty($tx['payment_method'])) {
                throw new \InvalidArgumentException('transaction.payment_method is required when transaction is provided');
            }
            if (!in_array($tx['payment_method'], self::VALID_PAYMENT_METHODS, true)) {
                throw new \InvalidArgumentException('transaction.payment_method must be company_link, on_site, or online');
            }
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

    private function generateUuidV4(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
