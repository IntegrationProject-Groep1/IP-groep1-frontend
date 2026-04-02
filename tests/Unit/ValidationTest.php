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

    public function test_validation_passes_without_vat_number_when_not_a_company(): void
    {
        $this->registrationRepository
            ->method('existsByEmailAndSession')
            ->willReturn(false);

        $this->sessionRepository
            ->method('isSessionFull')
            ->willReturn(false);

        $result = $this->validator->validate($this->validData());

        $this->assertIsArray($result);
    }

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
}