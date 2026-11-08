-- Отдельная база сервиса оповещений и отдельная роль с доступом только к ней
-- (FR-NTF-212, FR-NTF-213). Скрипт выполняется PostgreSQL только при
-- инициализации пустого тома pgdata; на существующем томе те же операторы
-- выполняются вручную (см. courier/services/notifications/README.md).

CREATE ROLE notifications LOGIN PASSWORD 'notifications';

-- База сервиса и база для его тестов; владелец — роль сервиса.
CREATE DATABASE notifications OWNER notifications;
CREATE DATABASE notifications_testing OWNER notifications;

-- Роль сервиса не должна даже подключаться к базе монолита: закрываем
-- подключение для PUBLIC и оставляем его только владельцу базы courier,
-- поэтому таблицы users, delivery_orders, assignments и outbox_messages
-- недоступны сервису на уровне прав, а не соглашения.
REVOKE ALL ON DATABASE courier FROM PUBLIC;
REVOKE ALL ON DATABASE courier_testing FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE courier TO courier;
GRANT CONNECT, TEMPORARY ON DATABASE courier_testing TO courier;
