<?php

declare(strict_types=1);

namespace Drupal\company_invite\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\rabbitmq_sender\IdentityServiceClient;
use Drupal\rabbitmq_sender\UserCreatedSender;

/**
 * Handles invite token creation, CRM pre-registration and email dispatch.
 *
 * Flow:
 *   1. Caller provides the invitee email and the inviting user's Drupal UID.
 *   2. This service resolves the inviter's master_uuid (used as company reference).
 *   3. Calls IdentityServiceClient to create/retrieve the invitee's master_uuid so
 *      CRM will recognise the user when they complete registration later.
 *   4. Publishes a user_created event to CRM with company_id = inviter master_uuid.
 *   5. Persists an invite token in the DB and sends the invite email.
 */
class InviteService
{
    private const TOKEN_TTL_SECONDS = 7 * 24 * 3600; // 7 days
    private const TABLE             = 'company_invite_tokens';

    public function __construct(
        private readonly Connection $database,
        private readonly LoggerChannelFactoryInterface $loggerFactory,
        private readonly MailManagerInterface $mailManager,
        private readonly ?IdentityServiceClient $identityClient = null,
        private readonly ?UserCreatedSender $userCreatedSender = null,
    ) {}

    /**
     * Sends a company invite for $inviteeEmail on behalf of the Drupal user $inviterUid.
     *
     * @throws \InvalidArgumentException When the inviter has no stored master_uuid (not a company admin).
     * @throws \InvalidArgumentException When the invitee email is invalid.
     */
    public function sendInvite(string $inviteeEmail, int $inviterUid): void
    {
        $logger = $this->loggerFactory->get('company_invite');

        $inviteeEmail = strtolower(trim($inviteeEmail));
        if (filter_var($inviteeEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('The provided email address is not valid.');
        }

        // Use master_uuid as company reference; fall back to Drupal UID so local
        // testing works without a live Identity Service.
        $inviterUuid = $this->getInviterMasterUuid($inviterUid) ?: 'uid-' . $inviterUid;

        // Prevent duplicate active invites for the same email + company.
        if ($this->hasPendingInvite($inviteeEmail, $inviterUuid)) {
            throw new \InvalidArgumentException('An active invite for this email address already exists.');
        }

        // Step 1: ensure the invitee has a master_uuid in the Identity Service so
        // that when they later register the identity service returns the same UUID
        // and CRM recognises them as already linked to this company.
        $inviteeMasterUuid = $this->resolveInviteeMasterUuid($inviteeEmail, $logger);

        // Step 2: notify CRM that this user is pre-registered and belongs to the company.
        if ($inviteeMasterUuid !== '') {
            $this->notifyCrm($inviteeMasterUuid, $inviteeEmail, $inviterUuid, $logger);
        }

        // Step 3: create invite token and persist it.
        $token = $this->generateToken();
        $now   = time();
        $this->database->insert(self::TABLE)->fields([
            'token'               => $token,
            'email'               => $inviteeEmail,
            'business_owner_uuid' => $inviterUuid,
            'created'             => $now,
            'expires'             => $now + self::TOKEN_TTL_SECONDS,
            'used'                => 0,
        ])->execute();

        // Step 4: send invite email.
        $this->sendEmail($inviteeEmail, $token, $inviterUid, $logger);

        $logger->info('Invite sent to @email by uid @uid.', [
            '@email' => $inviteeEmail,
            '@uid'   => $inviterUid,
        ]);
    }

    /**
     * Validates and retrieves invite data for a given token string.
     *
     * @return array{email: string, business_owner_uuid: string}
     * @throws \InvalidArgumentException When the token is unknown, expired or already used.
     */
    public function consumeToken(string $token): array
    {
        $record = $this->database->select(self::TABLE, 'i')
            ->fields('i', ['email', 'business_owner_uuid', 'expires', 'used'])
            ->condition('token', $token)
            ->execute()
            ->fetchAssoc();

        if ($record === false) {
            throw new \InvalidArgumentException('This invite link is not valid.');
        }

        if ((int) $record['used'] === 1) {
            throw new \InvalidArgumentException('This invite link has already been used.');
        }

        if (time() > (int) $record['expires']) {
            throw new \InvalidArgumentException('This invite link has expired. Please ask for a new one.');
        }

        return [
            'email'               => (string) $record['email'],
            'business_owner_uuid' => (string) $record['business_owner_uuid'],
        ];
    }

    /**
     * Looks up the email for a token without consuming it (used for form pre-fill).
     *
     * Returns null when the token is unknown, expired or already used.
     */
    public function getEmailForToken(string $token): ?string
    {
        try {
            $data = $this->consumeToken($token);
            return $data['email'];
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Marks a token as used after the invited user completes registration.
     */
    public function markTokenUsed(string $token): void
    {
        $this->database->update(self::TABLE)
            ->fields(['used' => 1])
            ->condition('token', $token)
            ->execute();
    }

    // ── private helpers ────────────────────────────────────────────────────────

    private function getInviterMasterUuid(int $uid): string
    {
        if (!\Drupal::hasContainer()) {
            return '';
        }

        $uuid = \Drupal::service('user.data')->get('registration_form', $uid, 'master_uuid');
        return is_string($uuid) ? $uuid : '';
    }

    private function hasPendingInvite(string $email, string $ownerUuid): bool
    {
        $count = (int) $this->database->select(self::TABLE, 'i')
            ->condition('email', $email)
            ->condition('business_owner_uuid', $ownerUuid)
            ->condition('used', 0)
            ->condition('expires', time(), '>')
            ->countQuery()
            ->execute()
            ->fetchField();

        return $count > 0;
    }

    private function resolveInviteeMasterUuid(string $email, \Drupal\Core\Logger\LoggerChannelInterface $logger): string
    {
        if ($this->identityClient === null) {
            return '';
        }

        try {
            return $this->identityClient->createOrGet($email);
        } catch (\Throwable $e) {
            $logger->warning('Identity Service call failed for invitee @email: @msg', [
                '@email' => $email,
                '@msg'   => $e->getMessage(),
            ]);
            return '';
        }
    }

    private function notifyCrm(
        string $inviteeMasterUuid,
        string $inviteeEmail,
        string $inviterUuid,
        \Drupal\Core\Logger\LoggerChannelInterface $logger,
    ): void {
        if ($this->userCreatedSender === null) {
            return;
        }

        try {
            $this->userCreatedSender->send([
                'identity_uuid' => $inviteeMasterUuid,
                'email'         => $inviteeEmail,
                'first_name'    => '',
                'last_name'     => '',
                'date_of_birth' => '',
                'is_company'    => true,
                'company_id'    => $inviterUuid,
            ]);
            $logger->info('CRM pre-registration sent for invited @email.', ['@email' => $inviteeEmail]);
        } catch (\Throwable $e) {
            // Non-fatal: the invited user can still register; CRM will link them on full registration.
            $logger->warning('CRM pre-registration failed for @email: @msg', [
                '@email' => $inviteeEmail,
                '@msg'   => $e->getMessage(),
            ]);
        }
    }

    private function sendEmail(
        string $inviteeEmail,
        string $token,
        int $inviterUid,
        \Drupal\Core\Logger\LoggerChannelInterface $logger,
    ): void {
        $inviteUrl = Url::fromRoute('company_invite.register_invited', ['token' => $token], ['absolute' => true])->toString();

        $inviterName = '';
        $companyName = '';
        if (\Drupal::hasContainer()) {
            $inviter = \Drupal\user\Entity\User::load($inviterUid);
            if ($inviter) {
                $first = $inviter->hasField('field_first_name') ? (string) $inviter->get('field_first_name')->value : '';
                $last  = $inviter->hasField('field_last_name')  ? (string) $inviter->get('field_last_name')->value  : '';
                $inviterName = trim($first . ' ' . $last) ?: $inviter->getDisplayName();
            }
        }

        $result = $this->mailManager->mail(
            'company_invite',
            'company_invite',
            $inviteeEmail,
            \Drupal::languageManager()->getDefaultLanguage()->getId(),
            [
                'invite_link'  => $inviteUrl,
                'inviter_name' => $inviterName,
                'company_name' => $companyName,
            ],
        );

        if (!($result['result'] ?? false)) {
            $logger->error('Invite email could not be sent to @email.', ['@email' => $inviteeEmail]);
        }
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
