<?php

declare(strict_types=1);

namespace Drupal\company_invite\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\company_invite\Form\CompanyInviteForm;
use Drupal\company_invite\Service\InviteService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the invite form + invite status overview on one page.
 */
class InviteDashboardController extends ControllerBase
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

    public function page(): array
    {
        // The CompanyInviteForm template (form--company-invite-form.html.twig)
        // renders the full page — header, stats, invite form, and invite table.
        // Invite data is attached to the form by CompanyInviteForm::buildForm().
        return [
            '#cache' => ['max-age' => 0],
            'form'   => $this->formBuilder()->getForm(CompanyInviteForm::class),
        ];
    }
}
