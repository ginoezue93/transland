# TranslandShipping – PlentyMarkets Plugin

Anbindung der **Transland / Zufall logistics group** Speditions-API an PlentyMarkets.

---

## Prozessübersicht

```
Verpackungsprozess (Plenty)          Tagesabschluss
─────────────────────────────        ──────────────────────────────
Packstücke erfassen (Maße/Gewicht)
         ↓
POST /rest/transland/label           POST /rest/transland/submit-day
  → Label-PDF/ZPL drucken              → Alle SSCCs als Bordero melden
  → SSCC von Transland erhalten         → Ladeliste-PDF erhalten
  → Sendungsdaten speichern             → Transportaufträge angelegt ✓
```

---

## Installation

1. Plugin-Ordner `TranslandShipping` in das Plenty-Plugin-Repository hochladen
2. Im Plenty-Backend: Plugins → Plugin-Set → Plugin installieren und deployen
3. Einstellungen unter **Setup → TranslandShipping** konfigurieren

---

## Einrichtung (Schritt für Schritt)

### 1. Zugangsdaten von Hr. Schrader (Transland) erfragen:
- `[KUNDE]` – Ihr Kürzel für die API-URL
- Benutzername und Passwort für DigestAuth
- Kundennummer bei Transland (customer_id im Bordero)

### 2. Plugin-Einstellungen befüllen (`/transland/settings`):
| Feld | Wert |
|------|------|
| Kunden-Kürzel | von Hr. Schrader |
| Kundennummer bei Transland | von Hr. Schrader |
| Benutzername | von Hr. Schrader |
| Passwort | von Hr. Schrader |
| Modus | Test → dann Produktion |
| Absenderadresse | Ihr Lager |

### 3. Verpackungsprozesse konfigurieren:
Für jeden Prozess die Standard-Verpackungsart festlegen:

| Prozess-ID | Bezeichnung | Empfehlung |
|------------|-------------|------------|
| 52 | 2. AP Kleinteile/Sonst. Teile | KT (Karton) |
| 73 | Montageschienen | FP (Europalette) |
| 79 | 1. AP Kleinteile/Sonst. Teile | KT (Karton) |
| 85 | PV Module | FP (Europalette) |
| 87 | Elektro | KT (Karton) |

---

## API-Referenz (interne REST-Endpunkte)

### Label-Druck (beim Verpacken)
```
POST /rest/transland/label
Authorization: Bearer {plenty_token}
Content-Type: application/json

{
  "order": { ...PlentyMarkets Auftragsobjekt... },
  "process_id": 52,
  "packages": [
    {
      "content": "Elektronikteile",
      "packaging_type": "KT",
      "length_cm": 60,
      "width_cm": 40,
      "height_cm": 30,
      "weight_gr": 5000
    }
  ],
  "options": [
    { "code": 101, "text": "+49 561 123456" }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "label_data": "<base64-PDF oder ZPL>",
  "label_format": "PDF",
  "sscc_list": ["00001234560000000018"],
  "packages": [{ "sscc": "00001234560000000018", "..." }],
  "order_id": 12345
}
```

### Ausstehende Sendungen anzeigen
```
GET /rest/transland/pending?date=2025-07-03
```

### Manueller Tagesabschluss
```
POST /rest/transland/submit-day
Content-Type: application/json

{
  "pickup_date": "2025-07-03",
  "return_list": true
}
```

**Response:**
```json
{
  "success": true,
  "list_id": "LIST-20250703-1751234567",
  "shipment_count": 12,
  "list_pdf": "<base64-PDF Ladeliste>",
  "submitted_order_ids": [12345, 12346, ...]
}
```

---

## Sendungsoptionen (Codes)

Häufig verwendete Codes für das `options`-Array:

| Code | Bedeutung |
|------|-----------|
| 101 | Avisierung unter Telefonnummer (+ text) |
| 107 | Zustellung mit Hebebühnen-LKW |
| 109 | Empfindliche Ware |
| 112 | Paletten nicht stapelbar |
| 127 | Stapelbare Paletten |
| 204 | Termingut KW ... (+ text) |
| 250 | Premium: NextDay |
| 257 | Premium: NextDay/12 |

---

## Datenbankschemas (auto-erstellt durch PlentyMarkets)

**TranslandShipping_shipments**
- `id`, `orderId`, `pickupDate`, `listId`, `submitted`, `shipmentData`, `createdAt`, `updatedAt`

**TranslandShipping_settings**
- `id`, `settingKey`, `settingValue`, `updatedAt`

---

## Verpackungsarten-Referenz

| Code | Bedeutung |
|------|-----------|
| FP | Euro-Palette |
| EP | Einwegpalette |
| KT | Karton |
| PA | Paket |
| HP | Halbpalette |
| DP | Düsseldorf-Palette |
| GP | Gitterboxpalette |
| CP | Chep-Palette |

---

## Kontakt / Support

Transland/Zufall Ansprechpartner: **Hr. Schrader**  
E-Mail Schnittstelle: Team.Software-Schnittstellenentwicklung@zufall.de
