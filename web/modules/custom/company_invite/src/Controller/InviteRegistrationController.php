<?php

declare(strict_types=1);

namespace Drupal\company_invite\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\company_invite\Service\InviteService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Handles the invite landing URL (/register/invite/{token}).
 *
 * Validates the token and redirects to the standard registration form
 * with the invite_token query parameter so the form can pre-fill the email.
 */
class InviteRegistrationController extends ControllerBase
{
    public function __construct(
        private readonly InviteService $inviteService,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('company_invite.invite_service'),
        );
    }

    public function page(string $token): RedirectResponse
    {
        try {
            // Validate without consuming — the token is consumed after successful registration.
            $this->inviteService->consumeToken($token);
        } catch (\InvalidArgumentException $e) {
            $this->messenger()->addError($e->getMessage());
            return new RedirectResponse(Url::fromRoute('registration_form.register')->toString());
        }

        // Redirect to the registration form, passing the token as a query param.
        $registerUrl = Url::fromRoute('registration_form.register', [], [
            'query' => ['invite_token' => $token],
        ])->toString();

        return new RedirectResponse($registerUrl);
    }
}
