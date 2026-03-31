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
        $this->expectExceptionMessage('date_of_birth is required; without it CRM will not synchronize the registration to Kassa.');
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
            'address' => [
                'street' => 'Kerkstraat',
                'number' => '42',
                'postal_code' => '2000',
                'city' => 'Antwerpen',
                'country' => 'be',
            ],
        ]);

        $this->assertStringContainsString('<message>', $xml);
        $this->assertStringContainsString('<header>', $xml);
        $this->assertMatchesRegularExpression('/<message_id>[0-9a-f\-]{36}<\/message_id>/', $xml);
        preg_match('/<message_id>([0-9a-f\-]{36})<\/message_id>/', $xml, $messageIdMatch);
        preg_match('/<correlation_id>([0-9a-f\-]{36})<\/correlation_id>/', $xml, $correlationIdMatch);
        $this->assertSame($messageIdMatch[1] ?? '', $correlationIdMatch[1] ?? '');
        $this->assertMatchesRegularExpression('/<timestamp>\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\+00:00<\/timestamp>/', $xml);
        $this->assertStringContainsString('<type>new_registration</type>', $xml);
        $this->assertStringContainsString('<version>2.0</version>', $xml);
        $this->assertStringContainsString('<source>frontend</source>', $xml);
        $this->assertStringContainsString('<body>', $xml);
        $this->assertStringContainsString('<customer>', $xml);
        $this->assertStringContainsString('<email>jan@test.be</email>', $xml);
        $this->assertStringContainsString('<user_id>12345</user_id>', $xml);
        $this->assertStringContainsString('<type>private</type>', $xml);
        $this->assertStringNotContainsString('<contact>', $xml);
        $this->assertStringContainsString('<first_name>Jan</first_name>', $xml);
        $this->assertStringContainsString('<last_name>Jansen</last_name>', $xml);
        $this->assertStringContainsString('<date_of_birth>1990-05-15</date_of_birth>', $xml);
        $this->assertStringContainsString('<registration_date>2026-03-31</registration_date>', $xml);
        $this->assertStringContainsString('<session_id>550e8400-e29b-41d4-a716-446655440001</session_id>', $xml);
        $this->assertStringContainsString('<is_company_linked>false</is_company_linked>', $xml);
        $this->assertStringContainsString('<country>BE</country>', $xml);
    }

    public function test_build_xml_contains_company_and_registration_fee_when_provided(): void
    {
        $xml = $this->sender->buildXml([
            'first_name' => 'Lotte',
            'last_name' => 'Peeters',
            'email' => 'lotte@test.be',
            'user_id' => '777',
            'date_of_birth' => '1985-09-03',
            'is_company' => true,
            'company_name' => 'Acme NV',
            'vat_number' => 'BE0123456789',
            'registration_fee' => [
                'amount' => '25.00',
                'currency' => 'eur',
                'paid' => true,
            ],
        ]);

        $this->assertStringContainsString('<type>company</type>', $xml);
        $this->assertStringContainsString('<is_company_linked>true</is_company_linked>', $xml);
        $this->assertStringContainsString('<company_name>Acme NV</company_name>', $xml);
        $this->assertStringContainsString('<vat_number>BE0123456789</vat_number>', $xml);
        $this->assertStringContainsString('<registration_fee>', $xml);
        $this->assertStringContainsString('<amount currency="eur">25.00</amount>', $xml);
        $this->assertStringContainsString('<paid>true</paid>', $xml);
    }

    public function test_build_xml_forces_registration_fee_currency_to_eur(): void
    {
        $xml = $this->sender->buildXml([
            'first_name' => 'Lotte',
            'last_name' => 'Peeters',
            'email' => 'lotte@test.be',
            'user_id' => '777',
            'date_of_birth' => '1985-09-03',
            'registration_fee' => [
                'amount' => '25.00',
                'currency' => 'usd',
            ],
        ]);

        $this->assertStringContainsString('<amount currency="eur">25.00</amount>', $xml);
    }

    public function test_build_xml_throws_for_invalid_country_code_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sender->buildXml([
            'first_name' => 'Jan',
            'last_name' => 'Jansen',
            'email' => 'jan@test.be',
            'user_id' => '12345',
            'date_of_birth' => '1990-05-15',
            'is_company' => false,
            'address' => [
                'country' => 'BEL',
            ],
        ]);
    }
}