<?php

declare(strict_types=1);

namespace Drupal\registration_form\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\rabbitmq_sender\IdentityServiceClient;
use Drupal\rabbitmq_sender\NewRegistrationSender;
use Drupal\rabbitmq_sender\UserCreatedSender;
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

        $logger->info('Registration attempt for @email on session @session', [
            '@email'   => $data['email'] ?? 'unknown',
            '@session' => $data['session_id'] ?? 'unknown',
        ]);

        $user = $this->createLocalUser($data);

        // Notify CRM that a new user account was created (non-fatal).
        if ($this->userCreatedSender !== null) {
            try {
                $this->userCreatedSender->send([
                    'user_id'    => (string) $user->id(),
                    'email'      => (string) $data['email'],
                    'first_name' => (string) ($data['first_name'] ?? ''),
                    'last_name'  => (string) ($data['last_name'] ?? ''),
                    'is_company' => (bool) ($data['is_company'] ?? false),
                    'vat_number' => (string) ($data['vat_number'] ?? ''),
                ]);
                $logger->info('user_created verstuurd naar CRM voor @email.', ['@email' => $data['email']]);
            } catch (\Throwable $e) {
                $logger->error('user_created mislukt voor @email: @message', [
                    '@email'   => $data['email'],
                    '@message' => $e->getMessage(),
                ]);
            }
        }

        // Retrieve the master UUID from the Identity Service so CRM can correlate
        // this user across all downstream systems. Non-fatal: if Identity Service
        // is unavailable the registration still succeeds locally.
        $masterUuid = $this->resolveMasterUuid((string) $data['email'], $logger);
        if ($masterUuid !== '') {
            $this->storeMasterUuidOnUser((int) $user->id(), $masterUuid);
            $data['master_uuid'] = $masterUuid;
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
        } else {
            $logger->warning('Registration stored locally for @email, but CRM synchronization is pending.', [
                '@email' => $data['email'],
            ]);
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

}
