<?php

declare(strict_types=1);

namespace Drupal\registration_form\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rabbitmq_sender\CompanyInvitationSender;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Handles company invitation payload preparation and dispatch.
 */
class CompanyInvitationService
{
    private const IS_COMPANY_FIELD = 'field_is_company';
    private const COMPANY_ID_FIELD = 'field_company_id';
    private const COMPANY_NAME_FIELD = 'field_company_name';

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly AccountProxyInterface $currentUser,
        private readonly CompanyInvitationSender $companyInvitationSender,
    ) {}

    /**
     * Sends invitation messages to CRM for all provided email addresses.
     */
    public function sendInvitations(array $emails): int
    {
        $companyUser = $this->loadCurrentCompanyUser();
        $sent = 0;

        foreach ($emails as $email) {
            $this->companyInvitationSender->send([
                'invitee_email' => $email,
                'inviter_user_id' => (string) $companyUser->id(),
                'company_id' => $this->resolveCompanyId($companyUser),
                'company_name' => $this->resolveCompanyName($companyUser),
            ]);
            $sent++;
        }

        return $sent;
    }

    public function currentUserIsCompany(): bool
    {
        if ($this->currentUser->isAnonymous()) {
            return false;
        }

        $user = $this->loadCurrentUserEntity();
        if (!$user instanceof UserInterface) {
            return false;
        }

        if (!$user->hasField(self::IS_COMPANY_FIELD)) {
            return false;
        }

        return (bool) $user->get(self::IS_COMPANY_FIELD)->value;
    }

    public function getCurrentCompanyName(): string
    {
        $user = $this->loadCurrentUserEntity();
        if (!$user instanceof UserInterface) {
            return '';
        }

        if ($user->hasField(self::COMPANY_NAME_FIELD)) {
            $companyName = trim((string) $user->get(self::COMPANY_NAME_FIELD)->value);
            if ($companyName !== '') {
                return $companyName;
            }
        }

        return $user->getDisplayName();
    }

    private function loadCurrentCompanyUser(): UserInterface
    {
        if (!$this->currentUserIsCompany()) {
            throw new AccessDeniedHttpException('Only company accounts can use the invitation dashboard.');
        }

        $user = $this->loadCurrentUserEntity();
        if (!$user instanceof UserInterface) {
            throw new AccessDeniedHttpException('Current user could not be loaded.');
        }

        return $user;
    }

    private function loadCurrentUserEntity(): ?UserInterface
    {
        $id = (int) $this->currentUser->id();
        if ($id <= 0) {
            return null;
        }

        $entity = $this->entityTypeManager->getStorage('user')->load($id);
        return $entity instanceof UserInterface ? $entity : null;
    }

    private function resolveCompanyId(UserInterface $user): string
    {
        if ($user->hasField(self::COMPANY_ID_FIELD) && (string) $user->get(self::COMPANY_ID_FIELD)->value !== '') {
            return (string) $user->get(self::COMPANY_ID_FIELD)->value;
        }

        return (string) $user->id();
    }

    private function resolveCompanyName(UserInterface $user): string
    {
        if ($user->hasField(self::COMPANY_NAME_FIELD) && (string) $user->get(self::COMPANY_NAME_FIELD)->value !== '') {
            return (string) $user->get(self::COMPANY_NAME_FIELD)->value;
        }

        return $user->getDisplayName();
    }
}
