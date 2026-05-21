<?php

declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use Drupal\rabbitmq_sender\XmlValidationTrait;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Receives wallet_balance_update messages from Kassa and stores the balance per user.
 */
class WalletBalanceReceiver
{
    use XmlValidationTrait;
    use ReceiverLogTrait;

    private const QUEUE    = 'frontend.payments';
    private const DLQ      = 'frontend.payments.dlq';
    private const DLX      = 'frontend.crm.dlx';
    private const XSD_PATH = __DIR__ . '/../../../../../xsd/wallet_balance_update.xsd';

    private ?RabbitMQClient $client;

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    /**
     * @return true
     * @throws \InvalidArgumentException
     */
    public function processMessageFromXml(string $xmlString): mixed
    {
        $this->validateXml($xmlString, self::XSD_PATH);
        $this->logReceiverSuccess(
            $this->extractXmlValue($xmlString, 'type') ?: 'wallet_balance_update',
            $this->extractXmlValue($xmlString, 'source') ?: 'kassa'
        );

        $xmlString = preg_replace('/ xmlns="[^"]*"/', '', $xmlString) ?? $xmlString;
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        libxml_clear_errors();

        if ($xml === false) {
            throw new \InvalidArgumentException('Invalid XML received');
        }

        $identityUuid = trim((string) $xml->body->identity_uuid);
        $balance      = trim((string) $xml->body->wallet_balance);
        $currency     = trim((string) ($xml->body->wallet_balance->attributes()['currency'] ?? 'eur'));

        if (empty($identityUuid)) {
            throw new \InvalidArgumentException('identity_uuid is required');
        }
        if ($balance === '') {
            throw new \InvalidArgumentException('wallet_balance is required');
        }

        \Drupal::logger('rabbitmq_receiver')->info(
            'wallet_balance_update: looking up user for identity_uuid @uuid.',
            ['@uuid' => $identityUuid]
        );

        $uid = $this->findUidByMasterUuid($identityUuid);
        if ($uid === null) {
            \Drupal::logger('rabbitmq_receiver')->error(
                'wallet_balance_update: No Drupal user found for identity_uuid @uuid — message rejected.',
                ['@uuid' => $identityUuid]
            );
            throw new \InvalidArgumentException('No user found for identity_uuid: ' . $identityUuid);
        }

        \Drupal::service('user.data')->set('registration_form', $uid, 'wallet_balance', [
            'amount'   => $balance,
            'currency' => $currency,
        ]);

        \Drupal::logger('rabbitmq_receiver')->info(
            'wallet_balance_update: stored balance @balance @currency for uid @uid (identity_uuid @uuid).',
            ['@balance' => $balance, '@currency' => strtoupper($currency), '@uid' => $uid, '@uuid' => $identityUuid]
        );

        return true;
    }

    public function pollOnce(): bool
    {
        $channel = $this->resolveClient()->getChannel();

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
