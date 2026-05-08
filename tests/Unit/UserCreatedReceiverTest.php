<?php
declare(strict_types=1);

use Drupal\rabbitmq_receiver\UserCreatedReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;
use PHPUnit\Framework\TestCase;
use Tests\Unit\XmlTestBuilder;

/**
 * Unit tests for UserCreatedReceiver XML parsing.
 */
class UserCreatedReceiverTest extends TestCase
{
    private UserCreatedReceiver $receiver;

    protected function setUp(): void
    {
        $mockClient = $this->createStub(RabbitMQClient::class);
        $this->receiver = new UserCreatedReceiver($mockClient);
    }

    private function buildXml(array $fields): string
    {
        return XmlTestBuilder::build('UserCreated', [], $fields, 'user_event', false);
    }

    public function test_returns_expected_data_from_valid_event(): void
    {
        $xml = $this->buildXml([
            'event' => 'UserCreated',
            'master_uuid' => '01890a5d-ac96-7ab2-80e2-4536629c90de',
            'email' => 'user@example.com',
            'source_system' => 'crm',
            'timestamp' => '2026-04-05T12:00:00Z'
        ]);

        $data = $this->receiver->processMessageFromXml($xml);

        $this->assertSame('UserCreated', $data['event']);
        $this->assertSame('01890a5d-ac96-7ab2-80e2-4536629c90de', $data['master_uuid']);
        $this->assertSame('user@example.com', $data['email']);
        $this->assertSame('crm', $data['source_system']);
        $this->assertSame('2026-04-05T12:00:00Z', $data['timestamp']);
    }

    public function test_normalizes_email_to_lowercase(): void
    {
        $xml = $this->buildXml([
            'event' => 'UserCreated',
            'master_uuid' => '01890a5d-ac96-7ab2-80e2-4536629c90de',
            'email' => 'USER@EXAMPLE.COM',
            'source_system' => 'frontend',
            'timestamp' => '2026-04-05T12:00:00Z'
        ]);

        $data = $this->receiver->processMessageFromXml($xml);

        $this->assertSame('user@example.com', $data['email']);
    }

    public function test_throws_on_invalid_xml(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml('not valid xml');
    }

    public function test_throws_on_wrong_event_type(): void
    {
        $this->expectException(\Exception::class);
        $xml = $this->buildXml([
            'event' => 'UserDeleted',
            'master_uuid' => '01890a5d-ac96-7ab2-80e2-4536629c90de',
            'email' => 'user@example.com',
            'source_system' => 'crm',
            'timestamp' => '2026-04-05T12:00:00Z'
        ]);

        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_when_master_uuid_is_missing(): void
    {
        $this->expectException(\Exception::class);
        $xml = $this->buildXml([
            'event' => 'UserCreated',
            'email' => 'user@example.com',
            'source_system' => 'crm',
            'timestamp' => '2026-04-05T12:00:00Z'
        ]);

        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_when_email_is_missing(): void
    {
        $this->expectException(\Exception::class);
        $xml = $this->buildXml([
            'event' => 'UserCreated',
            'master_uuid' => '01890a5d-ac96-7ab2-80e2-4536629c90de',
            'source_system' => 'crm',
            'timestamp' => '2026-04-05T12:00:00Z'
        ]);

        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_when_master_uuid_is_empty_string(): void
    {
        $this->expectException(\Exception::class);
        $xml = $this->buildXml([
            'event' => 'UserCreated',
            'master_uuid' => '   ',
            'email' => 'user@example.com',
            'source_system' => 'crm',
            'timestamp' => '2026-04-05T12:00:00Z'
        ]);

        $this->receiver->processMessageFromXml($xml);
    }
}
