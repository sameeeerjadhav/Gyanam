# Gyanam

Education management and online examination platform for **Gyanam India Educational Services**.

This repository has two apps:

| Folder | Purpose |
|--------|---------|
| [`gyanamindia/`](gyanamindia/) | Main portal — Admin (Head Office), DLC, ATC, Training |
| [`gyanamexam/`](gyanamexam/) | Exam portal — student exam SPA + Laravel API |

Live (typical):

- Portal: `https://gyanamindia.labxco.in`
- Exam: `https://gyanamexam.labxco.in`

---

## Architecture

```
Admin / DLC / ATC (PHP + MySQL)
        │
        │  Sanctum Bearer API
        ▼
Exam Portal (Laravel API + front-end)
```

- **gyanamindia** is the student / franchise operational system (admissions, fees, HO/DLC shares, materials, certificates).
- **gyanamexam** handles question banks, exam scheduling, live attempts, and results.
- They share data over the exam API (`EXAM_API_URL` + `EXAM_API_TOKEN`), not a shared database login.

---

## Roles (Gyanam India)

- **Admin** — courses, ATC/DLC logins, share payments, dispatches, certificates
- **DLC** — regional ATC oversight, bank details, DLC share earnings
- **ATC** — admissions, fees, HO share pay, inquiries, student docs
- **Training** — training video access for centres

---

## Setup

### 1. Gyanam India (`gyanamindia`)

1. Point the web root at `gyanamindia/` (PHP 8+ + MySQL/MariaDB).
2. Copy config templates and fill real values locally (do **not** commit secrets):

```bash
cp gyanamindia/config/db.php.example gyanamindia/config/db.php
cp gyanamindia/config/razorpay.php.example gyanamindia/config/razorpay.php
```

3. Import a baseline schema if needed:

```text
gyanamindia/database_schema_complete.sql
```

Newer columns (e.g. dual With/Without Material HO & DLC shares) are applied automatically by the app when pages load.

4. Ensure `uploads/` is writable by the web server.

### 2. Exam Portal (`gyanamexam`)

See [`gyanamexam/README.md`](gyanamexam/README.md) and [`gyanamexam/run_guide.md`](gyanamexam/run_guide.md).

Backend:

```bash
cd gyanamexam/gyanam-backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
```

Wire `EXAM_API_URL` and `EXAM_API_TOKEN` in `gyanamindia/config/db.php`.

---

## Repository layout

```
Gyanam/
├── gyanamindia/          # Main PHP portal
│   ├── admin/            # Head Office
│   ├── atc/              # Training centres
│   ├── dlc/              # District / regional offices
│   ├── training/         # Training video login
│   ├── includes/         # Shared helpers (auth, shares, pagination, exam sync)
│   ├── config/           # db.php / razorpay.php (local only — gitignored)
│   └── database_schema_complete.sql
├── gyanamexam/           # Exam front-end + Laravel API
│   └── gyanam-backend/
└── README.md             # This file
```

---

## Security notes

These files are **gitignored** and must never be committed:

- `gyanamindia/config/db.php`
- `gyanamindia/config/razorpay.php`
- `gyanamexam/gyanam-backend/.env`

Use the `.example` files as templates.

---

## License

Private / proprietary — Gyanam India Educational Services. All rights reserved.
