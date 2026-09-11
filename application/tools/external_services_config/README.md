# Внешние PostgreSQL и reverse proxy

Этот пример запускает PHP, индексатор и изолированный конвертер рядом с уже существующими PostgreSQL и nginx. Файл Compose рассчитан на запуск из корня этого репозитория:

```
docker compose -f application/tools/external_services_config/docker-compose.yml up -d --build
```

## Подготовка

Создайте внешние Docker-сети `postgres_db_net` и `flibusta_net`. Последняя должна иметь подсеть `172.101.0.0/16`: PHP получает адрес `172.101.0.101`, указанный в `flibusta.conf`. Подключите PostgreSQL к `postgres_db_net` и укажите его DNS-имя в `FLIBUSTA_DBHOST`.

Создайте в `secrets/` файлы `flibusta_pwd.txt`, `postgres_admin_pwd.txt`, `oidc_client_secret.txt`, `opds_owner_hmac_key.txt` и `smtp_password.txt`. Каждый содержит одно значение и доступен только пользователю контейнера. Пустой SMTP-пароль допустим только при пустом `FLIBUSTA_SMTP_USER`; всё равно создайте файл, поскольку Compose монтирует его как secret.

В `.env` рядом с корнем репозитория задайте, например:

```
FLIBUSTA_PUBLIC_URL=https://library.example
FLIBUSTA_WEBROOT=/mylib
FLIBUSTA_DBHOST=postgresdb
FLIBUSTA_OIDC_ISSUER=https://id.example
FLIBUSTA_OIDC_CLIENT_ID=flibusta
FLIBUSTA_SMTP_HOST=smtp.example
FLIBUSTA_SMTP_FROM=library@example
FLIBUSTA_SMTP_TO=reader@example
```

Callback у провайдера OIDC должен быть ровно `https://library.example/mylib/auth.php`. Нельзя использовать HTTP в `FLIBUSTA_PUBLIC_URL` или issuer. Остальные пределы и параметры SMTP описаны в корневом [README](../../../README.md).

## Nginx

Смонтируйте `application/public` в nginx как `/srv/mylib:ro`, а `flibusta.conf` включите в HTTPS `server` block. Замените `/mylib`, `/srv/mylib` и IP PHP, если выбрали другие значения. Конфигурация передаёт заголовок `Authorization` для OPDS Basic и схему/host для журналирования proxy. Публичный адрес берётся только из `FLIBUSTA_PUBLIC_URL`; не доверяйте клиентским `X-Forwarded-*`.

Не добавляйте независимый Basic Auth перед приложением: им управляют ключи OPDS. OIDC закрывает HTML, а OPDS без действующего ключа возвращает `401`. Не публикуйте `cache`, `Flibusta.Net`, `FlibustaSQL`, `application/tools`, `application/vendor` или `application/tests`.

## Фоновые процессы

`book-worker` применяет миграции, обнаруживает новые готовые ZIP и разбирает метаданные. `compilation-converter` не имеет сети, получает только read-only код и каталог `cache`, где обрабатывает подготовленные задания сборников. Не давайте ему доступ к PostgreSQL, SMTP или secrets.

PHP-FPM и оба обработчика запускаются с UID/GID `1026:100`. Для другого пользователя задайте `APPUSER_PUID` и `APPUSER_PGID` в `.env`. Этому UID нужны запись в `FlibustaSQL/` и `cache/`, чтение архивов и соответствующих secrets. Ручные команды наследуют его автоматически: `docker compose exec flibusta-fpm php /application/tools/app_worker.php`. При смене UID дождитесь завершения импорта, остановите сервисы и выдайте новому пользователю персональный ACL на старые закрытые файлы средствами NAS, не меняя владельцев. Затем пересоздайте сервисы. Не открывайте `cache/compilations/` всей группе `users`.

Проверьте конфигурацию перед запуском:

```
docker compose -f application/tools/external_services_config/docker-compose.yml config --quiet
```

`FLIBUSTA_PUBLIC_URL` задаёт только origin без пути; `/mylib` задаётся через `FLIBUSTA_WEBROOT`. Лимиты ZIP и записей, команды повторной обработки ошибок и состав автоматических проверок описаны в основном README.
