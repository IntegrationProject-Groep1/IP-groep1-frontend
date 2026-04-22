<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * RPC client for the Identity Service master UUID management.
 *
 * Sends a create-or-get request to identity.user.create.request and returns
 * the master_uuid from the identity_response XML.
 *
 * Exchange protocol:
 *   Request queue:  identity.user.create.request  (durable)
 *   Reply queue:    exclusive auto-delete per call
 *   Correlation ID: random hex string, matched on reply
 */
class IdentityServiceClient
{
    private const REQUEST_QUEUE = 'identity.user.create.request';
    private const SOURCE_SYSTEM = 'frontend';
    private const RPC_TIMEOUT   = 5.0; // seconds

    private ?RabbitMQClient $client;

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    /**
     * Creates or retrieves the master UUID for the given email.
     *
     * The Identity Service is idempotent: repeated calls with the same email
     * always return the same UUID.
     *
     * @throws \InvalidArgumentException When email is empty or response XML is malformed.
     * @throws \RuntimeException         When Identity Service does not respond in time.
     */
    public function createOrGet(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            throw new \InvalidArgumentException('email is required');
        }

        $correlationId = bin2hex(random_bytes(16));
        $channel       = $this->resolveClient()->getChannel();

        // Declare target queue idempotently so we can publish without prior provisioning.
        $channel->queue_declare(self::REQUEST_QUEUE, false, true, false, false);

        // Create an exclusive auto-delete reply queue with a server-generated name.
        [$replyQueue] = $channel->queue_declare('', false, false, true, true);

        $requestXml = $this->buildRequestXml($email);

        $msg = new AMQPMessage($requestXml, [
            'reply_to'       => $replyQueue,
            'correlation_id' => $correlationId,
            'delivery_mode'  => 2,
            'content_type'   => 'application/xml',
        ]);
        $channel->basic_publish($msg, '', self::REQUEST_QUEUE);

        $masterUuid = null;
        $channel->basic_consume(
            $replyQueue,
            '',
            false,
            true,   // auto-ack: reply queues don't need manual ack
            false,
            false,
            function (AMQPMessage $reply) use (&$masterUuid, $correlationId): void {
                if ($reply->get('correlation_id') === $correlationId) {
                    $masterUuid = $this->parseMasterUuid($reply->body);
                }
            }
        );

        $deadline = microtime(true) + self::RPC_TIMEOUT;
        while ($masterUuid === null && microtime(true) < $deadline) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            try {
                $channel->wait(null, false, $remaining);
            } catch (AMQPTimeoutException $e) {
                break;
            }
        }

        if ($masterUuid === null) {
            throw new \RuntimeException('Identity Service did not respond within ' . self::RPC_TIMEOUT . 's for: ' . $email);
        }

        return $masterUuid;
    }

    /**
     * Builds the XML request payload for identity.user.create.request.
     * Email is normalized (trim + lowercase) to match Identity Service canonical form.
     */
    public function buildRequestXml(string $email): string
    {
        $email = strtolower(trim($email));
        $doc   = new \DOMDocument('1.0', 'UTF-8');
        $root  = $doc->createElement('identity_request');
        $doc->appendChild($root);
        $root->appendChild($doc->createElement('email', htmlspecialchars($email, ENT_XML1, 'UTF-8')));
        $root->appendChild($doc->createElement('source_system', self::SOURCE_SYSTEM));

        return $doc->saveXML() ?: '';
    }

    /**
     * Parses the master_uuid from an identity_response XML string.
     *
     * @throws \InvalidArgumentException When XML is invalid or master_uuid is absent.
     * @throws \RuntimeException         When status is not 'ok'.
     */
    public function parseMasterUuid(string $xmlString): string
    {
        $xml = @simplexml_load_string($xmlString);
        if ($xml === false) {
            throw new \InvalidArgumentException('Invalid XML response from Identity Service');
        }

        $status = trim((string) ($xml->status ?? ''));
        if ($status !== 'ok') {
            throw new \RuntimeException('Identity Service returned non-ok status: ' . $status);
        }

        $masterUuid = trim((string) ($xml->user->master_uuid ?? ''));
        if ($masterUuid === '') {
            throw new \InvalidArgumentException('master_uuid missing in Identity Service response');
        }

        return $masterUuid;
    }

    private function resolveClient(): RabbitMQClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $host     = getenv('RABBITMQ_HOST')  ?: 'rabbitmq_broker';
        $port     = (int) (getenv('RABBITMQ_PORT')  ?: 5672);
        $user     = getenv('RABBITMQ_USER')  ?: 'guest';
        $password = getenv('RABBITMQ_PASS')  ?: 'guest';
        $vhost    = getenv('RABBITMQ_VHOST') ?: '/';

        $this->client = new RabbitMQClient($host, $port, $user, $password, $vhost);

        return $this->client;
    }
}
