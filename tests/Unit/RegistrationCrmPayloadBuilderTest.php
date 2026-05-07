<?php

declare(strict_types=1);

use Drupal\registration_form\Service\RegistrationCrmPayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CRM payload sanitization and mapping logic.
 */
class RegistrationCrmPayloadBuilderTest extends TestCase
{
    public function test_build_excludes_password_and_keeps_required_crm_fields(): void
    {
        $builder = new RegistrationCrmPayloadBuilder();

        $payload = $builder->build([
            'email' => 'jan@example.com',
            'password' => 'SuperSecret123!',
            'first_name' => 'Jan',
            'last_name' => 'Janssen',
            'date_of_birth' => '1990-05-15',
            'session_id' => '550e8400-e29b-41d4-a716-446655440001',
            'registration_date' => '2026-03-31',
            'is_company' => false,
        ], '42');

        $this->assertSame('jan@example.com', $payload['email']);
        $this->assertSame('Jan', $payload['first_name']);
        $this->assertSame('Janssen', $payload['last_name']);
        $this->assertSame('1990-05-15', $payload['date_of_birth']);
        $this->assertSame('2026-03-31', $payload['registration_date']);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440001', $payload['session_id']);
        $this->assertSame('42', $payload['user_id']);
        $this->assertSame('private', $payload['type']);
        $this->assertFalse($payload['is_company_linked']);
        $this->assertArrayNotHasKey('password', $payload);
    }

    public function test_build_sets_company_fields_when_company_registration(): void
    {
        $builder = new RegistrationCrmPayloadBuilder();

        $payload = $builder->build([
            'email' => 'company@example.com',
            'first_name' => 'Lotte',
            'last_name' => 'Peeters',
            'date_of_birth' => '1988-11-30',
            'session_id' => '550e8400-e29b-41d4-a716-446655440002',
            'is_company' => true,
            'company_name' => 'Acme NV',
            'vat_number' => 'BE0123456789',
            'address' => [
                'country' => 'be',
            ],
            'registration_fee' => [
                'amount' => '25.00',
                'currency' => 'eur',
                'paid' => true,
            ],
            'badge_id' => 'BADGE-001',
        ], '99');

        $this->assertSame('company', $payload['type']);
        $this->assertTrue($payload['is_company_linked']);
        $this->assertSame('Acme NV', $payload['company_name']);
        $this->assertSame('BE0123456789', $payload['vat_number']);
        $this->assertSame(['country' => 'be'], $payload['address']);
        $this->assertSame('BADGE-001', $payload['badge_id']);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440002', $payload['session_id']);
        $this->assertSame('99', $payload['user_id']);
    }

    public function test_build_sets_safe_defaults_for_optional_fields(): void
    {
        $builder = new RegistrationCrmPayloadBuilder();

        $payload = $builder->build([
            'email' => 'jan@example.com',
            'first_name' => 'Jan',
            'last_name' => 'Janssen',
            'date_of_birth' => '1990-05-15',
        ], '7');

        $this->assertSame([], $payload['address']);
        $this->assertSame(['amount' => '0.00', 'status' => 'unpaid'], $payload['registration_fee']);
        $this->assertSame('', $payload['company_name']);
        $this->assertSame('', $payload['vat_number']);
        $this->assertSame('', $payload['badge_id']);
        $this->assertSame('', $payload['session_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $payload['registration_date']);
    }

    public function test_build_uses_provided_user_id_even_if_input_contains_user_id(): void
    {
        $builder = new RegistrationCrmPayloadBuilder();

        $payload = $builder->build([
            'email' => 'jan@example.com',
            'first_name' => 'Jan',
            'last_name' => 'Janssen',
            'date_of_birth' => '1990-05-15',
            'user_id' => 'input-user-id',
        ], 'server-generated-uid');

        $this->assertSame('server-generated-uid', $payload['user_id']);
    }

    public function test_build_coerces_non_array_optional_structures_to_safe_defaults(): void
    {
        $builder = new RegistrationCrmPayloadBuilder();

        $payload = $builder->build([
            'email' => 'jan@example.com',
            'first_name' => 'Jan',
            'last_name' => 'Janssen',
            'date_of_birth' => '1990-05-15',
            'address' => 'invalid',
            'registration_fee' => 'invalid',
        ], '12');

        $this->assertSame([], $payload['address']);
        $this->assertSame(['amount' => '0.00', 'status' => 'unpaid'], $payload['registration_fee']);
    }

    public function test_build_joins_session_ids_array_into_comma_separated_session_id(): void
    {
        $builder = new RegistrationCrmPayloadBuilder();

        $payload = $builder->build([
            'email'       => 'jan@example.com',
            'first_name'  => 'Jan',
            'last_name'   => 'Janssen',
            'date_of_birth' => '1990-05-15',
            'session_ids' => ['sess-001', 'sess-002', 'sess-003'],
        ], '5');

        $this->assertSame('sess-001,sess-002,sess-003', $payload['session_id']);
    }

    public function test_build_falls_back_to_session_id_string_when_session_ids_not_provided(): void
    {
        $builder = new RegistrationCrmPayloadBuilder();

        $payload = $builder->build([
            'email'       => 'jan@example.com',
            'first_name'  => 'Jan',
            'last_name'   => 'Janssen',
            'date_of_birth' => '1990-05-15',
            'session_id'  => 'sess-single-001',
        ], '5');

        $this->assertSame('sess-single-001', $payload['session_id']);
    }

    public function test_build_returns_empty_session_id_when_neither_key_provided(): void
    {
        $builder = new RegistrationCrmPayloadBuilder();

        $payload = $builder->build([
            'email'       => 'jan@example.com',
            'first_name'  => 'Jan',
            'last_name'   => 'Janssen',
            'date_of_birth' => '1990-05-15',
        ], '5');

        $this->assertSame('', $payload['session_id']);
    }
}
