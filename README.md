<div align="center">

# SMSKIT

**Self-hosted SMS Gateway — Turn any Android phone into your SMS relay.**

[![GitHub Release](https://img.shields.io/github/v/release/smskit/smskit?style=for-the-badge&color=%23C8102E)](https://github.com/smskit/smskit/releases)
![Views](https://hits.sh/github.com/smskit/smskit.svg)
[![License MIT](https://img.shields.io/badge/license-MIT-green?style=for-the-badge)](LICENSE)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-8892BF?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![Android 5.0+](https://img.shields.io/badge/Android-5.0%2B-3DDC84?style=for-the-badge&logo=android&logoColor=white)](https://github.com/smskit/smskit/releases)
[![Downloads](https://img.shields.io/github/downloads/smskit/smskit/total?style=for-the-badge)](https://github.com/smskit/smskit/releases)
[![Stars](https://img.shields.io/github/stars/smskit/smskit?style=for-the-badge&color=yellow)](https://github.com/smskit/smskit/stargazers)

</div>

---

## What is SMSKIT?

SMSKIT is a self-hosted SMS gateway that pairs a lightweight PHP flat-file backend with a native Android app, letting your own phone act as the SMS relay hardware — no Twilio, no monthly bills, no third-party APIs. You deploy the PHP files to any server, install the APK on an Android device, and the phone polls for outbound messages every 5 seconds, dispatching them through your carrier like any normal text message. Incoming replies are captured by the app and forwarded back to your dashboard in real time.

---

## Features

- **Android-powered relay** — your phone is the hardware; SIM card costs are all you pay
- **Flat-file JSON storage** — zero database required; runs on the cheapest shared hosting
- **REST API with bearer tokens** — granular per-key permissions for read, send, and admin scopes
- **Scheduled SMS with recurrence** — one-time, daily, weekly, or monthly delivery
- **8 built-in message templates** — OTP codes, order confirmations, reminders, alerts, and more
- **Multi-device round-robin load balancing** — distribute traffic across multiple phones automatically
- **CSV export** — download sent, received, scheduled, and queued logs in one click
- **Failed message retry panel** — inspect failures with error codes and retry with a single click
- **Auto-refreshing dashboard** — live stats and activity feed refresh every 30 seconds
- **Hardened authentication** — bcrypt (cost 12) for admin passwords; SHA-256 hashed API keys never stored plaintext

---

## How It Works

```
┌─────────────────────────┐
│   Dashboard  /  API     │
└────────────┬────────────┘
             │  writes message to queue
             ▼
┌─────────────────────────┐
│   JSON flat files       │
│   (on your web server)  │
└────────────┬────────────┘
             │  Android app polls every 5 s
             ▼
┌─────────────────────────┐
│   Android phone         │◄──── incoming SMS forwarded back
│   (your SIM card)       │
└────────────┬────────────┘
             │  sends via carrier network
             ▼
┌─────────────────────────┐
│   SMS delivered to      │
│   recipient             │
└─────────────────────────┘
```

1. **Deploy** — upload the PHP files to any server running PHP 7.4+
2. **Connect** — install the Android APK, enter your server URL and API key
3. **Send** — queue a message from the dashboard or via the REST API
4. **Deliver** — the phone picks up queued messages within 5 seconds and dispatches them through the carrier

---

## Quick Start

```bash
git clone https://github.com/smskit/smskit.git
```

1. Upload the contents of the cloned folder to your web server's public directory.
2. Visit `https://yourdomain.com/setup.php` and complete the two-step installer (creates config files and the first admin account).
3. Download the latest `.apk` from the [Releases page](https://github.com/smskit/smskit/releases), install it on your Android device, and enter your server URL when prompted.

That's it. Your gateway is live.

---

## API Reference

**Base URL:** `https://yourdomain.com/api/v1`

All endpoints require an `Authorization` header. API keys are created and revoked from the dashboard.

```
Authorization: Bearer sk_live_xxxxxxxxxxxxxxxxxxxx
```

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/send.php` | Queue an SMS to one or more recipients |
| `GET` | `/status.php?id=xxx` | Check delivery status by message ID |
| `GET` | `/statistics.php` | Retrieve usage stats and totals |
| `POST` | `/validate.php` | Validate a phone number format |
| `POST` | `/schedule.php` | Schedule an SMS for future or recurring delivery |

---

### `POST /send.php`

Queue an SMS immediately.

```bash
curl -X POST https://yourdomain.com/api/v1/send.php \
  -H "Authorization: Bearer sk_live_xxxxxxxxxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "to": ["+8801711000001", "+8801812000002"],
    "message": "Your OTP is 847291. Valid for 5 minutes."
  }'
```

```json
{
  "success": true,
  "queued": 2,
  "messages": [
    { "id": "msg_a1b2c3d4", "to": "+8801711000001", "status": "queued" },
    { "id": "msg_e5f6g7h8", "to": "+8801812000002", "status": "queued" }
  ]
}
```

---

### `GET /status.php?id=xxx`

Check the delivery status of a specific message.

```bash
curl -X GET "https://yourdomain.com/api/v1/status.php?id=msg_a1b2c3d4" \
  -H "Authorization: Bearer sk_live_xxxxxxxxxxxxxxxxxxxx"
```

```json
{
  "success": true,
  "id": "msg_a1b2c3d4",
  "to": "+8801711000001",
  "message": "Your OTP is 847291. Valid for 5 minutes.",
  "status": "sent",
  "device": "device_01",
  "queued_at": "2026-05-18T10:22:00Z",
  "sent_at": "2026-05-18T10:22:04Z"
}
```

---

### `GET /statistics.php`

Retrieve aggregate usage statistics.

```bash
curl -X GET https://yourdomain.com/api/v1/statistics.php \
  -H "Authorization: Bearer sk_live_xxxxxxxxxxxxxxxxxxxx"
```

```json
{
  "success": true,
  "stats": {
    "total_sent": 14832,
    "total_received": 3210,
    "total_failed": 47,
    "total_scheduled": 128,
    "devices_online": 2,
    "queue_depth": 3
  }
}
```

---

### `POST /validate.php`

Validate a phone number before sending.

```bash
curl -X POST https://yourdomain.com/api/v1/validate.php \
  -H "Authorization: Bearer sk_live_xxxxxxxxxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{ "number": "+8801711000001" }'
```

```json
{
  "success": true,
  "number": "+8801711000001",
  "valid": true,
  "country": "Bangladesh",
  "country_code": "BD",
  "carrier_hint": "Grameenphone"
}
```

---

### `POST /schedule.php`

Schedule an SMS for future or recurring delivery.

**`recurrence` options:** `none` · `daily` · `weekly` · `monthly`

```bash
curl -X POST https://yourdomain.com/api/v1/schedule.php \
  -H "Authorization: Bearer sk_live_xxxxxxxxxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "+8801920000003",
    "message": "Your monthly invoice is ready. Visit https://billing.example.com",
    "send_at": "2026-06-01T09:00:00Z",
    "recurrence": "monthly"
  }'
```

```json
{
  "success": true,
  "schedule_id": "sch_9z8y7x6w",
  "to": "+8801920000003",
  "send_at": "2026-06-01T09:00:00Z",
  "recurrence": "monthly",
  "next_run": "2026-06-01T09:00:00Z",
  "status": "scheduled"
}
```

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 7.4+ |
| Storage | Flat-file JSON |
| Android | Native Java (no AndroidX) |
| Frontend | Vanilla HTML / CSS / JS |
| Auth | bcrypt (cost 12) + SHA-256 bearer tokens |

---

## Security

- **Bcrypt cost factor 12** for all admin passwords
- **SHA-256 hashed API keys** — raw keys are shown once at creation and never stored plaintext
- **`flock()` file locking** on every write operation to prevent concurrent corruption
- **Rate limiting** on all unauthenticated and high-frequency endpoints
- **Session cookies** set with `Secure`, `HttpOnly`, and `SameSite=Strict` flags

---

## File Structure

```
smskit/
├── index.php                # Landing page
├── setup.php                # Two-step installer
├── dashboard/
│   └── index.php            # Admin dashboard (stats, devices, logs)
├── config/                  # JSON flat-file storage (web-protected)
│   ├── settings.json
│   ├── devices.json
│   ├── messages.json
│   └── schedules.json
├── api/
│   └── v1/                  # Public REST API
│       ├── send.php
│       ├── status.php
│       ├── statistics.php
│       ├── validate.php
│       └── schedule.php
└── apk/
    └── smskit.apk           # Latest Android release
```

---

## Requirements

- PHP **7.4** or higher
- PHP extensions: `curl`, `json`, `openssl`
- An **Android 5.0+** device with an active SIM card
- Any shared hosting, VPS, or dedicated server with web access

---

## Support & Community

<div align="center">

[![Telegram](https://img.shields.io/badge/Telegram-%40envrc-2CA5E0?style=for-the-badge&logo=telegram&logoColor=white)](https://t.me/envrc)
[![GitHub Issues](https://img.shields.io/github/issues/smskit/smskit?style=for-the-badge&label=GitHub%20Issues)](https://github.com/smskit/smskit/issues)

</div>

For questions, bug reports, or feature requests, open an [issue](https://github.com/smskit/smskit/issues) on GitHub or reach out directly on Telegram.

---

## License

MIT © 2026 SMSKIT

Built with love by [@envrc](https://t.me/envrc)
