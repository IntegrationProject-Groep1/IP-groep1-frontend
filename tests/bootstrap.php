<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: load Composer autoloader and define minimal Drupal/PSR stubs
 * so that unit tests can run without a full Drupal installation.
 */

namespace {
    require_once __DIR__ . '/../vendor/autoload.php';

    // Redirect error_log() output to the null device so that production-code
    // calls (e.g. ReceiverLogTrait::logReceiverSuccess) don't produce output
    // that triggers PHPUnit's beStrictAboutOutputDuringTests warnings.
    ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null');
}

// ─── PSR-3 Logger ────────────────────────────────────────────────────────

namespace Psr\Log {
    if (!interface_exists('Psr\Log\LoggerInterface', false)) {
        interface LoggerInterface
        {
            public function emergency(string|\Stringable $message, array $context = []): void;
            public function alert(string|\Stringable $message, array $context = []): void;
            public function critical(string|\Stringable $message, array $context = []): void;
            public function error(string|\Stringable $message, array $context = []): void;
            public function warning(string|\Stringable $message, array $context = []): void;
            public function notice(string|\Stringable $message, array $context = []): void;
            public function info(string|\Stringable $message, array $context = []): void;
            public function debug(string|\Stringable $message, array $context = []): void;
            public function log($level, string|\Stringable $message, array $context = []): void;
        }
    }
}

// ─── Drupal Core Logger ──────────────────────────────────────────────────────

namespace Drupal\Core\Logger {
    if (!interface_exists('Drupal\Core\Logger\LoggerChannelInterface', false)) {
        interface LoggerChannelInterface extends \Psr\Log\LoggerInterface {}
    }

    if (!interface_exists('Drupal\Core\Logger\LoggerChannelFactoryInterface', false)) {
        interface LoggerChannelFactoryInterface
        {
            public function get(string $channel): LoggerChannelInterface;
        }
    }
}

// ─── Drupal Core State ───────────────────────────────────────────────────────

namespace Drupal\Core\State {
    if (!interface_exists('Drupal\Core\State\StateInterface', false)) {
        interface StateInterface
        {
            public function get(string $key, mixed $default = null): mixed;
            public function set(string $key, mixed $value): void;
            public function delete(string $key): void;
        }
    }
}

// ─── Drupal Core Entity ──────────────────────────────────────────────────────

namespace Drupal\Core\Entity {
    if (!interface_exists('Drupal\Core\Entity\EntityStorageInterface', false)) {
        interface EntityStorageInterface
        {
            public function loadByProperties(array $values = []): array;
            public function load(mixed $id): mixed;
        }
    }
    if (!interface_exists('Drupal\Core\Entity\EntityTypeManagerInterface', false)) {
        interface EntityTypeManagerInterface
        {
            public function getStorage(string $entity_type_id): EntityStorageInterface;
        }
    }
    if (!interface_exists('Drupal\Core\Entity\EntityInterface', false)) {
        interface EntityInterface {}
    }
}

// ─── Test Stubs (DB query builder, user.data service, null logger) ───────────

namespace Tests\Stubs {

    /**
     * No-op logger — satisfies any call to Drupal::logger()->error() etc.
     */
    class NullLogger
    {
        public function emergency(string|\Stringable $msg, array $ctx = []): void {}
        public function alert(string|\Stringable $msg, array $ctx = []): void {}
        public function critical(string|\Stringable $msg, array $ctx = []): void {}
        public function error(string|\Stringable $msg, array $ctx = []): void {}
        public function warning(string|\Stringable $msg, array $ctx = []): void {}
        public function notice(string|\Stringable $msg, array $ctx = []): void {}
        public function info(string|\Stringable $msg, array $ctx = []): void {}
        public function debug(string|\Stringable $msg, array $ctx = []): void {}
        public function log(mixed $level, string|\Stringable $msg, array $ctx = []): void {}
    }

    /**
     * Fluent stub for \Drupal::database()->select()->fields()->condition()->execute()->fetchAll().
     * Constructed with the rows that fetchAll() should return.
     */
    class DatabaseQueryBuilderStub
    {
        private array $rows;

        public function __construct(array $rows = [])
        {
            $this->rows = $rows;
        }

        public function select(string $table, string $alias): static { return $this; }
        public function fields(string $alias, array $fields): static { return $this; }
        public function condition(string $field, mixed $value): static { return $this; }
        public function execute(): static { return $this; }

        public function fetchAll(): array
        {
            return $this->rows;
        }
    }

    /**
     * In-memory stub for Drupal's user.data service.
     * Tracks set() calls so tests can assert stored values.
     */
    class UserDataServiceStub
    {
        public array $stored = [];

        public function set(string $module, int $uid, string $key, mixed $value): void
        {
            $this->stored["{$module}:{$uid}:{$key}"] = $value;
        }

        public function get(string $module, int $uid, string $key): mixed
        {
            return $this->stored["{$module}:{$uid}:{$key}"] ?? null;
        }
    }
}

// ─── Drupal Static Facade ─────────────────────────────────────────────────────
//
// Provides database(), service(), and logger() as no-op or configurable stubs.
// Tests call Drupal::setupDatabase() / Drupal::setupService() in setUp() and
// Drupal::resetTestStubs() in tearDown().

namespace {
    if (!class_exists('Drupal', false)) {
        class Drupal
        {
            private static ?object $_database = null;
            /** @var array<string, object> */
            private static array $_services = [];

            public static function setupDatabase(object $db): void
            {
                self::$_database = $db;
            }

            public static function setupService(string $name, object $svc): void
            {
                self::$_services[$name] = $svc;
            }

            public static function resetTestStubs(): void
            {
                self::$_database = null;
                self::$_services = [];
            }

            public static function database(): object
            {
                if (self::$_database === null) {
                    throw new \RuntimeException('Drupal::database() stub not configured — call Drupal::setupDatabase() in setUp()');
                }
                return self::$_database;
            }

            public static function service(string $name): object
            {
                if (!isset(self::$_services[$name])) {
                    // Throw so callers that don't guard with try/catch get a clear error.
                    // ReceiverLogTrait::sendToMonitoring() wraps this in try/catch, so it's fine.
                    throw new \RuntimeException("Drupal::service(\"{$name}\") stub not configured");
                }
                return self::$_services[$name];
            }

            public static function logger(string $channel = 'default'): \Tests\Stubs\NullLogger
            {
                return new \Tests\Stubs\NullLogger();
            }
        }
    }
}

// ─── Drupal ControllerBase stub ───────────────────────────────────────────────
//
// Minimal stub so QrCodeController (which extends ControllerBase) can be
// instantiated in unit tests without a full Drupal bootstrap.

namespace Drupal\Core\Controller {
    if (!class_exists('Drupal\Core\Controller\ControllerBase', false)) {
        abstract class ControllerBase
        {
            private int $_mockUid = 1;

            public function setMockCurrentUid(int $uid): void
            {
                $this->_mockUid = $uid;
            }

            protected function currentUser(): object
            {
                $uid = $this->_mockUid;
                return new class($uid) {
                    public function __construct(private int $uid) {}
                    public function id(): int { return $this->uid; }
                };
            }

            protected function t(string $string, array $args = []): string
            {
                foreach ($args as $placeholder => $value) {
                    $string = str_replace((string) $placeholder, (string) $value, $string);
                }
                return $string;
            }
        }
    }
}
