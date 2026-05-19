<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_sender\UserUnregisteredSender;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for user unregistered sender validation and XML generation.
 */
class UserUnregisteredSenderTest extends TestCase
{
    private UserUnregisteredSender $sender;

    protected function setUp(): void
    {
        $mockClient = $this->createStub(RabbitMQClient::class);
        $this->sender = new UserUnregisteredSender($mockClient);
    }

    public function test_throws_exception_when_user_id_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sender->send([
            'session_id' => 'session-uuid-001',
        ]);
    }

    public function test_throws_exception_when_email_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sender->send([
            'user_id' => 'uuid-v4-hier',
        ]);
    }

    public function test_valid_data_builds_correct_xml(): void
    {
        $xml = $this->sender->buildXml([
            'identity_uuid' => 'uuid-v4-hier',
            'email'         => 'jan@test.be',
        ]);

        $this->assertStringContainsString('<type>user_deleted</type>', $xml);
        $this->assertStringContainsString('<identity_uuid>uuid-v4-hier</identity_uuid>', $xml);
        $this->assertStringContainsString('<email>jan@test.be</email>', $xml);
    }
}
