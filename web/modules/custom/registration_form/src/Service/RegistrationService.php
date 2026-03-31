<?php

declare(strict_types=1);

namespace Drupal\registration_form\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\rabbitmq_sender\NewRegistrationSender;
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

        $payloadBuilder = $this->crmPayloadBuilder ?? new RegistrationCrmPayloadBuilder();
        $payload = $payloadBuilder->build($data, (string) $user->id());

        $rabbitSent = false;

        try {
            $this->registrationSender->send($payload);
            $rabbitSent = true;
        } catch (\Throwable $e) {
            $logger->error('RabbitMQ publish failed to host @host: @message', [
                '@host' => $this->resolveRabbitMqHost(),
                '@message' => $e->getMessage(),
            ]);
            // Keep local account so the user can still authenticate even if CRM is temporarily unavailable.
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

}
