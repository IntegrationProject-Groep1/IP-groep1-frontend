<?php

declare(strict_types=1);

namespace Drupal\company_invite\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\company_invite\Service\InviteService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Handles deletion of a single invite token.
 */
class InviteDeleteController extends ControllerBase
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

    public function delete(string $token): RedirectResponse
    {
        $uid       = (int) $this->currentUser()->id();
        $ownerUuid = \Drupal::service('user.data')->get('registration_form', $uid, 'master_uuid') ?: 'uid-' . $uid;

        try {
            $this->inviteService->deleteInvite($token, $ownerUuid);
            $this->messenger()->addStatus($this->t('The invitation has been deleted.'));
        } catch (\InvalidArgumentException $e) {
            $this->messenger()->addError($e->getMessage());
        }

        return new RedirectResponse(Url::fromRoute('company_invite.invite_form')->toString());
    }
}
