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
    use ReceiverLogTrait;

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
        $this->logReceiverSuccess(
            $this->extractXmlValue($xmlString, 'type') ?: 'badge_scanned',
            $this->extractXmlValue($xmlString, 'source') ?: 'CRM'
        );
        
        $xmlString = preg_replace('/ xmlns="[^"]*"/', '', $xmlString) ?? $xmlString;
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        libxml_clear_errors();

        if ($xml === false) {
            throw new \InvalidArgumentException('Invalid XML received');
        }

        $badgeId      = (string) $xml->body->badge_id;
        $identityUuid = (string) $xml->body->identity_uuid;
        $location     = (string) $xml->body->location;
        $scannedAt    = (string) $xml->body->scanned_at;

        if (empty($badgeId) && empty($identityUuid)) {
            throw new \InvalidArgumentException('Either badge_id or identity_uuid is required');
        }
        if (empty($location)) {
            throw new \InvalidArgumentException('location is required');
        }
        if (empty($scannedAt)) {
            throw new \InvalidArgumentException('scanned_at is required');
        }

        if (!empty($identityUuid)) {
            \Drupal::logger('rabbitmq_receiver')->info(
                'badge_scanned: QR path — looking up user for identity_uuid @uuid at @location.',
                ['@uuid' => $identityUuid, '@location' => $location]
            );

            if ($this->userCheckinSender !== null) {
                $uid = $this->findUidByMasterUuid($identityUuid);
                if ($uid === null) {
                    \Drupal::logger('rabbitmq_receiver')->error(
                        'badge_scanned: No Drupal user found for identity_uuid @uuid — message rejected.',
                        ['@uuid' => $identityUuid]
                    );
                    throw new \InvalidArgumentException('No user found for identity_uuid: ' . $identityUuid);
                }

                \Drupal::logger('rabbitmq_receiver')->info(
                    'badge_scanned: Found uid @uid for identity_uuid @uuid — sending user_checkin.',
                    ['@uid' => $uid, '@uuid' => $identityUuid]
                );

                $this->userCheckinSender->send([
                    'user_id'    => $identityUuid,
                    'badge_id'   => $identityUuid,
                    'checkin_at' => $scannedAt,
                ]);

                \Drupal::logger('rabbitmq_receiver')->info(
                    'badge_scanned: user_checkin sent for uid @uid at @location.',
                    ['@uid' => $uid, '@location' => $location]
                );
            }
        } else {
            \Drupal::logger('rabbitmq_receiver')->info(
                'badge_scanned: Physical badge path — badge_id @id at @location (no checkin forwarded).',
                ['@id' => $badgeId, '@location' => $location]
            );
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

        $msg = $channel->basic_get(self::QUEUE);
        if (!$msg) {
            return false;
        }

        try {
            $this->processMessageFromXml($msg->body);
            $msg->ack();
        } catch (\Throwable $e) {
            $this->logReceiverError($e, self::QUEUE, $msg->body);
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
                    $this->logReceiverError($e, self::QUEUE, $msg->body);
                    $msg->nack(false, false);
                }
            }
        );

        while ($channel->is_consuming()) {
            $channel->wait();
        }
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
