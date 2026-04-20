<?php

declare(strict_types=1);

use Drupal\session_enrollment\Service\SessionEnrollmentService;
use Drupal\rabbitmq_sender\NewRegistrationSender;
use Drupal\rabbitmq_sender\CalendarInviteSender;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SessionEnrollmentService enrollment logic.
 */
class SessionEnrollmentServiceTest extends TestCase
{
    private function makeLogger(): object
    {
        $channel = $this->createStub(\Drupal\Core\Logger\LoggerChannelInterface::class);
        $factory = $this->createMock(\Drupal\Core\Logger\LoggerChannelFactoryInterface::class);
        $factory->method('get')->willReturn($channel);
        return $factory;
    }

    private function makeSessionMap(): array
    {
        return [
            'sess-001' => [
                'session_id'     => 'sess-001',
                'title'          => 'Keynote: AI 2026',
                'start_datetime' => '2026-05-15T14:00:00Z',
                'end_datetime'   => '2026-05-15T15:00:00Z',
                'location'       => 'Aula A',
            ],
            'sess-002' => [
                'session_id'     => 'sess-002',
                'title'          => 'Workshop Cloud',
                'start_datetime' => '2026-05-15T15:00:00Z',
                'end_datetime'   => '2026-05-15T16:00:00Z',
                'location'       => '',
            ],
        ];
    }

    private function makeUserData(): array
    {
        return [
            'email'      => 'jan@test.be',
            'first_name' => 'Jan',
            'last_name'  => 'Jansen',
            'is_company' => false,
        ];
    }

    public function test_enroll_calls_user_registered_sender_per_session(): void
    {
        $userRegisteredSender = $this->createMock(UserRegisteredSender::class);
        $calendarInviteSender = $this->createMock(CalendarInviteSender::class);

        $userRegisteredSender->expects($this->exactly(2))->method('send');
        $calendarInviteSender->expects($this->exactly(2))->method('send');

        $service = new SessionEnrollmentService($this->makeLogger(), $userRegisteredSender, $calendarInviteSender);
        $service->enroll($this->makeUserData(), ['sess-001', 'sess-002'], $this->makeSessionMap());
    }

    public function test_enroll_passes_correct_data_to_user_registered_sender(): void
    {
        $userRegisteredSender = $this->createMock(UserRegisteredSender::class);
        $calendarInviteSender = $this->createStub(CalendarInviteSender::class);

        $userRegisteredSender->expects($this->once())
            ->method('send')
            ->with($this->callback(function (array $data): bool {
                return $data['email'] === 'jan@test.be'
                    && $data['first_name'] === 'Jan'
                    && $data['session_id'] === 'sess-001'
                    && $data['session_name'] === 'Keynote: AI 2026'
                    && $data['is_company'] === false;
            }));

        $service = new SessionEnrollmentService($this->makeLogger(), $userRegisteredSender, $calendarInviteSender);
        $service->enroll($this->makeUserData(), ['sess-001'], $this->makeSessionMap());
    }

    public function test_enroll_passes_correct_data_to_calendar_invite_sender(): void
    {
        $userRegisteredSender = $this->createStub(UserRegisteredSender::class);
        $calendarInviteSender = $this->createMock(CalendarInviteSender::class);

        $calendarInviteSender->expects($this->once())
            ->method('send')
            ->with($this->callback(function (array $data): bool {
                return $data['session_id'] === 'sess-001'
                    && $data['title'] === 'Keynote: AI 2026'
                    && $data['start_datetime'] === '2026-05-15T14:00:00Z'
                    && $data['end_datetime'] === '2026-05-15T15:00:00Z';
            }));

        $service = new SessionEnrollmentService($this->makeLogger(), $userRegisteredSender, $calendarInviteSender);
        $service->enroll($this->makeUserData(), ['sess-001'], $this->makeSessionMap());
    }

    public function test_enroll_throws_when_email_missing(): void
    {
        $service = new SessionEnrollmentService(
            $this->makeLogger(),
            $this->createStub(UserRegisteredSender::class),
            $this->createStub(CalendarInviteSender::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->enroll(['first_name' => 'Jan', 'last_name' => 'Jansen'], ['sess-001'], $this->makeSessionMap());
    }

    public function test_enroll_throws_when_first_name_missing(): void
    {
        $service = new SessionEnrollmentService(
            $this->makeLogger(),
            $this->createStub(UserRegisteredSender::class),
            $this->createStub(CalendarInviteSender::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->enroll(['email' => 'jan@test.be', 'last_name' => 'Jansen'], ['sess-001'], $this->makeSessionMap());
    }

    public function test_enroll_throws_when_no_sessions_selected(): void
    {
        $service = new SessionEnrollmentService(
            $this->makeLogger(),
            $this->createStub(UserRegisteredSender::class),
            $this->createStub(CalendarInviteSender::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->enroll($this->makeUserData(), [], $this->makeSessionMap());
    }

    public function test_enroll_skips_unknown_session_ids(): void
    {
        $userRegisteredSender = $this->createMock(UserRegisteredSender::class);
        $calendarInviteSender = $this->createMock(CalendarInviteSender::class);

        // Only sess-001 exists in the map, unknown-id should be skipped.
        $userRegisteredSender->expects($this->once())->method('send');
        $calendarInviteSender->expects($this->once())->method('send');

        $service = new SessionEnrollmentService($this->makeLogger(), $userRegisteredSender, $calendarInviteSender);
        $service->enroll($this->makeUserData(), ['sess-001', 'unknown-id'], $this->makeSessionMap());
    }

    public function test_enroll_rethrows_user_registered_send_exception(): void
    {
        $userRegisteredSender = $this->createMock(UserRegisteredSender::class);
        $userRegisteredSender->method('send')->willThrowException(new \RuntimeException('Connection refused'));

        $calendarInviteSender = $this->createStub(CalendarInviteSender::class);

        $service = new SessionEnrollmentService($this->makeLogger(), $userRegisteredSender, $calendarInviteSender);

        $this->expectException(\RuntimeException::class);
        $service->enroll($this->makeUserData(), ['sess-001'], $this->makeSessionMap());
    }

    public function test_enroll_continues_after_calendar_invite_failure(): void
    {
        $userRegisteredSender = $this->createStub(UserRegisteredSender::class);
        $calendarInviteSender = $this->createMock(CalendarInviteSender::class);

        // calendar.invite fails for sess-001 but sess-002 should still be processed.
        $calendarInviteSender->method('send')
            ->willReturnCallback(function (array $data): void {
                if ($data['session_id'] === 'sess-001') {
                    throw new \RuntimeException('Exchange unreachable');
                }
            });

        // Both sessions should still call userRegisteredSender (CRM is primary).
        $userRegisteredSender = $this->createMock(UserRegisteredSender::class);
        $userRegisteredSender->expects($this->exactly(2))->method('send');

        $service = new SessionEnrollmentService($this->makeLogger(), $userRegisteredSender, $calendarInviteSender);
        // Should not throw despite sess-001 calendar.invite failure.
        $service->enroll($this->makeUserData(), ['sess-001', 'sess-002'], $this->makeSessionMap());
    }

    public function test_enroll_skips_calendar_invite_when_datetime_missing(): void
    {
        $userRegisteredSender = $this->createStub(UserRegisteredSender::class);
        $calendarInviteSender = $this->createMock(CalendarInviteSender::class);

        $calendarInviteSender->expects($this->never())->method('send');

        $sessionMapWithoutDatetime = [
            'sess-no-dt' => [
                'session_id' => 'sess-no-dt',
                'title'      => 'Session without datetime',
                // start_datetime and end_datetime are intentionally absent
            ],
        ];

        $service = new SessionEnrollmentService($this->makeLogger(), $userRegisteredSender, $calendarInviteSender);
        $service->enroll($this->makeUserData(), ['sess-no-dt'], $sessionMapWithoutDatetime);
    }
}
