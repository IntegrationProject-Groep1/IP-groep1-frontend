<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Consumes UserCreated events from the Identity Service.
 *
 * Exchange: user.events  (fanout, durable)
 * Queue:    frontend.user_created  (durable, bound to exchange without routing key)
 *
 * On receipt: looks up the local Drupal user by email and stores master_uuid
 * via the user.data key-value service (module='rabbitmq_receiver', key='master_uuid').
 */
class UserCreatedReceiver
{
    private const EXCHANGE      = 'user.events';
    private const EXCHANGE_TYPE = 'fanout';
    private const QUEUE         = 'frontend.user_created';

    private RabbitMQClient $client;

    public function __construct(RabbitMQClient $client)
    {
        $this->client = $client;
    }

    public function listen(): void
    {
        $channel = $this->client->getChannel();

        // Declare exchange idempotently — safe if Identity Service already declared it.
        $channel->exchange_declare(self::EXCHANGE, self::EXCHANGE_TYPE, false, true, false);

        // Durable queue so events are not lost while the consumer is offline.
        $channel->queue_declare(self::QUEUE, false, true, false, false);

        // Fanout exchange: routing key is ignored, empty string is conventional.
        $channel->queue_bind(self::QUEUE, self::EXCHANGE, '');

        $channel->basic_consume(
            self::QUEUE,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $msg): void {
                $this->processMessage($msg);
            }
        );

        echo 'Listening for UserCreated events on ' . self::EXCHANGE . ' → ' . self::QUEUE . "\n";

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    /**
     * Parses a UserCreated XML event string from the Identity Service.
     *
     * @return array{event: string, master_uuid: string, email: string, source_system: string, timestamp: string}
     * @throws \InvalidArgumentException on invalid or incomplete XML.
     */
    public function processMessageFromXml(string $xmlString): array
    {
        $xml = @simplexml_load_string($xmlString);
        if ($xml === false) {
            throw new \InvalidArgumentException('Invalid XML received');
        }

        $event = trim((string) ($xml->event ?? ''));
        if ($event !== 'UserCreated') {
            throw new \InvalidArgumentException('Unexpected event type: ' . $event);
        }

        $masterUuid = trim((string) ($xml->master_uuid ?? ''));
        if ($masterUuid === '') {
            throw new \InvalidArgumentException('master_uuid is required');
        }

        $email = strtolower(trim((string) ($xml->email ?? '')));
        if ($email === '') {
            throw new \InvalidArgumentException('email is required');
        }

        return [
            'event'         => $event,
            'master_uuid'   => $masterUuid,
            'email'         => $email,
            'source_system' => trim((string) ($xml->source_system ?? '')),
            'timestamp'     => trim((string) ($xml->timestamp ?? '')),
        ];
    }

    private function processMessage(AMQPMessage $msg): void
    {
        try {
            $data = $this->processMessageFromXml($msg->body);
            $this->storeMasterUuid($data['email'], $data['master_uuid']);
            $msg->ack();
        } catch (\Throwable $e) {
            error_log('[UserCreatedReceiver] Failed to process message: ' . $e->getMessage());
            // Discard without requeue: malformed messages would loop endlessly.
            $msg->nack(false, false);
        }
    }

    /**
     * Persists master_uuid on the local Drupal user matched by email.
     *
     * Uses Drupal's user.data key-value service so no schema migration is needed.
     * Safe to call when Drupal bootstrap is absent (CLI tests outside full Drupal).
     */
    private function storeMasterUuid(string $email, string $masterUuid): void
    {
        if (!class_exists('\Drupal') || !\Drupal::hasContainer()) {
            return;
        }

        $storage = \Drupal::entityTypeManager()->getStorage('user');
        $ids = $storage->getQuery()
            ->accessCheck(false)
            ->condition('mail', $email)
            ->range(0, 1)
            ->execute();

        if (empty($ids)) {
            // Identity event can arrive before the user registers locally — ignore.
            return;
        }

        $userId = (int) reset($ids);
        \Drupal::service('user.data')->set('registration_form', $userId, 'master_uuid', $masterUuid);
    }
}
