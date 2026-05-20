<?php

declare(strict_types=1);

namespace Drupal\session_management\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Manages sessions in MariaDB and optionally notifies Planning via RabbitMQ
 * for Outlook calendar sync (Graph API).
 */
class SessionService
{
    public function __construct(
        private readonly LoggerChannelFactoryInterface $loggerFactory,
        private readonly UuidInterface $uuid,
    ) {}

    /**
     * Creates a session directly in MariaDB and notifies planning (optional).
     *
     * @throws \RuntimeException When the DB write fails.
     */
    public function createSession(array $data): string
    {
        $logger    = $this->loggerFactory->get('session_management');
        $sessionId = $this->uuid->generate();

        $start = is_object($data['start_datetime'] ?? null)
            ? $data['start_datetime']->format('c')
            : (string) ($data['start_datetime'] ?? '');
        $end = is_object($data['end_datetime'] ?? null)
            ? $data['end_datetime']->format('c')
            : (string) ($data['end_datetime'] ?? '');

        $db = Database::getConnection('default', 'planning');
        $db->insert('planning_sessions')->fields([
            'session_id'     => $sessionId,
            'title'          => (string) ($data['title'] ?? ''),
            'start_datetime' => $start,
            'end_datetime'   => $end,
            'location'       => (string) ($data['location'] ?? ''),
            'session_type'   => (string) ($data['session_type'] ?? 'keynote'),
            'status'         => (string) ($data['status'] ?? 'published'),
            'max_attendees'  => (int) ($data['max_attendees'] ?? 0),
            'price'          => isset($data['price']) && $data['price'] !== '' ? (float) $data['price'] : null,
            'is_deleted'     => 0,
        ])->execute();

        $logger->info('Session "@title" created in MariaDB (id: @id)', [
            '@title' => $data['title'] ?? '',
            '@id'    => $sessionId,
        ]);

        // Notify planning for Outlook Graph API (non-blocking).
        $this->notifyPlanningCreate(array_merge($data, [
            'session_id'     => $sessionId,
            'start_datetime' => $start,
            'end_datetime'   => $end,
        ]), $logger);

        return $sessionId;
    }

    /**
     * Updates a session in MariaDB and notifies planning (optional).
     */
    public function updateSession(string $sessionId, array $data): void
    {
        $logger = $this->loggerFactory->get('session_management');

        $fields = array_filter([
            'title'          => $data['title'] ?? null,
            'start_datetime' => $data['start_datetime'] ?? null,
            'end_datetime'   => $data['end_datetime'] ?? null,
            'location'       => $data['location'] ?? null,
            'session_type'   => $data['session_type'] ?? null,
            'status'         => $data['status'] ?? null,
            'max_attendees'  => isset($data['max_attendees']) ? (int) $data['max_attendees'] : null,
            'price'          => isset($data['price']) && $data['price'] !== '' ? (float) $data['price'] : null,
        ], fn($v) => $v !== null);

        if (!empty($fields)) {
            Database::getConnection('default', 'planning')
                ->update('planning_sessions')
                ->fields($fields)
                ->condition('session_id', $sessionId)
                ->execute();
        }

        $logger->info('Session @id updated in MariaDB.', ['@id' => $sessionId]);

        $this->notifyPlanningUpdate(array_merge($data, ['session_id' => $sessionId]), $logger);
    }

    /**
     * Marks a session as deleted in MariaDB and notifies planning (optional).
     */
    public function deleteSession(string $sessionId, string $reason = ''): void
    {
        $logger = $this->loggerFactory->get('session_management');

        Database::getConnection('default', 'planning')
            ->update('planning_sessions')
            ->fields(['is_deleted' => 1])
            ->condition('session_id', $sessionId)
            ->execute();

        $logger->info('Session @id marked deleted in MariaDB.', ['@id' => $sessionId]);

        $this->notifyPlanningDelete($sessionId, $reason, $logger);
    }

    /**
     * Load a single session from MariaDB.
     */
    public function loadSession(string $sessionId): ?array
    {
        $row = Database::getConnection('default', 'planning')->query(
            "SELECT * FROM planning_sessions WHERE session_id = :id AND is_deleted = 0",
            [':id' => $sessionId]
        )->fetchAssoc();
        return $row ?: null;
    }

    /**
     * List all non-deleted sessions from MariaDB.
     *
     * @return list<array<string,mixed>>
     */
    public function listSessions(): array
    {
        return Database::getConnection('default', 'planning')->query(
            "SELECT * FROM planning_sessions WHERE is_deleted = 0 ORDER BY start_datetime"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function notifyPlanningCreate(array $data, $logger): void
    {
        try {
            (new \Drupal\rabbitmq_sender\SessionCreateRequestSender())->send($data);
        } catch (\Throwable $e) {
            $logger->warning('Planning Graph sync (create) failed — session still saved: @e', ['@e' => $e->getMessage()]);
        }
    }

    private function notifyPlanningUpdate(array $data, $logger): void
    {
        try {
            (new \Drupal\rabbitmq_sender\SessionUpdateRequestSender())->send($data);
        } catch (\Throwable $e) {
            $logger->warning('Planning Graph sync (update) failed — session still saved: @e', ['@e' => $e->getMessage()]);
        }
    }

    private function notifyPlanningDelete(string $sessionId, string $reason, $logger): void
    {
        try {
            $payload = ['session_id' => $sessionId];
            if ($reason !== '') {
                $payload['reason'] = $reason;
            }
            (new \Drupal\rabbitmq_sender\SessionDeleteRequestSender())->send($payload);
        } catch (\Throwable $e) {
            $logger->warning('Planning Graph sync (delete) failed — session still deleted: @e', ['@e' => $e->getMessage()]);
        }
    }
}
