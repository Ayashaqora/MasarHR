# MasarHR

مسار — نظام إدارة شؤون الموظفين والقوى العاملة
Masar — Human Resources & Workforce Management System

> **Status:** Stage S01 (Project Foundation). No HR functionality is implemented yet.

## Stack

| Layer | Technology |
| --- | --- |
| Backend | Laravel 13, REST API under `/api/v1` (PHP 8.5 target; 8.3 accepted locally) |
| Frontend | React 19, TypeScript, Vite — Arabic-first, RTL-first, English-ready |
| Database | PostgreSQL (mandatory) |
| Cache / queue | Redis 7 |
| Architecture | Modular Monolith (Domain / Application / Infrastructure / Presentation) |

## Repository layout

```
MasarHR/
├── backend/          Laravel 13 API
├── frontend/         React + TypeScript + Vite application
├── docs/             Project documentation
├── infrastructure/   Minimal local infrastructure notes/config
└── tests/            Cross-cutting tests (unit tests live beside their code)
```

## Getting started

See [`docs/development-setup.md`](docs/development-setup.md) for prerequisites, environment
configuration, and the commands to run and test both applications.

## Data handling warning

This repository must never contain real employee data, national IDs, real HR spreadsheets,
database dumps, or secrets. Use synthetic data only. See `.gitignore` and `CLAUDE.md`.

## Governance

Work proceeds in authorized stages. Claude Code is the executor; the Architecture Authority owns
architecture and scope. No stage is started, and nothing is committed, pushed, tagged or released,
without explicit authorization.
