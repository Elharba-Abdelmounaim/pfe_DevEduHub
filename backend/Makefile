# ── DevEduHub Makefile ─────────────────────────────────────────────────────────
# Shortcuts for common Docker + Laravel + Python commands.
# Usage: make <target>

.PHONY: up down build fresh migrate seed test logs shell

# ── Stack ─────────────────────────────────────────────────────────────────────

up:
	docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d

down:
	docker compose down

build:
	docker compose build --no-cache

restart:
	docker compose restart app worker notifier

# ── Laravel ───────────────────────────────────────────────────────────────────

migrate:
	docker compose exec app php artisan migrate

seed:
	docker compose exec app php artisan migrate:fresh --seed

fresh: seed

key:
	docker compose exec app php artisan key:generate

cache-clear:
	docker compose exec app php artisan optimize:clear

# ── Testing ───────────────────────────────────────────────────────────────────

test:
	docker compose exec app php artisan test --parallel

test-coverage:
	docker compose exec app php artisan test --coverage

test-python:
	docker compose exec grader pytest test_grader.py -v

# ── Queue workers (manual start outside Docker) ───────────────────────────────

worker-grading:
	docker compose exec app php artisan queue:work redis \
		--queue=grading --timeout=180 --tries=3

worker-notify:
	docker compose exec app php artisan queue:work redis \
		--queue=notifications --timeout=30 --tries=5

queue-monitor:
	docker compose exec app php artisan queue:monitor redis:grading,redis:notifications

failed-jobs:
	docker compose exec app php artisan queue:failed

retry-failed:
	docker compose exec app php artisan queue:retry all

# ── Logs ──────────────────────────────────────────────────────────────────────

logs:
	docker compose logs -f app worker notifier grader

logs-nginx:
	docker compose logs -f nginx

logs-db:
	docker compose logs -f db

# ── Shell access ──────────────────────────────────────────────────────────────

shell:
	docker compose exec app sh

shell-db:
	docker compose exec db psql -U postgres deveduhub

shell-redis:
	docker compose exec redis redis-cli

shell-grader:
	docker compose exec grader sh

# ── Health checks ─────────────────────────────────────────────────────────────

health:
	@echo "Laravel:" && curl -sf http://localhost:8080/api/health | jq . || echo "DOWN"
	@echo "Grader: " && curl -sf http://localhost:8000/health     | jq . || echo "DOWN"
