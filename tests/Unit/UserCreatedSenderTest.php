<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_sender\UserCreatedSender;
use Drupal\rabbitmq_sender\RabbitMQClient;

class UserCreatedSenderTest extends TestCase
{
    private UserCreatedSender $sender;

    protected function setUp(): void
    {
        $mockClient = $this->createStub(RabbitMQClient::class);
        $this->sender = new UserCreatedSender($mockClient);
    }

    public function test_throws_exception_when_email_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sender->send([
            'first_name' => 'Jan',
            'last_name' => 'Jansen',
        ]);
    }

    public function test_valid_data_builds_correct_xml(): void
    {
        $xml = $this->sender->buildXml([
            'first_name' => 'Jan',
            'last_name' => 'Jansen',
            'email' => 'jan@test.be',
            'is_company' => false,
        ]);

        $this->assertStringContainsString('<type>user.created</type>', $xml);
        $this->assertStringContainsString('<email>jan@test.be</email>', $xml);
        $this->assertStringNotContainsString('<session>', $xml);
    }

    public function test_company_data_included_when_is_company_true(): void
    {
        $xml = $this->sender->buildXml([
            'first_name' => 'Jan',
            'last_name' => 'Jansen',
            'email' => 'jan@test.be',
            'is_company' => true,
            'company_name' => 'Bedrijf NV',
            'vat_number' => 'BE0123456789',
        ]);

        $this->assertStringContainsString('<vat_number>BE0123456789</vat_number>', $xml);
    }
}