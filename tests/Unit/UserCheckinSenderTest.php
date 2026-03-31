<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_sender\UserCheckinSender;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for user check-in sender input validation and XML output.
 */
class UserCheckinSenderTest extends TestCase
{
    private UserCheckinSender $sender;

    protected function setUp(): void
    {
        $mockClient = $this->createStub(RabbitMQClient::class);
        $this->sender = new UserCheckinSender($mockClient);
    }

    public function test_throws_exception_when_user_id_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sender->send([
            'badge_id' => 'nfc-badge-abc123',
        ]);
    }

    public function test_throws_exception_when_badge_id_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sender->send([
            'user_id' => 'uuid-v4-hier',
        ]);
    }

    public function test_valid_data_builds_correct_xml(): void
    {
        $xml = $this->sender->buildXml([
            'user_id' => 'uuid-v4-hier',
            'badge_id' => 'nfc-badge-abc123',
        ]);

        $this->assertStringContainsString('<type>user.checkin</type>', $xml);
        $this->assertStringContainsString('<user_id>uuid-v4-hier</user_id>', $xml);
        $this->assertStringContainsString('<badge_id>nfc-badge-abc123</badge_id>', $xml);
    }
}