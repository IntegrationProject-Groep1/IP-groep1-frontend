# Contract Proposal: `company_member_removed`

**Voorgesteld door:** Frontend team (IP-Groep1)  
**Datum:** 2026-05-08  
**Contract versie:** toevoeging aan v2.3  
**Status:** Voorstel — ter review

---

## Aanleiding

Het huidige contract (v2.3) ondersteunt het koppelen van een gebruiker aan een bedrijf via `user_created` en `user_updated`, maar voorziet geen manier om die koppeling later ongedaan te maken.

**Concreet probleem:** wanneer een bedrijfsbeheerder een uitnodiging intrekt (vóór acceptatie), is de uitnodiging lokaal verwijderd maar staat de gebruiker in CRM nog steeds als bedrijfslid geregistreerd (via het eerder verstuurde `user_created` bericht). Er is geen berichttype om CRM hiervan op de hoogte te stellen.

---

## Voorstel: nieuw berichttype `company_member_removed`

| Eigenschap | Waarde |
|---|---|
| **Type** | `company_member_removed` |
| **Queue** | `crm.incoming` |
| **Source** | `frontend` |
| **Version** | `2.0` |
| **Richting** | Frontend → CRM |

---

## XML voorbeeld

```xml
<message>
  <header>
    <message_id>a1b2c3d4-e5f6-7890-abcd-ef1234567890</message_id>
    <timestamp>2026-05-08T14:30:00Z</timestamp>
    <source>frontend</source>
    <type>company_member_removed</type>
    <version>2.0</version>
    <correlation_id>a1b2c3d4-e5f6-7890-abcd-ef1234567890</correlation_id>
  </header>
  <body>
    <removal>
      <identity_uuid>e8b27c1d-4f2a-4b3e-9c5f-123456789abc</identity_uuid>
      <company_id>uid-3</company_id>
      <reason>invite_revoked</reason>
    </removal>
  </body>
</message>
```

---

## Veldspecificatie

| Veld | Type | Verplicht | Beschrijving |
|---|---|---|---|
| `identity_uuid` | UUID | Ja | Master UUID van de te verwijderen gebruiker (van Identity Service) |
| `company_id` | string | Ja | Identifier van het bedrijf (master UUID van de bedrijfsbeheerder) |
| `reason` | enum | Ja | Reden van verwijdering: `invite_revoked` of `admin_removed` |

---

## XSD definitie

```xsd
<?xml version="1.0" encoding="UTF-8"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">

  <!-- Herbruik bestaand header type uit de gedeelde header.xsd -->
  <xs:include schemaLocation="header.xsd"/>

  <xs:element name="message">
    <xs:complexType>
      <xs:sequence>
        <xs:element name="header" type="HeaderType"/>
        <xs:element name="body">
          <xs:complexType>
            <xs:sequence>
              <xs:element name="removal" type="RemovalType"/>
            </xs:sequence>
          </xs:complexType>
        </xs:element>
      </xs:sequence>
    </xs:complexType>
  </xs:element>

  <xs:complexType name="RemovalType">
    <xs:sequence>
      <xs:element name="identity_uuid">
        <xs:simpleType>
          <xs:restriction base="xs:string">
            <xs:pattern value="[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:element>
      <xs:element name="company_id" type="xs:string"/>
      <xs:element name="reason">
        <xs:simpleType>
          <xs:restriction base="xs:string">
            <xs:enumeration value="invite_revoked"/>
            <xs:enumeration value="admin_removed"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:element>
    </xs:sequence>
  </xs:complexType>

</xs:schema>
```

---

## Verwacht gedrag van CRM

Wanneer CRM dit bericht ontvangt:
1. Zoek de gebruiker op via `identity_uuid`
2. Verwijder de koppeling tussen de gebruiker en het bedrijf (`company_id`)
3. Zet het gebruikerstype terug naar `private` als er geen andere bedrijfskoppeling is
4. Log de actie met de `reason`

---

## Opmerkingen

- Dit berichttype verwijdert **geen** gebruikersaccount — alleen de bedrijfskoppeling
- Als de gebruiker nog geen account heeft aangemaakt (invite was pending), verwijdert CRM de pre-registratie
- Het `reason` veld is uitbreidbaar voor toekomstige use cases (bijv. `contract_ended`)
