<?php

declare(strict_types=1);

namespace Drupal\session_management\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use PhpAmqpLib\Message\AMQPMessage;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Confirmation form to remove a person from a session.
 *
 * On confirm:
 *   1. Removes the enrollee from planning.enrollees Drupal State.
 *   2. Sends cancel_registration XML to crm.incoming (contract §5.6).
 *   3. Redirects back to the enrollees list.
 */
class RemoveEnrolleeForm extends ConfirmFormBase
{
    private string $sessionId = '';
    private string $userUuid  = '';

    public function getFormId(): string
    {
        return 'remove_enrollee_form';
    }

    public function getQuestion(): \Drupal\Core\StringTranslation\TranslatableMarkup
    {
        return $this->t('Are you sure you want to remove this person from the session?');
    }

    public function getDescription(): \Drupal\Core\StringTranslation\TranslatableMarkup
    {
        return $this->t('This will send a cancellation to CRM via RabbitMQ. This action cannot be undone.');
    }

    public function getCancelUrl(): Url
    {
        return Url::fromRoute('session_management.session_enrollees', [
            'session_id' => $this->sessionId,
        ]);
    }

    public function buildForm(
        array $form,
        FormStateInterface $form_state,
        string $session_id = '',
        string $user_uuid  = '',
    ): array {
        $this->sessionId = $session_id;
        $this->userUuid  = $user_uuid;

        return parent::buildForm($form, $form_state);
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        // Step 1: Remove from planning.enrollees state.
        $allEnrollees = \Drupal::state()->get('planning.enrollees', []);
        $filtered     = array_values(array_filter(
            $allEnrollees,
            fn(array $e) => !(
                isset($e['identity_uuid'], $e['session_id'])
                && (string) $e['identity_uuid'] === $this->userUuid
                && (string) $e['session_id']    === $this->sessionId
            ),
        ));
        \Drupal::state()->set('planning.enrollees', $filtered);

        // Step 2: Build cancel_registration XML (contract §5.6).
        $messageId = $this->generateUuid();
        $xml       = $this->buildCancelRegistrationXml($messageId);

        // Step 3: Publish to crm.incoming.
        try {
            $client = new RabbitMQClient(
                (string) (getenv('RABBITMQ_HOST')  ?: 'rabbitmq_broker'),
                (int)    (getenv('RABBITMQ_PORT')  ?: 5672),
                (string) (getenv('RABBITMQ_USER')  ?: 'guest'),
                (string) (getenv('RABBITMQ_PASS')  ?: 'guest'),
                (string) (getenv('RABBITMQ_VHOST') ?: '/'),
            );

            $channel = $client->getChannel();
            $channel->queue_declare('crm.incoming', false, true, false, false);
            $msg = new AMQPMessage($xml, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
            $channel->basic_publish($msg, '', 'crm.incoming');

            $this->messenger()->addStatus(
                $this->t('The enrollee has been removed and CRM has been notified.')
            );
        } catch (\Throwable $e) {
            \Drupal::logger('session_management')->error(
                'cancel_registration RabbitMQ failure for identity @uuid / session @sid: @message',
                ['@uuid' => $this->userUuid, '@sid' => $this->sessionId, '@message' => $e->getMessage()]
            );
            $this->messenger()->addWarning(
                $this->t('Enrollee removed locally, but the CRM cancellation message could not be sent. Please retry or notify the administrator.')
            );
        }

        // Step 4: Redirect back to enrollees list.
        $form_state->setRedirectUrl($this->getCancelUrl());
    }

    private function buildCancelRegistrationXml(string $messageId): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $message = $dom->createElement('message');
        $dom->appendChild($message);

        $header = $dom->createElement('header');
        $message->appendChild($header);

        $header->appendChild($dom->createElement('source',      'frontend'));
        $header->appendChild($dom->createElement('message_id',  htmlspecialchars($messageId, ENT_XML1)));
        $header->appendChild($dom->createElement('type',        'cancel_registration'));
        $header->appendChild($dom->createElement('version',     '2.0'));
        $header->appendChild($dom->createElement('timestamp',   date('c')));

        $body = $dom->createElement('body');
        $message->appendChild($body);

        $body->appendChild($dom->createElement('identity_uuid', htmlspecialchars($this->userUuid,  ENT_XML1)));
        $body->appendChild($dom->createElement('session_id',    htmlspecialchars($this->sessionId, ENT_XML1)));
        $body->appendChild($dom->createElement('reason',        'Removed by admin'));

        return $dom->saveXML();
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
