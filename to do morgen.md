✅ Wat correct is

  ┌───────────────────────────────────┬─────────────────────────────────────────────────┐
  │              Bestand              │                     Status                      │
  ├───────────────────────────────────┼─────────────────────────────────────────────────┤
  │ EventEndedSender.php              │ Correct v2.0 header, geen namespace             │
  ├───────────────────────────────────┼─────────────────────────────────────────────────┤
  │ SessionCreatedReceiver.php        │ Correct (routing key, DLQ, namespace stripping) │
  ├───────────────────────────────────┼─────────────────────────────────────────────────┤
  │ SessionUpdateReceiver.php         │ Correct (luistert op session_updated)           │
  ├───────────────────────────────────┼─────────────────────────────────────────────────┤
  │ xsd/session_created.xsd           │ Aanwezig                                        │
  ├───────────────────────────────────┼─────────────────────────────────────────────────┤
  │ xsd/session_updated.xsd           │ Aanwezig                                        │
  ├───────────────────────────────────┼─────────────────────────────────────────────────┤
  │ xsd/session_deleted.xsd           │ Aanwezig                                        │
  ├───────────────────────────────────┼─────────────────────────────────────────────────┤
  │ xsd/payment_registered.xsd        │ Aanwezig                                        │
  ├───────────────────────────────────┼─────────────────────────────────────────────────┤
  │ xsd/badge_scanned.xsd             │ Aanwezig                                        │
  ├───────────────────────────────────┼─────────────────────────────────────────────────┤
  │ xsd/vat_validation_error.xsd      │ Aanwezig                                        │
  ├───────────────────────────────────┼─────────────────────────────────────────────────┤
  │ xsd/event_ended.xsd               │ Aanwezig                                        │
  ├───────────────────────────────────┼─────────────────────────────────────────────────┤
  │ xsd/calendar_invite_confirmed.xsd │ Aanwezig                                        │
  ├───────────────────────────────────┼─────────────────────────────────────────────────┤
  │ xsd/session_view_response.xsd     │ Aanwezig                                        │
  ├───────────────────────────────────┼─────────────────────────────────────────────────┤
  │ xsd/session_delete_request.xsd    │ Aanwezig                                        │
  ├───────────────────────────────────┼─────────────────────────────────────────────────┤
  │ xsd/session_update_request.xsd    │ Aanwezig                                        │
  └───────────────────────────────────┴─────────────────────────────────────────────────┘

  ---
  ❌ Wat AANGEPAST moet worden

  Senders — Header migratie v1.0 → v2.0

  UserCreatedSender.php (user_created_sender.xsd ook):
  - xmlns="urn:integration:planning:v1" → verwijderen
  - <receiver>crm.salesforce</receiver> → verwijderen
  - type user.created → user_created
  - version 1.0 → 2.0
  - source frontend.drupal → frontend
  - queue user.created → crm.incoming

  UserUnregisteredSender.php (user_unregistered.xsd ook):
  - xmlns namespace → verwijderen
  - <receiver>...</receiver> → verwijderen
  - type user.unregistered → user_deleted
  - version 1.0 → 2.0
  - source frontend.drupal → frontend
  - fan-out naar 3 queues tegelijk → 1 exchange/routing key
  - body: <email> toevoegen (vereist in sec 5.3), dubbele <timestamp> in body weghalen

  UserCheckinSender.php (user_checkin.xsd ook nagaan):
  - xmlns namespace → verwijderen
  - <receiver>monitoring.elastic</receiver> → verwijderen
  - type user.checkin → user_checkin
  - version 1.0 → 2.0
  - source frontend.drupal → frontend
  - queue user.checkin → juiste routing key

  CalendarInviteSender.php (calendar_invite.xsd ook):
  - createElementNS(self::NAMESPACE, 'message') → createElement('message') (namespace verwijderen)
  - type calendar.invite → calendar_invite
  - <version>2.0</version> ontbreekt volledig → toevoegen
  - body: <attendee_email> ontbreekt maar is verplicht per sec 17.2
  - body: <user_id> optioneel in code maar verplicht per contract

  NewRegistrationSender.php (new_registration.xsd ook):
  - conditioneel voegt nog <master_uuid> toe (regel 91-93) → altijd verwijderen
  - first_name/last_name zitten los in <customer> → moeten in <contact> wrapper per Regel 2
  - XSD bevat ook nog <master_uuid> als minOccurs="0" → verwijderen

  SessionCreateRequestSender.php, SessionUpdateRequestSender.php, SessionDeleteRequestSender.php, SessionViewRequestSender.php:
  - Alle 4: createElementNS(self::NAMESPACE, 'message') → createElement('message')
  - Alle 4: VERSION = '1.0' → '2.0'
  - SessionCreateRequestSender: body mist <session_id> (vereist per contract sec 17.3 XSD)

  XSD-bestanden — inhoud verouderd

  xsd/heartbeat.frontend.xsd:
  - Gebruikt targetNamespace="urn:integration:planning:v1" → verwijderen
  - Heeft <receiver> in header → verwijderen
  - version 1.0 → 2.0
  - Body status-waarden: alive/degraded/error → moeten online/degraded/offline zijn (exact zoals contract sec 3)
  - Geen PHP sender aanwezig → PHP sender aanmaken of externer triggeren

  xsd/session_view_request.xsd:
  - targetNamespace="urn:integration:planning:v1" → verwijderen
  - version is minOccurs="0" → verplicht 2.0

  xsd/calendar_invite.xsd:
  - targetNamespace="urn:integration:planning:v1" → verwijderen
  - type calendar.invite → calendar_invite
  - Geen <version> element → toevoegen als verplicht 2.0
  - <attendee_email> ontbreekt → toevoegen als verplicht

  xsd/user_created_sender.xsd:
  - targetNamespace, <receiver>, type user.created, version 1.0 → alles naar v2.0 snake_case

  xsd/user_unregistered.xsd:
  - targetNamespace, <receiver>, type user.unregistered, version 1.0 → alles naar v2.0, type → user_deleted

  ---
  ➕ Wat ONTBREEKT (moet toegevoegd worden)

  ┌──────────────────────────┬───────────────────────────────────────────────────────────────────────────────────────────────────────────┐   
  │      Wat ontbreekt       │                                                  Waarom                                                   │   
  ├──────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤   
  │ UserUpdatedSender.php    │ Contract sec 5.2 vereist user_updated bericht → sender bestaat niet                                       │   
  ├──────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤   
  │ UserRegisteredSender.php │ Contract audit vermeldt dit expliciet; user_registered.xsd bestaat wel, sender niet                       │   
  ├──────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤   
  │ xsd/user_updated.xsd     │ XSD voor user_updated ontbreekt volledig                                                                  │   
  ├──────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤   
  │ Identity RPC vóór        │ Contract sec 14.6: Identity RPC moet aangeroepen worden vóór new_registration naar CRM;                   │   
  │ CRM-call                 │ IdentityServiceClient.php bestaat maar integratie in NewRegistrationSender moet gecontroleerd worden      │   
  └──────────────────────────┴───────────────────────────────────────────────────────────────────────────────────────────────────────────┘   

  ---
  ⚠️ Wat IN DE CODE staat maar NIET (of anders) in het document

  ┌──────────────────────────────────────────────┬───────────────────────────────────────────────────────────────────────────────────────┐   
  │                     Wat                      │                                       Probleem                                        │   
  ├──────────────────────────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────┤   
  │ xsd/user_created_receiver.xsd +              │ Frontend ontvangt user_created — staat nergens in de Frontend quick reference tabel   │   
  │ UserCreatedReceiver.php                      │ van het contract. Waarschijnlijk voor Identity Service events, maar niet              │   
  │                                              │ gedocumenteerd. Controleren of dit nog nodig is.                                      │   
  ├──────────────────────────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────┤   
  │ NewRegistrationSender.php — velden type,     │ Deze extra velden staan in de code maar niet in de contract-XSD voor new_registration │   
  │ is_company_linked, company_name              │  (sec 5.1). Contractbreuk of bewuste uitbreiding?                                     │   
  ├──────────────────────────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────┤   
  │ UserUnregisteredSender.php — fan-out naar 3  │ Contract zegt: 1 message naar crm.incoming. Fan-out naar crm.salesforce,              │   
  │ hardcoded queues                             │ planning.outlook, mailing.sendgrid is v1.0 anti-patroon.                              │   
  └──────────────────────────────────────────────┴───────────────────────────────────────────────────────────────────────────────────────┘   

  ---
  Samenvatting prioriteiten

  1. Hoog — Header migratie: UserCreatedSender, UserCheckinSender, UserUnregisteredSender → v2.0
  ├──────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────────────────────────┤   
  │ Identity RPC vóór        │ Contract sec 14.6: Identity RPC moet aangeroepen worden vóór new_registration naar CRM;                   │   
  │ CRM-call                 │ IdentityServiceClient.php bestaat maar integratie in NewRegistrationSender moet gecontroleerd worden      │   
  └──────────────────────────┴───────────────────────────────────────────────────────────────────────────────────────────────────────────┘   

  ---
  ⚠️ Wat IN DE CODE staat maar NIET (of anders) in het document

  ┌──────────────────────────────────────────────┬───────────────────────────────────────────────────────────────────────────────────────┐   
  │                     Wat                      │                                       Probleem                                        │   
  ├──────────────────────────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────┤   
  │ xsd/user_created_receiver.xsd +              │ Frontend ontvangt user_created — staat nergens in de Frontend quick reference tabel   │   
  │ UserCreatedReceiver.php                      │ van het contract. Waarschijnlijk voor Identity Service events, maar niet              │   
  │                                              │ gedocumenteerd. Controleren of dit nog nodig is.                                      │   
  ├──────────────────────────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────┤   
  │ NewRegistrationSender.php — velden type,     │ Deze extra velden staan in de code maar niet in de contract-XSD voor new_registration │   
  │ is_company_linked, company_name              │  (sec 5.1). Contractbreuk of bewuste uitbreiding?                                     │   
  ├──────────────────────────────────────────────┼───────────────────────────────────────────────────────────────────────────────────────┤   
  │ UserUnregisteredSender.php — fan-out naar 3  │ Contract zegt: 1 message naar crm.incoming. Fan-out naar crm.salesforce,              │   
  │ hardcoded queues                             │ planning.outlook, mailing.sendgrid is v1.0 anti-patroon.                              │   
  └──────────────────────────────────────────────┴───────────────────────────────────────────────────────────────────────────────────────┘   

  ---
  Samenvatting prioriteiten

  1. Hoog — Header migratie: UserCreatedSender, UserCheckinSender, UserUnregisteredSender → v2.0
  2. Hoog — CalendarInviteSender: namespace weg + <version> + <attendee_email> toevoegen
  3. Hoog — Sessie-senders (SessionCreate/Update/Delete/ViewRequestSender): namespace weg + version 2.0
  4. Hoog — NewRegistrationSender: <master_uuid> weg + <contact> wrapper toevoegen
  5. Medium — UserUpdatedSender.php + UserRegisteredSender.php aanmaken
  6. Medium — Alle verouderde XSD-bestanden herbouwen naar v2.0
  7. Laag — user_created_receiver.xsd / UserCreatedReceiver.php documenteren of verwijderen