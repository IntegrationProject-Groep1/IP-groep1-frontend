<?php
declare(strict_types=1);

use PhpAmqpLib\Message\AMQPMessage;

class UserCheckinSender
{
    private RabbitMQClient $client;

    public function __construct(RabbitMQClient $client)
    {
        $this->client = $client;
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
        $msg = new AMQPMessage($xml, ['delivery_mode' => 2]);
        $this->client->getChannel()->basic_publish($msg, '', 'user.checkin');
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
        $xml .= "<user_id>{$data['user_id']}</user_id>";
        $xml .= "<badge_id>{$data['badge_id']}</badge_id>";
        $xml .= "<timestamp>{$timestamp}</timestamp>";
        $xml .= '</payload>';
        $xml .= '</message>';

        return $xml;
    }
}