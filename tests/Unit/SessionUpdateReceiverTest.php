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

    public function test_throws_exception_when_session_name_is_missing(): void
    {
        $stubClient = $this->createStub(RabbitMQClient::class);
        $receiver = new SessionUpdateReceiver($stubClient);

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message><body>';
        $xml .= '<status>updated</status>';
        $xml .= '<start_time>2026-05-01T09:00:00.000Z</start_time>';
        $xml .= '<end_time>2026-05-01T11:00:00.000Z</end_time>';
        $xml .= '</body></message>';

        $this->expectException(\InvalidArgumentException::class);
        $receiver->processMessageFromXml($xml);
    }

    public function test_throws_exception_when_status_is_missing(): void
    {
        $stubClient = $this->createStub(RabbitMQClient::class);
        $receiver = new SessionUpdateReceiver($stubClient);

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message><body>';
        $xml .= '<session_name>Workshop AI</session_name>';
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
        $xml .= '<session_name>Workshop AI</session_name>';
        $xml .= '<status>updated</status>';
        $xml .= '<start_time>2026-05-01T09:00:00.000Z</start_time>';
        $xml .= '<end_time>2026-05-01T11:00:00.000Z</end_time>';
        $xml .= '</body></message>';

        $result = $receiver->processMessageFromXml($xml);
        $this->assertTrue($result);
    }
}
