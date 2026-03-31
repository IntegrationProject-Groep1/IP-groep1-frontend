<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_receiver\SessionUpdateReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for session update receiver XML validation.
 */
class SessionUpdateReceiverTest extends TestCase
{
    public function test_throws_exception_when_xml_is_invalid(): void
    {
        $stubClient = $this->createStub(RabbitMQClient::class);
        $receiver = new SessionUpdateReceiver($stubClient);

        $this->expectException(\InvalidArgumentException::class);
        $receiver->processMessageFromXml('invalid xml');
    }

    public function test_throws_exception_when_session_id_is_missing(): void
    {
        $stubClient = $this->createStub(RabbitMQClient::class);
        $receiver = new SessionUpdateReceiver($stubClient);

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message><body>';
        $xml .= '<new_time>14:00</new_time>';
        $xml .= '<location>Zaal A</location>';
        $xml .= '</body></message>';

        $this->expectException(\InvalidArgumentException::class);
        $receiver->processMessageFromXml($xml);
    }

    public function test_valid_xml_is_processed_correctly(): void
    {
        $stubClient = $this->createStub(RabbitMQClient::class);
        $receiver = new SessionUpdateReceiver($stubClient);

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message><body>';
        $xml .= '<session_id>session-uuid-001</session_id>';
        $xml .= '<new_time>14:00</new_time>';
        $xml .= '<location>Zaal A</location>';
        $xml .= '</body></message>';

        $result = $receiver->processMessageFromXml($xml);
        $this->assertTrue($result);
    }
}