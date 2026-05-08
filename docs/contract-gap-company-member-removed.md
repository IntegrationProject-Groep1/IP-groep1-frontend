## Samenvatting
Er bestaat geen berichttype om een gebruiker los te koppelen van een bedrijf in CRM. Wanneer een bedrijfsbeheerder een uitnodiging intrekt, blijft de gebruiker in CRM als bedrijfslid geregistreerd omdat er geen bericht verstuurd kan worden om dit ongedaan te maken.

## Verwacht Gedrag
Wanneer een bedrijfsbeheerder een uitnodiging intrekt vóórdat de uitgenodigde zich heeft geregistreerd, moet CRM de pre-registratie van die gebruiker als bedrijfslid verwijderen. De gebruiker mag vervolgens niet meer als bedrijfslid worden herkend.

## Huidig Gedrag
Na het intrekken van een uitnodiging wordt de invite token lokaal verwijderd (de link werkt niet meer), maar CRM heeft de gebruiker al aangemaakt als bedrijfslid via een eerder verstuurd `user_created` bericht met `company_id`. Er is geen berichttype beschikbaar om deze koppeling ongedaan te maken, waardoor de gebruiker permanent als bedrijfslid geregistreerd blijft in CRM.

## Betrokken Contractsectie
`XML_XSD_Contract_v2.3_Centralized 1.md` — sectie `user_created` (company flow)

## Betrokken Team(s)
- [x] CRM
- [ ] Kassa
- [x] Frontend
- [ ] Planning
- [ ] Facturatie
- [ ] Monitoring
- [ ] Mailing
- [ ] Identity
- [ ] Ander team: ...

## Berichtdetails
- Message type: ontbreekt — voorstel: `company_member_removed`
- Source: `frontend`
- Correlation ID (indien beschikbaar): n.v.t.
- Queue/routing key (indien van toepassing): `crm.incoming`

## Reproductiestappen
1. Registreer een gebruiker als bedrijf via `/register` (vink "I am registering as a company" aan)
2. Log in en ga naar `/company/invite`
3. Nodig een e-mailadres uit — dit verstuurt een `user_created` bericht naar CRM met `company_id` gekoppeld
4. Verwijder de uitnodiging via de "Delete" knop in de overzichtstabel
5. De invite link is nu geblokkeerd, maar CRM heeft de gebruiker nog steeds als bedrijfslid geregistreerd — er is geen bericht om dit te corrigeren

## XML/XSD Voorbeeld Of Foutmelding
Het `user_created` bericht dat al verstuurd is (en niet teruggedraaid kan worden):

```xml
<message>
  <header>
    <message_id>a1b2c3d4-...</message_id>
    <timestamp>2026-05-08T14:00:00Z</timestamp>
    <source>frontend</source>
    <type>user_created</type>
    <version>2.0</version>
    <correlation_id>a1b2c3d4-...</correlation_id>
  </header>
  <body>
    <customer>
      <identity_uuid>e8b27c1d-4f2a-4b3e-9c5f-123456789abc</identity_uuid>
      <email>uitgenodigde@example.com</email>
      <first_name></first_name>
      <last_name></last_name>
      <date_of_birth></date_of_birth>
      <type>company</type>
      <company_id>uid-3</company_id>
    </customer>
  </body>
</message>
```

Het berichttype dat ontbreekt om dit terug te draaien — zie volledig voorstel in `docs/contract-proposal-company-member-removed.md` op branch `claude/add-company-registration-azMAX`.

## Impact
- **Frontend:** kan geen uitnodiging volledig intrekken zonder dit berichttype
- **CRM:** bevat onjuiste bedrijfskoppelingen voor ingetrokken uitnodigingen
- **Flow:** company invite flow (branch `claude/add-company-registration-azMAX`)

## Checklijst
- [x] Ik heb de relevante contractsectie nagelezen.
- [x] Ik heb een concreet voorbeeld toegevoegd.
- [x] Ik heb aangegeven wat verwacht gedrag is.
- [x] Ik heb impact benoemd.

## Voorstel Tot Oplossing (optioneel)
Voeg een nieuw berichttype `company_member_removed` toe aan het contract. Het volledige voorstel inclusief XML-voorbeeld, XSD-definitie en verwacht CRM-gedrag staat gedocumenteerd in:
`docs/contract-proposal-company-member-removed.md` op branch `claude/add-company-registration-azMAX`
