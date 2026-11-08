COMPOSE ?= docker compose
TESTS = sh docker/php/run-tests.sh

.PHONY: up down test test-parallel lint fix stan fresh ps logs shell docs docs-check demo

## Поднять окружение целиком
up:
	$(COMPOSE) up -d --build
	@echo "Приложение: http://127.0.0.1:8080/up"

## Остановить и удалить контейнеры
down:
	$(COMPOSE) down

## Прогнать все тесты (Unit, Feature, Arch) на PostgreSQL
test:
	$(COMPOSE) run --rm app $(TESTS)

## Как в CI: параллельный прогон (тоже без risky)
test-parallel:
	$(COMPOSE) run --rm app $(TESTS) --parallel

## Проверка стиля без исправлений
lint:
	$(COMPOSE) run --rm app ./vendor/bin/pint --test

## Исправить стиль автоматически
fix:
	$(COMPOSE) run --rm app ./vendor/bin/pint

## Статический анализ
stan:
	$(COMPOSE) run --rm app ./vendor/bin/phpstan analyse

## Пересоздать базы и налить демо-данные
fresh:
	$(COMPOSE) exec app php artisan migrate:fresh --seed

## Собрать описание API из кода (docs/api/openapi.json)
docs:
	$(COMPOSE) exec app php artisan api:docs

## Проверить, что описание API совпадает с кодом
docs-check:
	$(COMPOSE) exec app php artisan api:docs --check

## Показать демонстрационные учётные записи и данные для показа
demo:
	$(COMPOSE) exec app php artisan demo:accounts

## Состояние сервисов
ps:
	$(COMPOSE) ps

## Логи всех сервисов
logs:
	$(COMPOSE) logs -f

## Оболочка внутри контейнера приложения
shell:
	$(COMPOSE) exec app sh
