<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

class NewRegistrationSender
{
    use RetryTrait;

    private RabbitMQClient $client;

    private const QUEUES = [
        'crm.incoming',
        'planning.outlook',
        'mailing.sendgrid',
    ];

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

        foreach (self::QUEUES as $queue) {
            $this->sendWithRetry(function () use ($xml, $queue): void {
                $msg = new AMQPMessage($xml, ['delivery_mode' => 2]);
                $this->client->getChannel()->basic_publish($msg, '', $queue);
            });
        }
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
        $xml .= '<source>frontend.drupal</source>';
        $xml .= '<receiver>crm.incoming planning.outlook mailing.sendgrid</receiver>';
        $xml .= '<type>new_registration</type>';
        $xml .= '<version>2.0</version>';
        $xml .= '</header>';
        $xml .= '<body>';
        $xml .= '<customer>';
        $xml .= '<user_id>' . htmlspecialchars($messageId, ENT_XML1, 'UTF-8') . '</user_id>';
        $xml .= '<email>' . htmlspecialchars($data['email'], ENT_XML1, 'UTF-8') . '</email>';
        $xml .= '<type>' . (!empty($data['is_company']) ? 'company' : 'private') . '</type>';
        $xml .= '<contact>';
        $xml .= '<first_name>' . htmlspecialchars($data['first_name'] ?? '', ENT_XML1, 'UTF-8') . '</first_name>';
        $xml .= '<last_name>' . htmlspecialchars($data['last_name'] ?? '', ENT_XML1, 'UTF-8') . '</last_name>';
        $xml .= '</contact>';

        if (!empty($data['date_of_birth'])) {
            $xml .= '<date_of_birth>' . htmlspecialchars($data['date_of_birth'], ENT_XML1, 'UTF-8') . '</date_of_birth>';
        }

        if (!empty($data['is_company'])) {
            $xml .= '<is_company_linked>true</is_company_linked>';
            $xml .= '<company>';
            $xml .= '<name>' . htmlspecialchars($data['company_name'] ?? '', ENT_XML1, 'UTF-8') . '</name>';
            $xml .= '<vat_number>' . htmlspecialchars($data['vat_number'] ?? '', ENT_XML1, 'UTF-8') . '</vat_number>';
            $xml .= '</company>';
        } else {
            $xml .= '<is_company_linked>false</is_company_linked>';
        }

        $xml .= '</customer>';
        $xml .= '<session>';
        $xml .= '<id>' . htmlspecialchars($data['session_id'], ENT_XML1, 'UTF-8') . '</id>';
        $xml .= '<name>' . htmlspecialchars($data['session_name'] ?? '', ENT_XML1, 'UTF-8') . '</name>';
        $xml .= '</session>';
        $xml .= '<payment_status>pending</payment_status>';
        $xml .= '</body>';
        $xml .= '</message>';

        return $xml;
    }
}