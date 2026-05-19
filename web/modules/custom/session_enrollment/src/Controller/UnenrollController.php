<?php

declare(strict_types=1);

namespace Drupal\session_enrollment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\rabbitmq_sender\UserUnregisteredSender;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Handles session unenrollment: sends user_unregistered to CRM and redirects with feedback.
 */
class UnenrollController extends ControllerBase
{
    public function __construct(
        private readonly UserUnregisteredSender $userUnregisteredSender,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('rabbitmq_sender.user_unregistered_sender'),
        );
    }

    public function unenroll(string $session_id): RedirectResponse
    {
        $currentUser  = $this->currentUser();
        $uid          = (int) $currentUser->id();
        $identityUuid = (string) (\Drupal::service('user.data')->get('registration_form', $uid, 'master_uuid') ?? '');

        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $identityUuid)) {
            $this->messenger()->addError($this->t('Je account is nog niet volledig ingesteld.'));
            return new RedirectResponse('/sessions/my');
        }

        $sessionTitle = $this->resolveSessionTitle($session_id);

        try {
            $this->userUnregisteredSender->send([
                'identity_uuid' => $identityUuid,
                'session_id'    => $session_id,
                'session_title' => $sessionTitle,
            ]);

            $message = $sessionTitle !== ''
                ? $this->t('Je bent uitgeschreven voor sessie: @title', ['@title' => $sessionTitle])
                : $this->t('Je bent uitgeschreven voor de sessie.');
            $this->messenger()->addStatus($message);
        } catch (\Throwable $e) {
            \Drupal::logger('session_enrollment')->error('Uitschrijven mislukt voor sessie @id: @error', [
                '@id'    => $session_id,
                '@error' => $e->getMessage(),
            ]);
            $this->messenger()->addError($this->t('Uitschrijven mislukt: @error', ['@error' => $e->getMessage()]));
        }

        return new RedirectResponse('/sessions/my');
    }

    private function resolveSessionTitle(string $sessionId): string
    {
        $sessions = \Drupal::state()->get('planning.sessions', []);
        foreach ($sessions as $session) {
            if (isset($session['session_id']) && $session['session_id'] === $sessionId) {
                return (string) ($session['title'] ?? '');
            }
        }
        return '';
    }
}
