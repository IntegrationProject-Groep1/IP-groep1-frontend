<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_sender\NewRegistrationSender;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for CRM new registration sender contract and XML output.
 */
class NewRegistrationSenderTest extends TestCase
{
    private NewRegistrationSender $sender;

    protected function setUp(): void
    {
        $mockClient = $this->createStub(RabbitMQClient::class);
        $this->sender = new NewRegistrationSender($mockClient);
    }

    public function test_throws_exception_when_email_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sender->send([
            'user_id' => '12345',
            'first_name' => 'Jan',
            'last_name' => 'Jansen',
            'date_of_birth' => '1990-05-15',
            'is_company' => false,
        ]);
    }

    public function test_throws_exception_when_user_id_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sender->send([
            'email' => 'jan@test.be',
            'first_name' => 'Jan',
            'last_name' => 'Jansen',
            'date_of_birth' => '1990-05-15',
            'is_company' => false,
        ]);
    }

    public function test_throws_exception_when_date_of_birth_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sender->send([
            'email' => 'jan@test.be',
            'user_id' => '12345',
            'first_name' => 'Jan',
            'last_name' => 'Jansen',
            'is_company' => false,
        ]);
    }

    public function test_throws_exception_when_company_has_no_vat_number(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sender->send([
            'email' => 'jan@test.be',
            'user_id' => '12345',
            'first_name' => 'Jan',
            'last_name' => 'Jansen',
            'date_of_birth' => '1990-05-15',
            'is_company' => true,
            'company_name' => 'Bedrijf NV',
        ]);
    }

    public function test_valid_data_builds_correct_xml(): void
    {
        $xml = $this->sender->buildXml([
            'first_name' => 'Jan',
            'last_name' => 'Jansen',
            'email' => 'jan@test.be',
            'user_id' => '12345',
            'date_of_birth' => '1990-05-15',
            'registration_date' => '2026-03-31',
            'session_id' => '550e8400-e29b-41d4-a716-446655440001',
            'is_company' => false,
        ]);

        $this->assertStringContainsString('<message>', $xml);
        $this->assertStringContainsString('<body>', $xml);
        $this->assertStringContainsString('<customer>', $xml);
        $this->assertStringContainsString('<email>jan@test.be</email>', $xml);
        $this->assertStringContainsString('<user_id>12345</user_id>', $xml);
        $this->assertStringContainsString('<first_name>Jan</first_name>', $xml);
        $this->assertStringContainsString('<last_name>Jansen</last_name>', $xml);
        $this->assertStringContainsString('<date_of_birth>1990-05-15</date_of_birth>', $xml);
    }
}