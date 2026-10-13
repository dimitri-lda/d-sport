# Sport E: Symfony 8.1 foundation

Технический каркас будущего интернет-магазина: Symfony API в корне репозитория, React + TypeScript SPA в `frontend/`, PostgreSQL, Redis cache и надёжная очередь Symfony Messenger. Локальное окружение запускается Docker Compose; production собирается в отдельный multi-stage образ FrankenPHP/Caddy.

Это первый этап. Бизнес-сущностей, предметных таблиц, товаров, fixtures, платёжных интеграций и legacy-кода здесь нет. Elasticsearch, Shopify и CDN пока не подключены. Второй этап начнётся после предоставления исходников в `legacy`; заранее переносить неизвестную бизнес-логику каркас не пытается.

## Версии и требования

Для запуска нужны Docker Engine/Docker Desktop с Linux-контейнерами, Docker Compose v2 с поддержкой `up --wait`, Git и POSIX shell. Docker daemon должен быть запущен. `make` — необязательная обёртка над исполняемым `./dev`. Локальные PHP, Composer, Node.js и PostgreSQL не требуются: рабочие команды выполняются в контейнерах. Первый запуск скачивает образы и зависимости из публичных реестров, поэтому требует доступа к сети.

На машине должны быть свободны порты 5173, 8080 и 8443; их можно изменить в локальном `.env`. Runtime поддерживает amd64/arm64 в пределах платформ опубликованных базовых образов. Контейнер web по умолчанию ограничен 1 CPU и 768 МБ; PostgreSQL, Redis, Messenger и Vite требуют дополнительной памяти Docker VM.

| Компонент | Зафиксированная версия |
| --- | --- |
| PHP в runtime-образе | 8.5.11 |
| FrankenPHP / Caddy | 1.13.1 / 2.11.7 |
| Symfony FrameworkBundle | 8.1.8; прямые Symfony-пакеты ограничены `8.1.*` |
| Symfony Runtime | 8.1.0, с native FrankenPHP worker mode |
| Doctrine ORM / DBAL | 3.7.5 / 4.5.0 |
| DoctrineBundle / DoctrineMigrationsBundle | 3.3.2 / 3.7.0 |
| MonologBundle / Monolog | 4.1.0 / 3.12.1 |
| PostgreSQL / Redis | 18.6 / 8.10.2 |
| Composer image | 2.10.3 |
| Node.js | 24.21.0; frontend требует `>=24.15.0 <25` |
| React / React Router | 19.3.0 / 8.4.0 |
| Vite / TypeScript | 8.3.4 / 6.0.3 |
| Tailwind CSS / официальный Vite plugin | 4.3.3 / 4.3.3 |
| TanStack Query | 5.104.2 |
| PHPUnit / PHPStan / PHP-CS-Fixer | 13.4.2 / 2.3.1 / 3.95.27 |
| Vitest / ESLint / Prettier | 5.0.4 / 10.12.0 / 3.9.10 |

PHP 8.5 выбран как совместимая стабильная версия и среда для текущих dev tools. Точные транзитивные зависимости находятся в `composer.lock` и `frontend/package-lock.json`; базовые Docker-образы закреплены digest в Dockerfile и Compose. Для воспроизводимой установки используют `composer install` и `npm ci`, не `update`.

## Первый запуск одной командой

Из корня нового checkout:

```sh
./dev init
```

Команда копирует `.env.example` в игнорируемый Git файл `.env`, генерирует случайный `APP_SECRET`, собирает dev-образы, устанавливает зависимости в Docker volumes и поднимает окружение с ожиданием healthchecks. Уже существующий `.env` сохраняется. PostgreSQL/Redis запускаются первыми, отдельный service `migrate` применяет версионированные migrations, затем запускаются backend, worker и Vite.

Пароль PostgreSQL из `.env.example` предназначен только для локальной разработки; вручную вводить секреты для `./dev init` не нужно. `.env`, зависимости, runtime cache, сертификаты и backups исключены из Git. PostgreSQL и Redis не публикуют порты наружу.

При работающем `make` можно использовать `make init`: Makefile вызывает ту же команду. На macOS системный `make` может отказываться работать до принятия лицензии Xcode. В проверенном окружении это ограничение присутствует; `./dev` позволяет запускать проект и все проверки без изменения системных настроек или принятия лицензии.

| Адрес | Назначение |
| --- | --- |
| [http://localhost:5173](http://localhost:5173) | React technical shell и индикатор backend |
| [http://localhost:8080/api/health/live](http://localhost:8080/api/health/live) | Liveness |
| [http://localhost:8080/api/health/ready](http://localhost:8080/api/health/ready) | PostgreSQL + Redis readiness |
| [http://localhost:8080/api/version](http://localhost:8080/api/version) | Имя приложения, окружение, версия сборки |
| [https://localhost:8443/api/health/live](https://localhost:8443/api/health/live) | Локальный HTTPS backend |

Frontend использует относительный `/api`; Vite проксирует его в `http://backend:8080`. CORS не требуется. Индикатор проверяет liveness и показывает loading/network error; timeout API client — 3 секунды, автоматический retry ограничен одним повтором, предусмотрена ручная повторная проверка.

Проверка состояния контейнеров:

```sh
docker compose ps
curl --fail http://localhost:8080/api/health/live
curl --fail http://localhost:8080/api/health/ready
```

Успешный liveness возвращает `{"status":"ok"}` без обращения к базе или Redis. Readiness действительно выполняет PostgreSQL `SELECT 1` и Redis `PING`: при недоступности зависимости возвращает HTTP 503 и состояния `checks`. Неизвестный `/api/*` маршрут возвращает HTTP 404 в формате `{"error":{"code":"not_found","message":"Not Found"}}`. Внутренние исключения, DSN и секреты не попадают в ответы API.

### Доверие локальному HTTPS

Caddy создаёт локальный сертификат в volume `caddy_data`; автоматическая установка CA на хост отключена. HTTP подходит для обычной разработки и Vite proxy. Если нужен HTTPS в браузере, экспортируйте только публичный корневой сертификат и добавьте его в доверенные сертификаты своей системы:

```sh
mkdir -p certificates
docker compose cp backend:/data/caddy/pki/authorities/local/root.crt certificates/caddy-local-root.crt
```

Например, на macOS:

```sh
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain certificates/caddy-local-root.crt
```

Для других ОС используйте штатное хранилище доверенных CA; некоторые браузеры имеют отдельное хранилище. Приватный ключ CA не экспортируйте. Каталог `certificates/` игнорируется Git. При удалении `caddy_data` создаётся новая CA и прежнее доверие необходимо обновить. Caddy предоставляет HTTP/2 и HTTP/3; HTTP/3 также требует доступного UDP-порта.

## Структура

```text
.
├── bin/                       # Symfony console
├── config/                    # Routes, services, Doctrine, Cache, Messenger
├── migrations/                # Версионированная инфраструктурная схема
├── public/                    # PHP entrypoint
├── src/                       # Тонкие controllers, health services, API errors
├── tests/                     # Backend checks и performance smoke
├── frontend/                  # React / TypeScript / Vite / Tailwind
├── docker/                    # Caddy, PHP, frontend dev image
├── scripts/                   # Инициализация env и изолированная test DB
├── docs/                      # Архитектура и входные сведения для legacy analysis
├── compose.yaml               # Базовые services и production runtime
├── compose.override.yaml      # Автоматически подключаемая dev-конфигурация
├── compose.prod.yaml          # Production port mappings
├── Dockerfile                 # Dev target и multi-stage production build
├── .env.example               # Локальные значения и обязательные ENV
├── dev                        # Исполняемый интерфейс команд без зависимости от make
├── Makefile
├── composer.json
└── composer.lock
```

Symfony находится в корне, отдельным приложением выделен только frontend. В runtime-образе Symfony расположен в `/app`, production SPA — в `/app/frontend-dist`. Caddy направляет `/api` в `/app/public/index.php`, отдаёт assets напрямую и применяет `index.html` fallback к frontend-маршрутам. Неизвестные API-маршруты не подменяются SPA.

## Команды разработчика

Команды выполняются из корня и используют локальный Compose. Makefile предоставляет одноимённые обёртки (`make up`, `make test` и другие):

| Команда | Действие |
| --- | --- |
| `./dev init` | Подготовить новый checkout и запустить всё окружение |
| `./dev up` | Собрать изменённые dev-образы и поднять services |
| `./dev down` | Остановить services; сохранить постоянные volumes |
| `./dev logs` | Общие логи |
| `./dev test` | Backend tests в отдельной базе, затем frontend tests |
| `./dev lint` | Composer, PHPStan, PHP-CS-Fixer, Symfony lint, Doctrine, TS/ESLint/Prettier |
| `./dev format` | Исправить форматирование backend и frontend |
| `./dev build` | Проверить frontend production build и собрать production image |
| `./dev migrate` | Явно применить migrations отдельным service |
| `./dev console debug:router` | Symfony console (`make console ARGS='debug:router'`) |
| `./dev shell` | Shell backend-контейнера |
| `./dev worker-logs` | Логи Messenger consumer |
| `./dev worker-start` / `./dev worker-stop` | Запустить / остановить worker |
| `./dev failed-show` | Посмотреть failure queue |
| `./dev failed-retry ID --force` | Повторно отправить выбранное failed message |

`./dev down` не удаляет данные PostgreSQL. Команды `down --volumes` и `volume rm` удаляют данные; для обычной остановки они не нужны. После изменения lock-файлов повторите `./dev init`, чтобы обновить зависимости в named volumes.

## Окружение и timeout

`.env.example` перечисляет обязательные переменные. `.env` обслуживает локальный Compose и Symfony; production использует отдельный игнорируемый `.env.prod.local` или внешнее хранилище секретов. В production dotenv отключён и Symfony получает реальные environment variables от Compose.

| Переменные | Назначение / локальные значения |
| --- | --- |
| `APP_SECRET`, `APP_NAME`, `BUILD_VERSION` | Секрет приложения, отображаемое имя, идентификатор сборки |
| `APP_ENV`, `APP_DEBUG` | Dev: `dev` / `1`; production runtime: `prod` / `0` |
| `COMPOSE_PROJECT_NAME` | Изоляция имён services, images и volumes |
| `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` | База и credentials; локальные placeholders |
| `DATABASE_URL` | DSN для прямого Symfony-запуска; Compose формирует его из `POSTGRES_*` |
| `POSTGRES_MAX_CONNECTIONS` | Общий server limit PostgreSQL: 50 |
| `DB_CONNECTION_TIMEOUT`, `DB_STATEMENT_TIMEOUT_MS` | Connect 2 секунды; SQL statement timeout 3000 мс |
| `REDIS_URL` | Прикладной cache provider: `redis://redis:6379` |
| `REDIS_CONNECT_TIMEOUT`, `REDIS_READ_TIMEOUT` | По 1 секунде |
| `REDIS_MAXMEMORY` | Redis memory budget: `128mb` |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=false` |
| `MESSENGER_TIME_LIMIT`, `MESSENGER_MEMORY_LIMIT` | Ротация consumer: 3600 секунд / мягкий memory limit |
| `FRANKENPHP_WORKERS`, `FRANKENPHP_MAX_THREADS` | Два web workers; общий максимум PHP threads — четыре |
| `PHP_MEMORY_LIMIT`, `WORKER_PHP_MEMORY_LIMIT` | PHP memory limits web / CLI consumer |
| `WEB_CPUS`, `WEB_MEMORY_LIMIT`, `WORKER_MEMORY_LIMIT` | Resource limits контейнеров |
| `TRUSTED_PROXIES`, `TRUSTED_HOSTS` | Допустимые proxy IP/CIDR и host regex |
| `HTTP_PORT`, `HTTPS_PORT`, `FRONTEND_PORT` | Локальные host ports: 8080 / 8443 / 5173 |
| `PUBLIC_HTTPS_PORT` | Публичный HTTPS-порт для HTTP redirect; локально 8443, production 443 |
| `SERVER_NAME` | Caddy domain: локально `localhost`, в production реальный DNS hostname |

Compose преобразует `DB_CONNECTION_TIMEOUT` в libpq `PGCONNECT_TIMEOUT`, а `DB_STATEMENT_TIMEOUT_MS` — в `PGOPTIONS=-c statement_timeout=...`. Поэтому лимиты действуют и на прямые Doctrine-подключения. При запуске PHP вне Compose эти libpq-переменные требуется задать самостоятельно. Общая длительность readiness может включать последовательную проверку нескольких зависимостей; endpoint не обещает единый трёхсекундный deadline.

Пустой `TRUSTED_PROXIES` означает недоверие к proxy headers. При добавлении reverse proxy/CDN указывайте его точные адреса или CIDR и сохраняйте host allowlist. Произвольное доверие всем клиентам или `TRUSTED_HOSTS=.*` не требуется. Для прямого локального доступа к PostgreSQL/Redis используйте отдельный override с привязкой к `127.0.0.1`; стандартный Compose оставляет их в своей внутренней сети.

## PostgreSQL, migrations и Redis

PostgreSQL — единственная основная реляционная база. `postgres_data` хранит данные; путь volume соответствует PostgreSQL 18. Первая migration создаёт только `messenger_messages`; Doctrine также ведёт служебную `doctrine_migration_versions`. Других таблиц, ORM business mappings и тестового наполнения нет.

`migrate` выполняется перед запуском web. `auto_setup=false` запрещает Messenger создавать свою таблицу на рабочем запросе/старте consumer. Для просмотра и применения версии:

```sh
./dev console doctrine:migrations:status
./dev migrate
./dev console doctrine:migrations:up-to-date
```

Production использует эти версионированные migrations. `doctrine:schema:update --force` не является частью deployment. При появлении предметной схемы отдельно потребуется совместимость migrations со старой и новой версиями приложений при rolling deployment.

`cache.app` использует Redis с короткими сетевыми timeout. `cache.system` сохраняет стандартный адаптер Symfony. Redis предназначен только для восстанавливаемого кэша: memory limit 128 МБ, eviction `allkeys-lru`, AOF и snapshots отключены. Перезапуск Redis может очистить кэш. Критические очереди и долговечные данные не хранятся в этом Redis.

## Messenger worker и failure queue

`async` и `failed` — отдельные queue names в PostgreSQL `messenger_messages`. Основной transport имеет три retry с задержками от одной секунды; после исчерпания повторов сообщение перемещается в failure transport. Бизнес-сообщений и handlers на этом этапе нет.

Consumer работает отдельным service с `init`, `pcntl`, передачей сигналов через `exec` и `stop_grace_period: 60s`. Время и память ограничены; `restart: unless-stopped` запускает consumer снова после плановой ротации. Container stop передаёт SIGTERM; будущие долгие handlers потребуют согласовать grace period, redelivery timeout и идемпотентность. Doctrine delivery может повториться, поэтому exactly-once не заявляется. Healthcheck worker проверяет процесс consumer и PostgreSQL.

```sh
./dev worker-logs
./dev console messenger:stats
./dev failed-show
./dev failed-show ID -vv
./dev failed-retry ID --force
./dev worker-stop
./dev worker-start
```

Перед retry выясняют причину сбоя и последствия повторной обработки. Для production те же console-команды выполняются через явный production Compose, например:

```sh
docker compose --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml exec backend php bin/console messenger:failed:show
```

## Проверки качества

После запуска окружения:

```sh
./dev test
./dev lint
./dev build
```

Backend использует PHPUnit, PHPStan, PHP-CS-Fixer, `composer validate --strict`, Symfony `lint:yaml`/`lint:container`, валидацию ORM mapping и проверку актуальности migrations. Скрипт тестов очищает test cache, создаёт отдельную базу с уникальным test suffix, применяет migrations и удаляет её через cleanup trap даже после ошибки тестов. Runtime dev DB не служит хранилищем временных тестовых данных. Новые PHP-файлы используют строгую типизацию и атрибуты.

Frontend проверяется TypeScript, ESLint, Prettier и Vitest/Testing Library; `npm run build` выполняет typecheck и Vite build. Тесты проверяют API client и состояние технического интерфейса, включая ошибки и ограниченные повторы.

Результаты реального выполнения команд:

| Проверка | Статус |
| --- | --- |
| Чистая копия исходников, `./dev init`, Compose healthchecks | Пройдено: без `.env`, vendor и node_modules; новые volumes и пустая база |
| Health endpoints и отказ зависимостей | Пройдено: live 200, ready 200/503; восстановление PostgreSQL/Redis в production worker mode |
| Backend tests, static analysis, style, configuration | Пройдено: 13 тестов, 71 assertion; PHPStan max, style, Composer, Symfony, Doctrine |
| Frontend tests, lint, typecheck, production build | Пройдено: 12 тестов; все проверки и Vite build |
| Production image, запуск, SPA/API/static cache headers | Пройдено: rootless runtime, healthcheck, HTTP/2, SPA, gzip, cache headers и публичный HTTP/3 Alt-Svc |
| k6 2.3.0 liveness smoke | Пройдено: 3/3 запроса, 6/6 проверок, ошибок нет |
| Git: секреты и runtime-файлы | Пройдено: зависимости, env, cache и сертификаты исключены; реальные секреты отсутствуют в исходниках |

Технический shell и 404 проверены браузером во время разработки; финальный Compose проверен по HTTP и component tests. Повторная browser-автоматизация в текущем macOS-сеансе недоступна из-за разрешений Accessibility/Screen Recording. Для обычного открытия frontend эти разрешения не нужны.

## Production build и запуск

Production запускается с **явными** `-f compose.yaml -f compose.prod.yaml`: это исключает автоматически подключаемый `compose.override.yaml`, dev bind mounts, Vite и dev dependency volumes. `./dev prod-build`/`./dev prod-up` и их Makefile-обёртки уже используют такие флаги. Не запускайте production обычным `docker compose up`.

Подготовьте отдельный игнорируемый файл окружения:

```sh
cp .env.example .env.prod.local
openssl rand -hex 32
openssl rand -hex 32
```

Сохраните два разных сгенерированных значения как `APP_SECRET` и `POSTGRES_PASSWORD`, затем настройте файл для своего deployment:

```dotenv
COMPOSE_PROJECT_NAME=sport-e-prod
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=REPLACE_WITH_RANDOM_APP_SECRET
POSTGRES_PASSWORD=REPLACE_WITH_RANDOM_DATABASE_PASSWORD
BUILD_VERSION=REPLACE_WITH_UNIQUE_RELEASE_ID
SERVER_NAME=shop.example.com
TRUSTED_HOSTS='^(shop\.example\.com|127\.0\.0\.1)$'
TRUSTED_PROXIES=
HTTP_PORT=80
HTTPS_PORT=443
PUBLIC_HTTPS_PORT=443
```

Placeholder-значения заменяются перед запуском. Разные `COMPOSE_PROJECT_NAME` отделяют local и production volumes; `BUILD_VERSION` должен меняться для новой сборки. DNS hostname, firewall и TCP 80/443 должны позволять Caddy получить ACME-сертификат; UDP 443 нужен для HTTP/3. Loopback host оставлен в allowlist для внутреннего healthcheck. Если TLS завершает внешний reverse proxy, его адреса и публичный origin необходимо настроить отдельно.

Для `POSTGRES_PASSWORD` удобно использовать hex из примера: это URL-safe значение. В Compose `DATABASE_URL` формируется строкой из `POSTGRES_*`; специальные символы в username/password нельзя вставлять в DSN без percent encoding. Если нужна произвольная строка пароля, адаптируйте DSN-конфигурацию с корректным URL encoding и отдельным настоящим паролем для PostgreSQL. Не коммитьте production env-файл.

```sh
./dev prod-build
./dev prod-up
```

Эквивалентные команды без Makefile:

```sh
docker compose --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml build
docker compose --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml up -d --wait --wait-timeout 180
docker compose --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml ps
```

`migrate` применяет migrations перед web/worker. Новый release ID обеспечивает обновление services вместе с собранным образом. Сборка не подключается к PostgreSQL/Redis и не требует production credentials для warmup.

Финальный образ содержит PHP runtime, Symfony production dependencies и frontend `dist`; Node.js, frontend-исходники, PHPUnit/PHPStan/CS-Fixer и Composer CLI в финальный runtime не входят. Приложение работает от пользователя `app` (UID 1000), с writable `var` и volumes Caddy. Включены OPcache и realpath cache, Composer classmap authoritative, прогрев prod cache и FrankenPHP worker mode; Xdebug и JIT отсутствуют.

Caddy отдаёт хэшированные `/assets/*` с `Cache-Control: public, max-age=31536000, immutable`, а `index.html` — с revalidation `no-cache`. API получает `no-store`. Сжатие статики и security headers настроены в Caddy; dev-конфигурация сохраняет работу Vite HMR. При обновлении PHP-кода production workers перезапускают: OPcache timestamp validation отключена.

Остановка production сохраняет volumes:

```sh
./dev prod-down
```

Для другого env-файла используйте `PROD_ENV=/absolute/path/to/environment-file ./dev prod-up` либо `make prod-up PROD_ENV=/absolute/path/to/environment-file`.

## Worker mode, ресурсы и будущая нагрузка

FrankenPHP worker mode сохраняет PHP-процесс между запросами. Symfony 8.1 поддерживает его через native runtime и сбрасывает известное состояние; собственные будущие сервисы должны учитывать lifecycle/reset. Не сохраняйте Request, авторизацию, транзакцию или данные отдельного запроса в mutable/static-полях долгоживущих сервисов. Корректность проверяют последовательными запросами с разными контекстами и после ошибок.

Для lazy Redis provider явно зарегистрирован `kernel.reset`: factory возвращает стандартный resettable proxy, но объявленный тип `Redis` скрывает этот интерфейс от autoconfiguration. Это предотвращает сохранение потерянного соединения после перезапуска Redis; regression test проверяет readiness и чтение кэша через тот же kernel и cache service. Native Symfony runtime также автоматически обновляет worker после 500 запросов по умолчанию.

Стартовые лимиты консервативные: два web workers, максимум четыре PHP threads, web PHP memory limit 128 МБ при container limit 768 МБ и 1 CPU. Для Messenger задаётся отдельный PHP limit 256 МБ, мягкий лимит consumer 128 МБ и container limit 384 МБ. `num_threads` FrankenPHP добавляет обычные PHP threads поверх workers, а `max_threads` ограничивает их суммарное количество. PHP memory limit ограничивает отдельное выполнение, а не весь контейнер; cache/runtime overhead также занимает память.

Worker count, PostgreSQL connections, SQL timeout и Redis memory уточняются измерениями на целевом VPS. Бюджет PostgreSQL учитывает web-реплики, consumers, migrations и служебные подключения; увеличение workers само по себе не ускоряет систему. Для будущего каталога 100 000–1 000 000 товаров потребуется анализ схемы и запросов, индексов, пагинации, import batches и памяти ORM.

Место для измерений — [tests/performance/README.md](tests/performance/README.md). Минимальный k6 smoke делает три запроса одним VU к liveness:

```sh
k6 run tests/performance/health-smoke.js
```

k6 устанавливается отдельно. Smoke подтверждает HTTP-контракт, не производительность магазина. Цель более 1000 публичных RPS с CDN относится к будущей витрине; каркас не доказывает её достижение. Полноценную нагрузку запускают отдельно на разрешённом target с реальными сценариями и наблюдаемостью origin/CDN.

## Резервное копирование и эксплуатационные ограничения

Постоянный Docker volume обеспечивает хранение данных между перезапусками, но не заменяет backup. До реального production-запуска обязательны регулярные PostgreSQL backups, защищённое хранение вне VPS, retention и проверяемое восстановление. Схема и очередь Messenger находятся в одной базе и попадают в её backup; Redis cache резервного копирования не требует.

Пример ручного логического backup без публикации порта базы:

```sh
mkdir -p backups
backup_file="backups/postgres-$(date -u +%Y%m%dT%H%M%SZ).dump"
docker compose --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml exec -T postgres sh -c 'exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$backup_file"
```

Это пример операции, а не установленная система резервирования. Каталог `backups/` игнорируется Git; production-дампы могут содержать конфиденциальные данные. Восстановление проверяют в отдельной среде; RPO/RTO, PITR, мониторинг, ротация секретов и rolling deployment сейчас не реализованы.

Compose рассчитан на локальную разработку и стартовое развёртывание на одном хосте. Высокая доступность PostgreSQL, orchestration нескольких VPS, поисковый индекс, CDN caching и синхронизация Shopify остаются следующими решениями. Внешние сервисы не устанавливаются автоматически.

## Следующий этап

Архитектурные решения и точки расширения описаны в [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). На втором этапе в корень добавляется `legacy` с Laravel-приложением, предыдущей пробой в `legacy/symfony-api` и доступной схемой/документацией. Перечень сведений для анализа находится в [docs/NEXT_LEGACY_ANALYSIS.md](docs/NEXT_LEGACY_ANALYSIS.md).

До этого анализа не определены бизнес-контракты, перенос сущностей и данных, интеграционная совместимость или порядок переключения трафика. Push и PR не создаются автоматически.
