<?php

declare(strict_types=1);

namespace Drupal\session_enrollment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\session_enrollment\Service\SessionEnrollmentService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Handles single-session enrollment via a direct GET link (no JS required).
 */
class EnrollSingleController extends ControllerBase
{
    public function __construct(
        private readonly SessionEnrollmentService $enrollmentService,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('session_enrollment.enrollment_service'),
        );
    }

    public function enroll(string $session_id): RedirectResponse
    {
        $currentUser  = $this->currentUser();
        $uid          = (int) $currentUser->id();
        $identityUuid = (string) (\Drupal::service('user.data')->get('registration_form', $uid, 'master_uuid') ?? '');

        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $identityUuid)) {
            $this->messenger()->addError($this->t('Je account is nog niet volledig ingesteld. Voltooi eerst je registratie.'));
            return new RedirectResponse('/sessions/enroll');
        }

        $session = $this->resolveSession($session_id);

        if ($session === null) {
            $this->messenger()->addError($this->t('Sessie niet gevonden.'));
            return new RedirectResponse('/sessions/enroll');
        }

        $userData = [
            'email'        => $currentUser->getEmail(),
            'user_id'      => (string) $uid,
            'master_uuid'  => $identityUuid,
            'first_name'   => $this->resolveUserField($uid, 'field_first_name') ?: $currentUser->getAccountName(),
            'last_name'    => $this->resolveUserField($uid, 'field_last_name'),
            'is_company'   => (bool) $this->resolveUserField($uid, 'field_is_company'),
            'company_name' => $this->resolveUserField($uid, 'field_company_name'),
            'vat_number'   => $this->resolveUserField($uid, 'field_vat_number'),
        ];

        try {
            $this->enrollmentService->enroll($userData, [$session_id], [$session_id => $session]);
            $this->messenger()->addStatus($this->t(
                'Je bent nu ingeschreven voor sessie: @title',
                ['@title' => $session['title'] ?? $session_id]
            ));
        } catch (\Throwable $e) {
            \Drupal::logger('session_enrollment')->error('Inschrijving mislukt voor @id: @error', [
                '@id'    => $session_id,
                '@error' => $e->getMessage(),
            ]);
            $this->messenger()->addError($this->t('Inschrijving mislukt: @error', ['@error' => $e->getMessage()]));
        }

        return new RedirectResponse('/sessions/enroll');
    }

    private function resolveSession(string $sessionId): ?array
    {
        $sessions = \Drupal::state()->get('planning.sessions', []);
        foreach ($sessions as $session) {
            if (isset($session['session_id']) && $session['session_id'] === $sessionId) {
                return $session;
            }
        }
        return null;
    }

    private function resolveUserField(int $uid, string $fieldName): string
    {
        try {
            $user = $this->entityTypeManager()->getStorage('user')->load($uid);
            if ($user && $user->hasField($fieldName)) {
                return (string) ($user->get($fieldName)->value ?? '');
            }
        } catch (\Throwable) {}
        return '';
    }
}
