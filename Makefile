.PHONY: up down build install migrate fresh admin shell logs test

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose up -d --build

install:
	docker compose exec app composer install
	docker compose exec app php artisan key:generate --ansi

migrate:
	docker compose exec app php artisan migrate

fresh:
	docker compose exec app php artisan migrate:fresh

admin:
	docker compose exec app php artisan make:filament-user

shell:
	docker compose exec app bash

logs:
	docker compose logs -f

test:
	docker compose exec app composer test
