<?php
declare(strict_types=1);

use PhpAmqpLib\Message\AMQPMessage;

class UserRegisteredSender
{
    private RabbitMQClient $client;

    public function __construct(RabbitMQClient $client)
    {
        $this->client = $client;
    }

    public function send(array $data): void
    {
        if (empty($data['email'])) {
            throw new \InvalidArgumentException('email is required');
        }
        if (empty($data['session_id'])) {
            throw new \InvalidArgumentException('session_id is required');
        }
        if (!empty($data['is_company']) && empty($data['vat_number'])) {
            throw new \InvalidArgumentException('vat_number is required for companies');
        }

        $xml = $this->buildXml($data);
        $msg = new AMQPMessage($xml, ['delivery_mode' => 2]);
        $this->client->getChannel()->basic_publish($msg, '', 'user.registered');
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
        $xml .= '<receiver>crm.salesforce</receiver>';
        $xml .= '<event_type>user.registered</event_type>';
        $xml .= '<version>1.0</version>';
        $xml .= '</header>';
        $xml .= '<payload>';
        $xml .= '<user>';
        $xml .= "<first_name>{$data['first_name']}</first_name>";
        $xml .= "<last_name>{$data['last_name']}</last_name>";
        $xml .= "<email>{$data['email']}</email>";
        $xml .= '<is_company>' . ($data['is_company'] ? 'true' : 'false') . '</is_company>';

        if (!empty($data['is_company'])) {
            $xml .= '<company>';
            $xml .= "<name>{$data['company_name']}</name>";
            $xml .= "<vat_number>{$data['vat_number']}</vat_number>";
            $xml .= '</company>';
        }

        $xml .= '</user>';
        $xml .= '<session>';
        $xml .= "<id>{$data['session_id']}</id>";
        $xml .= "<name>{$data['session_name']}</name>";
        $xml .= '</session>';
        $xml .= '<payment_status>pending</payment_status>';
        $xml .= '</payload>';
        $xml .= '</message>';

        return $xml;
    }
}