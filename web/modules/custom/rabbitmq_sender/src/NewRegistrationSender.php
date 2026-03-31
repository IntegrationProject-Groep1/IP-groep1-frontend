<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes CRM-compatible new registration messages to RabbitMQ.
 */
class NewRegistrationSender
{
    use RetryTrait;

    private ?RabbitMQClient $client;
    private const QUEUE_NAME = 'crm.incoming';
    private const SOURCE = 'registratie';
    private const MESSAGE_TYPE = 'new_registration';
    private const MESSAGE_VERSION = '2.0';

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    public function send(array $data): void
    {
        // Validate required contract fields before building and publishing XML.
        if (empty($data['email'])) {
            throw new \InvalidArgumentException('email is required');
        }
        if (empty($data['user_id'])) {
            throw new \InvalidArgumentException('user_id is required');
        }
        if (empty($data['first_name'])) {
            throw new \InvalidArgumentException('first_name is required');
        }
        if (empty($data['last_name'])) {
            throw new \InvalidArgumentException('last_name is required');
        }
        if (empty($data['date_of_birth'])) {
            throw new \InvalidArgumentException('date_of_birth is required; without it CRM will not synchronize the registration to Kassa.');
        }
        if (!empty($data['is_company']) && empty($data['vat_number'])) {
            throw new \InvalidArgumentException('vat_number is required for companies');
        }

        $xml = $this->buildXml($data);

        try {
            $this->sendWithRetry(function () use ($xml): void {
                $msg = new AMQPMessage($xml, [
                    'delivery_mode' => 2,
                    'content_type' => 'application/xml',
                ]);
                $this->resolveClient()->getChannel()->basic_publish($msg, '', self::QUEUE_NAME);
            });
        } catch (\Throwable $e) {
            error_log('RabbitMQ connection/publish failed for host ' . $this->getEnv('RABBITMQ_HOST', 'rabbitmq_broker') . ': ' . $e->getMessage());
            throw $e;
        }
    }

    public function buildXml(array $data): string
    {
        if (empty($data['date_of_birth'])) {
            throw new \InvalidArgumentException('date_of_birth is required; without it CRM will not synchronize the registration to Kassa.');
        }

        // Correlation and message IDs are aligned to simplify cross-system tracing.
        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = false;

        $message = $xml->createElement('message');
        $xml->appendChild($message);

        $header = $xml->createElement('header');
        $header->appendChild($xml->createElement('message_id', $messageId));
        $header->appendChild($xml->createElement('correlation_id', $messageId));
        $header->appendChild($xml->createElement('version', self::MESSAGE_VERSION));
        $header->appendChild($xml->createElement('type', self::MESSAGE_TYPE));
        $header->appendChild($xml->createElement('timestamp', $timestamp));
        $header->appendChild($xml->createElement('source', self::SOURCE));
        $message->appendChild($header);

        $body = $xml->createElement('body');
        $customer = $xml->createElement('customer');
        $customer->appendChild($xml->createElement('email', (string) $data['email']));
        $customer->appendChild($xml->createElement('user_id', (string) $data['user_id']));

        if (!empty($data['type'])) {
            $customer->appendChild($xml->createElement('type', (string) $data['type']));
        } elseif (array_key_exists('is_company', $data)) {
            // Keep backward compatibility for clients still sending boolean company flags.
            $customer->appendChild($xml->createElement('type', !empty($data['is_company']) ? 'company' : 'private'));
        }

        if (array_key_exists('is_company_linked', $data)) {
            $customer->appendChild($xml->createElement('is_company_linked', !empty($data['is_company_linked']) ? 'true' : 'false'));
        } elseif (array_key_exists('is_company', $data)) {
            $customer->appendChild($xml->createElement('is_company_linked', !empty($data['is_company']) ? 'true' : 'false'));
        }

        $contact = $xml->createElement('contact');
        $contact->appendChild($xml->createElement('first_name', (string) $data['first_name']));
        $contact->appendChild($xml->createElement('last_name', (string) $data['last_name']));
        $customer->appendChild($contact);

        // date_of_birth is always required by downstream CRM -> Kassa forwarding.
        $customer->appendChild($xml->createElement('date_of_birth', (string) $data['date_of_birth']));

        if (!empty($data['badge_id'])) {
            $customer->appendChild($xml->createElement('badge_id', (string) $data['badge_id']));
        }

        if (!empty($data['company_name'])) {
            $customer->appendChild($xml->createElement('company_name', (string) $data['company_name']));
        }

        if (!empty($data['vat_number'])) {
            $customer->appendChild($xml->createElement('vat_number', (string) $data['vat_number']));
        }

        if (!empty($data['address']) && is_array($data['address'])) {
            $address = $xml->createElement('address');
            $addressFields = ['street', 'number', 'postal_code', 'city'];

            foreach ($addressFields as $field) {
                if (isset($data['address'][$field]) && $data['address'][$field] !== '') {
                    $address->appendChild($xml->createElement($field, (string) $data['address'][$field]));
                }
            }

            if (!empty($data['address']['country'])) {
                $countryCode = strtoupper((string) $data['address']['country']);
                if (strlen($countryCode) !== 2) {
                    throw new \InvalidArgumentException('address.country must be a 2-letter country code');
                }
                $address->appendChild($xml->createElement('country', $countryCode));
            }

            if ($address->hasChildNodes()) {
                $customer->appendChild($address);
            }
        }

        if (!empty($data['registration_fee']) && is_array($data['registration_fee'])) {
            $registrationFee = $xml->createElement('registration_fee');
            if (isset($data['registration_fee']['amount']) && $data['registration_fee']['amount'] !== '') {
                // CRM contract requires EUR for registration fee amounts.
                $amount = $xml->createElement('amount', (string) $data['registration_fee']['amount']);
                $amount->setAttribute('currency', 'eur');
                $registrationFee->appendChild($amount);
            }

            if (array_key_exists('paid', $data['registration_fee'])) {
                $registrationFee->appendChild(
                    $xml->createElement('paid', !empty($data['registration_fee']['paid']) ? 'true' : 'false')
                );
            }

            if ($registrationFee->hasChildNodes()) {
                $customer->appendChild($registrationFee);
            }
        }

        $body->appendChild($customer);
        $message->appendChild($body);

        return $xml->saveXML() ?: '';
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function resolveClient(): RabbitMQClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        // Fall back to environment configuration when no client is injected.
        $this->client = new RabbitMQClient(
            $this->getEnv('RABBITMQ_HOST', 'rabbitmq_broker'),
            (int) $this->getEnv('RABBITMQ_PORT', '5672'),
            $this->getEnv('RABBITMQ_USER', 'guest'),
            $this->getEnv('RABBITMQ_PASS', 'guest'),
            $this->getEnv('RABBITMQ_VHOST', '/')
        );

        return $this->client;
    }

    private function getEnv(string $name, string $default): string
    {
        $value = getenv($name);

        if ($value === false || trim((string) $value) === '') {
            return $default;
        }

        return trim((string) $value);
    }
}