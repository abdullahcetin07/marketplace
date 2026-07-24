###############################################################################
# MarketplaceOS
#
# Every command runs inside Docker. Nothing here assumes PHP, Composer or
# PostgreSQL on the host — that is the point: one `make install` and a new
# engineer is productive, regardless of what their laptop has on it.
#
#   make help      list every target
###############################################################################

DC      := docker compose
APP     := $(DC) exec app
APP_RUN := $(DC) run --rm --no-deps app

.DEFAULT_GOAL := help
.PHONY: help install up down restart build shell logs ps \
        migrate migrate-fresh seed permissions admin \
        test test-unit test-feature test-arch coverage \
        lint lint-fix analyse check ide-helper \
        horizon queue-restart cache-clear optimize \
        search-index clean

## ---------------------------------------------------------------------------
## Setup
## ---------------------------------------------------------------------------

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

install: ## First-time setup: build, install deps, key, migrate, seed
	@test -f .env || (cp .env.example .env && echo "Created .env from .env.example")
	$(DC) build
	$(DC) up -d postgres redis opensearch minio
	$(APP_RUN) composer install
	$(APP_RUN) php artisan key:generate
	$(DC) up -d
	@echo "Waiting for services..."
	@sleep 5
	$(APP) php artisan migrate --seed
	$(APP) php artisan storage:link
	@echo ""
	@echo "  App        http://localhost:8080"
	@echo "  Admin      http://localhost:8080/admin"
	@echo "  Seller     http://localhost:8080/seller"
	@echo "  Horizon    http://localhost:8080/admin/horizon"
	@echo "  Mailpit    http://localhost:8025"
	@echo "  MinIO      http://localhost:9001"
	@echo ""
	@echo "  Create your first admin:  make admin"

up: ## Start the stack
	$(DC) up -d

down: ## Stop the stack
	$(DC) down

restart: ## Restart the stack
	$(DC) restart

build: ## Rebuild images
	$(DC) build --no-cache

shell: ## Shell into the app container
	$(APP) bash

logs: ## Tail logs (S=service to narrow, e.g. make logs S=horizon)
	$(DC) logs -f $(S)

ps: ## Show container status
	$(DC) ps

## ---------------------------------------------------------------------------
## Database
## ---------------------------------------------------------------------------

migrate: ## Run pending migrations
	$(APP) php artisan migrate

migrate-fresh: ## Drop everything and re-migrate with seeds (DESTRUCTIVE)
	$(APP) php artisan migrate:fresh --seed

seed: ## Run seeders
	$(APP) php artisan db:seed

permissions: ## Sync permissions from PermissionRegistry
	$(APP) php artisan marketplace:sync-permissions

admin: ## Create an administrator interactively
	$(APP) php artisan marketplace:create-admin --super

## ---------------------------------------------------------------------------
## Quality — these four are exactly what CI runs
## ---------------------------------------------------------------------------

test: ## Run the full Pest suite
	$(APP) php artisan test

test-unit: ## Unit suite only
	$(APP) ./vendor/bin/pest --testsuite=Unit

test-feature: ## Feature suite only
	$(APP) ./vendor/bin/pest --testsuite=Feature

test-arch: ## Architecture rules only
	$(APP) ./vendor/bin/pest --testsuite=Architecture

coverage: ## Run tests with coverage, failing under 70%
	$(APP) ./vendor/bin/pest --coverage --min=70

lint: ## Check formatting (Pint, no changes written)
	$(APP) ./vendor/bin/pint --test

lint-fix: ## Fix formatting
	$(APP) ./vendor/bin/pint

analyse: ## Static analysis (PHPStan level 6)
	$(APP) ./vendor/bin/phpstan analyse --memory-limit=1G

check: lint analyse test ## Everything CI runs, locally

ide-helper: ## Regenerate IDE helper files
	$(APP) php artisan ide-helper:generate
	$(APP) php artisan ide-helper:models --nowrite
	$(APP) php artisan ide-helper:meta

## ---------------------------------------------------------------------------
## Runtime
## ---------------------------------------------------------------------------

horizon: ## Tail Horizon
	$(DC) logs -f horizon

queue-restart: ## Gracefully restart queue workers
	$(APP) php artisan horizon:terminate

cache-clear: ## Clear every cache
	$(APP) php artisan optimize:clear

optimize: ## Build production caches
	$(APP) php artisan optimize

search-index: ## Rebuild OpenSearch indexes (M=Model to narrow)
	$(APP) php artisan scout:index $(M)

clean: ## Remove containers AND volumes (DESTRUCTIVE)
	$(DC) down -v --remove-orphans
