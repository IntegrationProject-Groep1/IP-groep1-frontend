<?php

declare(strict_types=1);

namespace Drupal\company_invite\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
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
        private readonly FormBuilderInterface $formBuilder,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('company_invite.invite_service'),
            $container->get('form_builder'),
        );
    }

    public function page(): array
    {
        $uid         = (int) $this->currentUser()->id();
        $ownerUuid   = \Drupal::service('user.data')->get('registration_form', $uid, 'master_uuid') ?: 'uid-' . $uid;
        $invites     = $this->inviteService->getInvitesForOwner($ownerUuid);
        $now         = time();

        $rows = [];
        foreach ($invites as $invite) {
            $expired = (int) $invite['expires'] < $now;
            $used    = (int) $invite['used'] === 1;

            if ($used) {
                $status = $this->t('Registered');
                $badge  = 'color:#16a34a;background:#dcfce7;';
            } elseif ($expired) {
                $status = $this->t('Expired');
                $badge  = 'color:#9ca3af;background:#f3f4f6;';
            } else {
                $status = $this->t('Pending');
                $badge  = 'color:#d97706;background:#fef9c3;';
            }

            $rows[] = [
                'data' => [
                    ['data' => $invite['email']],
                    ['data' => date('d/m/Y H:i', (int) $invite['created'])],
                    ['data' => date('d/m/Y', (int) $invite['expires'])],
                    [
                        'data' => [
                            '#markup' => '<span style="' . $badge . 'padding:2px 10px;border-radius:9999px;font-size:0.75rem;font-weight:600;">'
                                . $status . '</span>',
                        ],
                    ],
                ],
            ];
        }

        $table = [
            '#type'    => 'table',
            '#header'  => [
                $this->t('Email'),
                $this->t('Invited on'),
                $this->t('Expires'),
                $this->t('Status'),
            ],
            '#rows'    => $rows,
            '#empty'   => $this->t('No invitations sent yet.'),
            '#attributes' => ['style' => 'width:100%;border-collapse:collapse;'],
        ];

        return [
            'form' => $this->formBuilder->getForm(CompanyInviteForm::class),
            'overview_title' => [
                '#markup' => '<h2 style="margin-top:2rem;margin-bottom:1rem;font-size:1.125rem;font-weight:600;">'
                    . $this->t('Sent invitations') . '</h2>',
            ],
            'table' => $table,
        ];
    }
}
