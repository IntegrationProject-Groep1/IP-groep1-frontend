<?php

declare(strict_types=1);

namespace Drupal\registration_form\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\rabbitmq_sender\IdentityServiceClient;
use Drupal\rabbitmq_sender\NewRegistrationSender;
use Drupal\rabbitmq_sender\UserCreatedSender;
use Drupal\rabbitmq_sender\MonitoringLogSender;
use Drupal\rabbitmq_sender\SendMailingSender;
use Drupal\user\Entity\User;

/**
 * Coordinates local user registration and CRM message publication.
 */
class RegistrationService
{
    private const DEFAULT_FIRST_NAME_FIELD = 'field_first_name';
    private const DEFAULT_LAST_NAME_FIELD = 'field_last_name';
    private const DEFAULT_DATE_OF_BIRTH_FIELD = 'field_date_of_birth';

    public function __construct(
        private readonly LoggerChannelFactoryInterface $loggerFactory,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly NewRegistrationSender $registrationSender,
        private readonly ?RegistrationCrmPayloadBuilder $crmPayloadBuilder = null,
        private readonly ?IdentityServiceClient $identityClient = null,
        private readonly ?UserCreatedSender $userCreatedSender = null,
        private readonly ?MonitoringLogSender $monitoringLogger = null,
        private readonly ?SendMailingSender $mailingSender = null,
    ) {}

    /**
     * Registers a user and sends a new_registration event to CRM via RabbitMQ.
     *
     * @throws \InvalidArgumentException When validation fails.
     */
    public function register(array $data): void
    {
        $logger = $this->loggerFactory->get('registration_form');

        $this->validateRegistrationInput($data);
        $this->assertEmailNotInUse((string) $data['email']);

        $logger->info('Registration attempt for @email', [
            '@email'   => $data['email'] ?? 'unknown',
        ]);

        $user = $this->createLocalUser($data);

        // Retrieve the master UUID from the Identity Service FIRST — contract §5.4 says
        // user_created must be published AFTER the Identity RPC so we can include identity_uuid.
        $masterUuid = $this->resolveMasterUuid((string) $data['email'], $logger);
        if ($masterUuid !== '') {
            $this->storeMasterUuidOnUser((int) $user->id(), $masterUuid);
            $data['master_uuid'] = $masterUuid;
        } elseif ($this->identityClient !== null && $this->mustRequireRabbitMqSync()) {
            // Identity Service is configured but did not return a UUID. Sending a
            // registration to CRM without a real identity_uuid would create corrupt data,
            // so we abort and roll back the local user.
            $user->delete();
            throw new \InvalidArgumentException('Registratie tijdelijk niet beschikbaar omdat de Identity Service niet bereikbaar is. Probeer het over enkele minuten opnieuw.');
        }

        $isCompany = (bool) ($data['is_company'] ?? false);
        $this->storeIsCompanyOnUser((int) $user->id(), $isCompany);
        if ($isCompany) {
            $this->grantCompanyInvitePermission($user);
        }

        // Notify CRM that a new user account was created (non-fatal).
        if ($this->userCreatedSender !== null) {
            try {
                $this->userCreatedSender->send([
                    'identity_uuid' => $masterUuid !== '' ? $masterUuid : (string) $user->id(),
                    'user_id'       => (string) $user->id(),
                    'email'         => (string) $data['email'],
                    'first_name'    => (string) ($data['first_name'] ?? ''),
                    'last_name'     => (string) ($data['last_name'] ?? ''),
                    'date_of_birth' => (string) ($data['date_of_birth'] ?? ''),
                    'is_company'    => (bool) ($data['is_company'] ?? false),
                    'company_name'  => (string) ($data['company_name'] ?? ''),
                    'vat_number'    => (string) ($data['vat_number'] ?? ''),
                ]);
                $logger->info('user_created verstuurd naar CRM voor @email.', ['@email' => $data['email']]);
            } catch (\Throwable $e) {
                $logger->error('user_created mislukt voor @email: @message', [
                    '@email'   => $data['email'],
                    '@message' => $e->getMessage(),
                ]);
            }
        }

        $payloadBuilder = $this->crmPayloadBuilder ?? new RegistrationCrmPayloadBuilder();
        $payload = $payloadBuilder->build($data, (string) $user->id());

        $rabbitSent = false;

        try {
            if ($this->registrationSender !== null) {
                $this->registrationSender->send($payload);
                $rabbitSent = true;
            }
        } catch (\Throwable $e) {
            $logger->error('RabbitMQ publish failed to host @host: @message', [
                '@host' => $this->resolveRabbitMqHost(),
                '@message' => $e->getMessage(),
            ]);

            if ($this->mustRequireRabbitMqSync()) {
                // In strict integration mode we avoid false-positive UI success by rolling back
                // the local user when CRM publication fails.
                $user->delete();
                throw new \InvalidArgumentException('Registration could not be synchronized to CRM. Please try again in a few minutes.');
            }

            // Default behavior: keep local account so the user can still authenticate even if CRM is temporarily unavailable.
        }

        if ($rabbitSent) {
            $logger->info('Registration sent to RabbitMQ for @email.', [
                '@email' => $data['email'],
            ]);

            // Notify Monitoring team of successful registration
            if ($this->monitoringLogger !== null) {
                $this->monitoringLogger->send('info', 'registration', "New user registered: {$data['email']}");
            }

            // ✅ Trigger confirmation email via direct mailing queue
            if ($this->mailingSender !== null) {
                try {
                    $this->mailingSender->send([
                        'campaign_id' => 'registration-drupal',
                        'subject'     => 'Bevestiging van je registratie',
                        'mail_type'   => 'registration_confirmation',
                        'recipients'  => [
                            [
                                'email'         => (string) $data['email'],
                                'identity_uuid' => $masterUuid ?: (string) $user->id(),
                                'first_name'    => (string) ($data['first_name'] ?? ''),
                                'last_name'     => (string) ($data['last_name'] ?? ''),
                            ]
                        ],
                        'template_data' => json_encode([
                            'first_name' => (string) ($data['first_name'] ?? ''),
                            'last_name'  => (string) ($data['last_name'] ?? ''),
                            'date'       => date('d-m-Y'),
                        ]),
                    ]);
                    $logger->info('Registration confirmation email triggered for @email.', ['@email' => $data['email']]);
                } catch (\Throwable $e) {
                    $logger->error('Failed to trigger confirmation email for @email: @message', [
                        '@email'   => $data['email'],
                        '@message' => $e->getMessage(),
                    ]);
                }
            }
        } else {
            $logger->warning('Registration stored locally for @email, but CRM synchronization is pending.', [
                '@email' => $data['email'],
            ]);
        }
    }
}

    private function validateRegistrationInput(array $data): void
    {
        $requiredFields = ['email', 'password', 'first_name', 'last_name', 'date_of_birth'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException($field . ' is required');
            }
        }

        if (filter_var((string) $data['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('email must be a valid email address');
        }

        if (strlen((string) $data['password']) < 8) {
            throw new \InvalidArgumentException('password must be at least 8 characters long');
        }
    }

    private function assertEmailNotInUse(string $email): void
    {
        $storage = $this->entityTypeManager->getStorage('user');
        $existingIds = $storage->getQuery()
            ->accessCheck(false)
            ->condition(
                $storage->getQuery()
                    ->orConditionGroup()
                    ->condition('mail', $email)
                    ->condition('name', $email)
            )
            ->range(0, 1)
            ->execute();

        if (!empty($existingIds)) {
            throw new \InvalidArgumentException('An account with this email address already exists. Please sign in instead.');
        }
    }

    private function createLocalUser(array $data): User
    {
        $user = User::create([
            'name' => (string) $data['email'],
            'mail' => (string) $data['email'],
            'status' => 1,
        ]);

        // Use Drupal's password API on the user entity; hashing is handled safely during save.
        $user->setPassword((string) $data['password']);

        $this->setIfFieldExists($user, $this->resolveUserFieldName('DRUPAL_USER_FIELD_FIRST_NAME', self::DEFAULT_FIRST_NAME_FIELD), (string) $data['first_name']);
        $this->setIfFieldExists($user, $this->resolveUserFieldName('DRUPAL_USER_FIELD_LAST_NAME', self::DEFAULT_LAST_NAME_FIELD), (string) $data['last_name']);
        $this->setIfFieldExists($user, $this->resolveUserFieldName('DRUPAL_USER_FIELD_DATE_OF_BIRTH', self::DEFAULT_DATE_OF_BIRTH_FIELD), (string) $data['date_of_birth']);

        $user->save();

        return $user;
    }

    private function setIfFieldExists(User $user, string $fieldName, string $value): void
    {
        if ($value === '' || !$user->hasField($fieldName)) {
            return;
        }

        $user->set($fieldName, $value);
    }

    private function resolveUserFieldName(string $envName, string $default): string
    {
        $value = getenv($envName);

        if ($value === false || trim($value) === '') {
            return $default;
        }

        return trim((string) $value);
    }

    private function resolveRabbitMqHost(): string
    {
        $value = getenv('RABBITMQ_HOST');

        if ($value === false || trim($value) === '') {
            return 'rabbitmq_broker';
        }

        return trim((string) $value);
    }

    private function mustRequireRabbitMqSync(): bool
    {
        $value = getenv('REGISTRATION_REQUIRE_RABBITMQ_SYNC');

        if ($value === false) {
            return true;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Calls the Identity Service to retrieve (or create) the master UUID for an email.
     * Returns an empty string when the client is not configured or the call fails.
     */
    private function resolveMasterUuid(string $email, \Drupal\Core\Logger\LoggerChannelInterface $logger): string
    {
        if ($this->identityClient === null) {
            return '';
        }

        try {
            return $this->identityClient->createOrGet($email);
        } catch (\Throwable $e) {
            $logger->warning('Identity Service call failed for @email: @message', [
                '@email'   => $email,
                '@message' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Stores master_uuid on the Drupal user via user.data (no schema change needed).
     */
    private function storeMasterUuidOnUser(int $userId, string $masterUuid): void
    {
        if (!class_exists('\Drupal') || !\Drupal::hasContainer()) {
            return;
        }

        \Drupal::service('user.data')->set('registration_form', $userId, 'master_uuid', $masterUuid);
    }

    /**
     * Assigns the company_admin role to the user so the "Invite member" menu
     * link and route are only accessible to company accounts.
     */
    private function grantCompanyInvitePermission(User $user): void
    {
        if (!class_exists('\Drupal') || !\Drupal::hasContainer()) {
            return;
        }

        $roleStorage = $this->entityTypeManager->getStorage('user_role');
        if (!$roleStorage->load('company_admin')) {
            $role = $roleStorage->create(['id' => 'company_admin', 'label' => 'Company admin']);
            $role->grantPermission('access company invite');
            $role->save();
        }

        $user->addRole('company_admin');
        $user->save();
    }

    /**
     * Stores whether the user registered as a company so other modules can check
     * this without loading CRM data (e.g. company_invite checks it for access control).
     */
    private function storeIsCompanyOnUser(int $userId, bool $isCompany): void
    {
        if (!class_exists('\Drupal') || !\Drupal::hasContainer()) {
            return;
        }

        \Drupal::service('user.data')->set('registration_form', $userId, 'is_company', $isCompany);
    }

}
