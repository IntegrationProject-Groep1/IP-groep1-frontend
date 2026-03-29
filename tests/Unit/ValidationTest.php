<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\registration_form\RegistrationValidator;
use Drupal\registration_form\RegistrationRepositoryInterface;
use Drupal\registration_form\SessionRepositoryInterface;

class ValidationTest extends TestCase
{
    private RegistrationRepositoryInterface $registrationRepository;
    private SessionRepositoryInterface $sessionRepository;
    private RegistrationValidator $validator;

    protected function setUp(): void
    {
        $this->registrationRepository = $this->createMock(RegistrationRepositoryInterface::class);
        $this->sessionRepository = $this->createMock(SessionRepositoryInterface::class);

        $this->validator = new RegistrationValidator(
            $this->registrationRepository,
            $this->sessionRepository,
        );
    }

    /**
     * Returns a base set of valid registration data for use in tests.
     */
    private function validData(): array
    {
        return [
            'first_name'   => 'Jan',
            'last_name'    => 'Jansen',
            'email'        => 'jan@example.com',
            'session_id'   => '550e8400-e29b-41d4-a716-446655440000',
            'session_name' => 'Workshop AI',
            'is_company'   => false,
        ];
    }

    // -------------------------------------------------------------------------
    // Email validation
    // -------------------------------------------------------------------------

    public function test_validation_fails_when_email_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = $this->validData();
        unset($data['email']);

        $this->validator->validate($data);
    }

    public function test_validation_fails_when_email_has_invalid_format(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = $this->validData();
        $data['email'] = 'not-an-email';

        $this->validator->validate($data);
    }

    // -------------------------------------------------------------------------
    // session_id validation
    // -------------------------------------------------------------------------

    public function test_validation_fails_when_session_id_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = $this->validData();
        unset($data['session_id']);

        $this->validator->validate($data);
    }

    public function test_validation_fails_when_session_id_is_not_a_uuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = $this->validData();
        $data['session_id'] = 'not-a-valid-uuid';

        $this->validator->validate($data);
    }

    // -------------------------------------------------------------------------
    // Company / VAT validation
    // -------------------------------------------------------------------------

    public function test_validation_fails_when_company_name_filled_but_vat_number_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = $this->validData();
        $data['is_company']   = true;
        $data['company_name'] = 'Acme NV';
        // vat_number intentionally omitted

        $this->validator->validate($data);
    }

    public function test_validation_passes_without_vat_number_when_not_a_company(): void
    {
        $this->registrationRepository
            ->method('existsByEmailAndSession')
            ->willReturn(false);

        $this->sessionRepository
            ->method('isSessionFull')
            ->willReturn(false);

        $data = $this->validData();
        $data['is_company'] = false;
        // no vat_number — must be allowed

        $result = $this->validator->validate($data);

        $this->assertIsArray($result);
    }

    // -------------------------------------------------------------------------
    // Duplicate registration
    // -------------------------------------------------------------------------

    public function test_validation_fails_when_email_already_registered_for_session(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->registrationRepository
            ->method('existsByEmailAndSession')
            ->willReturn(true);

        $this->validator->validate($this->validData());
    }

    public function test_validation_passes_when_same_email_registers_for_different_session(): void
    {
        $this->registrationRepository
            ->method('existsByEmailAndSession')
            ->willReturn(false);

        $this->sessionRepository
            ->method('isSessionFull')
            ->willReturn(false);

        // Different session UUID compared to any previous registration —
        // the mock already returns false, confirming no conflict.
        $result = $this->validator->validate($this->validData());

        $this->assertIsArray($result);
    }

    // -------------------------------------------------------------------------
    // Session capacity
    // -------------------------------------------------------------------------

    public function test_validation_fails_when_session_is_full(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->registrationRepository
            ->method('existsByEmailAndSession')
            ->willReturn(false);

        $this->sessionRepository
            ->method('isSessionFull')
            ->willReturn(true);

        $this->validator->validate($this->validData());
    }

    // -------------------------------------------------------------------------
    // payment_status auto-assignment
    // -------------------------------------------------------------------------

    public function test_payment_status_is_set_to_pending_after_successful_validation(): void
    {
        $this->registrationRepository
            ->method('existsByEmailAndSession')
            ->willReturn(false);

        $this->sessionRepository
            ->method('isSessionFull')
            ->willReturn(false);

        $result = $this->validator->validate($this->validData());

        $this->assertSame('pending', $result['payment_status']);
    }
}
