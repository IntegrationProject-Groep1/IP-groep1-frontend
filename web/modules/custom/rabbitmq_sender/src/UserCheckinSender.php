<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

class UserCheckinSender
{
    use RetryTrait;

    private RabbitMQClient $client;
    private string $queueName;

    public function __construct(RabbitMQClient $client, ?string $queueName = null)
    {
        $this->client = $client;
        $prefix = getenv('RABBITMQ_PREFIX') ?: 'frontend.';
        $this->queueName = $queueName ?? ($prefix . 'user.checkin');
    }

    public function send(array $data): void
    {
        if (empty($data['user_id'])) {
            throw new \InvalidArgumentException('user_id is required');
        }
        if (empty($data['badge_id'])) {
            throw new \InvalidArgumentException('badge_id is required');
        }

        $xml = $this->buildXml($data);
        $this->sendWithRetry(function () use ($xml): void {
            $msg = new AMQPMessage($xml, ['delivery_mode' => 2]);
            $this->client->getChannel()->basic_publish($msg, '', $this->queueName);
        });
    }

    public function buildXml(array $data): string
    {
        $messageId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $timestamp = (new \DateTime())->format('c');

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message>';
        $xml .= '<header>';
        $xml .= "<message_id>{$messageId}</message_id>";
        $xml .= "<timestamp>{$timestamp}</timestamp>";
        $xml .= '<sender>frontend.drupal</sender>';
        $xml .= '<receiver>monitoring.elastic</receiver>';
        $xml .= '<event_type>user.checkin</event_type>';
        $xml .= '<version>1.0</version>';
        $xml .= '</header>';
        $xml .= '<payload>';
        $xml .= '<user_id>' . htmlspecialchars($data['user_id'], ENT_XML1, 'UTF-8') . '</user_id>';
        $xml .= '<badge_id>' . htmlspecialchars($data['badge_id'], ENT_XML1, 'UTF-8') . '</badge_id>';
        $xml .= "<timestamp>{$timestamp}</timestamp>";
        $xml .= '</payload>';
        $xml .= '</message>';

        return $xml;
    }
}