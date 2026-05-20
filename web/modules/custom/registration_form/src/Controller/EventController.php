<?php

namespace Drupal\registration_form\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;
use Drupal\user\Entity\User;

class EventController extends ControllerBase {

  public function endEvent(Request $request) {
    $event_id = $request->get('event_id');
    $password = $request->get('password');
    $current_user = \Drupal::currentUser();

    // Alleen admins
    if (!$current_user->hasPermission('administer site configuration')) {
      return new JsonResponse(['success' => false], 403);
    }

    // Wachtwoordcontrole
    $user = User::load($current_user->id());
    if (!\Drupal::service('password')->check($password, $user->getPassword())) {
      return new JsonResponse(['success' => false]);
    }

    // Contract-conform event_ended bericht sturen
    /** @var \Drupal\rabbitmq_sender\EventEndedSender $eventEndedSender */
    $eventEndedSender = \Drupal::service('rabbitmq_sender.event_ended_sender');
    $eventEndedSender->send([
      'event_id' => $event_id,
      // Voeg hier andere verplichte velden toe volgens contract
    ]);

    return new JsonResponse(['success' => true]);
  }
}