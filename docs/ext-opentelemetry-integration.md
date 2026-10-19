# spiral/otel-bridge × ext-opentelemetry: проектная записка

Статус: исследование 2026-10-16; решения приняты 2026-10-17 и реализованы в ветке `feat/ext-opentelemetry` (раздел «Решения» описывает то, что сделано).

Обозначения:
- **[Ф]** — проверенный факт с источником.
- **[В]** — вывод или рекомендация. Не проверялось запуском.

Прочитанные версии:
- В `vendor` репозитория: open-telemetry/api 1.10.0, sdk 1.15.0, context 1.5.0, exporter-otlp 1.4.0, sem-conv 1.44.0 и spiral/* 3.17.2.
- Upstream-репозитории, прочитанные на этих коммитах:
  - opentelemetry-php @ fa9ce32 (2026-10-13)
  - opentelemetry-php-contrib @ 4a91c33 (2026-10-15)
  - opentelemetry-php-instrumentation @ 416edaa (ext 1.4.2)
  - opentelemetry.io @ 7f94db6
  - FriendsOfOpenTelemetry/opentelemetry-bundle @ c0578f3

---

## 0. Кратко

- **[Ф]** Bridge уже делит с авто-инструментацией *контекст*: оба используют `Context::storage()`. Scoped-спаны Spiral становятся родителями спанов из хуков, а спаны из хуков — родителями спанов Spiral. Чего bridge *не* делит, так это **провайдер, конвейер экспорта, flush и пропагатор**. Сегодня при `OTEL_PHP_AUTOLOAD_ENABLED=true` в приложении работают два конвейера SDK:
  - провайдер bridge из контейнера экспортирует спаны Spiral и сбрасывается после каждого запроса;
  - провайдер автолоадера в `Globals` экспортирует спаны из хуков и сбрасывается только на выходе из процесса, поэтому под RoadRunner его спаны опаздывают или застревают.
- **[Ф]** HTTP-стек Spiral — чистый PSR-15. `Http`, `Pipeline`, `LazyPipeline`, `Router`, маршруты и `CoreHandler` реализуют `RequestHandlerInterface`, а middleware — `MiddlewareInterface`. Поэтому `opentelemetry-auto-psr15` добавляет:
  - второй SERVER-спан на `Http::handle`;
  - INTERNAL-спан на каждый шаг рекурсии `Pipeline::handle`;
  - по спану на каждый `process()`.

  Сверху остаются собственный SERVER-спан Spiral и спаны «Middleware processing».
- **[Ф]** Авто-инструментации для Spiral или RoadRunner нет ни в contrib (в `src/` совпадений нет), ни на Packagist (поиск).
- **[В] Рекомендуемое направление:**
  1. Сделать конвейер bridge единственным. Bridge регистрирует свой провайдер **и пропагатор** как глобальные в контексте через публичный `Configurator` — тем же механизмом, что и `SdkBuilder::buildAndRegisterGlobal()`.
  2. Flush и shutdown остаются за bridge. Тогда его сброс после каждого запроса покрывает и спаны из хуков.
  3. Дать опциональный режим, в котором bridge берёт всё из `Globals`, — для тех, кто настраивает SDK через автолоадер или `OTEL_CONFIG_FILE`.
  4. Задокументировать `OTEL_PHP_DISABLED_INSTRUMENTATIONS=psr15` для приложений на Spiral. По желанию — подавление дублей по типу спана из SDK.
- **[В] Правило из #16 при авто-инструментации выдерживает.** Более того, оно *нужно*, чтобы дерево было связным. Есть одна ловушка: bridge обязан регистрировать пропагатор вместе с провайдером (см. §4).

---

## Решения (2026-10-17)

Одна фича, один PR, мажорный релиз: обратная совместимость не держится. Bridge должен работать и с ext-opentelemetry, и без него. С расширением поведение может отличаться в деталях (например, в части PSR-15), но результат должен быть эквивалентным. Работу, которую лучше делают хуки, bridge им отдаёт.

1. **Состав PR.** #18, #19, #17 и интеграция с ext. Каждая тема — отдельный коммит.
2. **Провайдер.**
   - По умолчанию bridge сам строит провайдер и в `init()` бутлоадера регистрирует его в `Globals` через `Configurator` вместе с пропагатором: `Context::storage()->attach(...)`. Провайдер регистрируется как `LateBindingTracerProvider`, так что он по-прежнему строится лениво. При `finalize(true)` регистрация снимается (`detach`), этим занимается `Trace\ContextFinalizer`.
   - При `OTEL_PHP_AUTOLOAD_ENABLED=true` bridge берёт провайдер и пропагатор из `Globals`. Такой провайдер финализатор только сбрасывает (`forceFlush`) и никогда не закрывает (`shutdown`): у `TracerProviderFinalizer` флаг `owned: false`, а свой `ShutdownHandler` bridge не регистрирует. Провайдер берётся из `Globals` уже при загрузке, чтобы сброс был зарегистрирован, даже если спаны создают только хуки. Если в `Globals` провайдер не из SDK, контейнер отдаёт `NoopTracerProvider` SDK.
   - Флаг автолоадера читается через `Configuration::getBoolean(OTEL_PHP_AUTOLOAD_ENABLED)`: `SdkAutoloader::isEnabled()` помечен `@internal`.
3. **Дубль SERVER-спана.** Подавление по типу спана (`SpanKindSuppressionStrategy`, SDK ≥ 1.9): SERVER-спан Spiral внутри SERVER-спана psr15 схлопывается. Подавляется любой не-INTERNAL спан внутри спана того же вида на любой глубине, в том числе без ext (например, CLIENT внутри CLIENT).
4. **Дубли middleware.** Своя стратегия подавления `Trace\DuplicateSpanSuppressionStrategy`, вариант В из §3.2. Она составная: подавление по типу спана плюс подавление INTERNAL-спанов scope `io.opentelemetry.contrib.php.psr15`. SERVER-спан psr15 остаётся. Без ext дерево такое же, только корень — SERVER-спан Spiral. Стратегия ставится только на провайдер, который строит bridge; в режиме автолоадера подавление настраивает SDK.
   - Страховка: в `require` — `open-telemetry/sdk: >=1.9 <1.16`, в `conflict` — `open-telemetry/opentelemetry-auto-psr15: <1.1 || >=1.4` (scope и виды спанов сверены по исходникам 1.1.0 и 1.3.0). Подавление проверяют Feature-тест без ext (`DuplicateSpanSuppressionTest`) и приёмочный тест с ext (`AutoInstrumentationTest`).
   - В README вариантом описан `OTEL_PHP_DISABLED_INSTRUMENTATIONS=psr15`.
5. **Самотрассировка экспортёра.** Процессор SDK экспортирует под контекстом, в котором он создан (`exportContext`), а не под контекстом `forceFlush`. Поэтому bridge строит процессор под контекстом с валидным несемплированным спаном (`Trace\UnsampledContext`), а `forceFlush` и `shutdown` в финализаторе тоже выполняются под таким контекстом. Семплер `ParentBased` отбрасывает спаны хуков, созданные во время экспорта. Без этого `SimpleSpanProcessor` с `auto-psr18` уходит в бесконечный цикл: каждый экспорт порождает спан, который экспортируется сразу. Тесты: Feature `ExporterSelfTracingTest` (хук имитируется спаном из `Globals` внутри экспортёра) и приёмочный с `auto-psr18`. В режиме автолоадера SDK строит процессор внутри инициализатора `Globals`, под контекстом с Noop-провайдерами, так что самотрассировки там нет.
6. **Instrumentation scope.**
   - Имя `spiral/otel-bridge`.
   - Версия читается из `resources/version.json`: это манифест release-please, перенесённый из `.github/` и включённый в дистрибутив через `.gitattributes`. Файл читается один раз при первом обращении (`InstrumentationScope::version()`). Если его нет или он битый, передаётся `null`.
   - В будущем: scope по компоненту (`spiral/http`, `spiral/queue`), если его начнёт передавать `spiral/telemetry`.
7. **Метрики и логи.** Сброс `meterProvider` и `loggerProvider` — следующий шаг, не в этом PR.
8. **Утёкший scope.** Если после `finalize(false)` в контексте остался валидный активный спан, `ContextFinalizer` пишет предупреждение через лог SDK (`LogsMessagesTrait`) с trace id и span id.
9. **Версии.** `open-telemetry/sdk: >=1.9 <1.16`, `open-telemetry/api: ^1.7` и `open-telemetry/context: ^1.4` (минимумы sdk 1.9.0). ext-opentelemetry — только в `suggest`.
10. **Пропагатор.** Строится из `OTEL_PROPAGATORS` (`PropagatorFactory`), синглтон, тот же экземпляр регистрируется в `Globals`. `TracestateGetter` сохраняется. Входящий контекст извлекается один раз на `Tracer`; `getContext()` передаёт дальше входящий baggage, если код не активировал свой.
11. **`getContext()` (#17).** Правило «стек выбирает, `Context` уточняет», см. §3.5. На `@todo Question` ответ — «да»: после всех трейсов возвращается последний завершившийся корень.
12. **Статусы (#19).**
    - Строки сравниваются без учёта регистра и после `trim`: `ok` → OK, `error` → Error, всё остальное → Unset.
    - Целые числа:
      - `0`, `1`, `2` — значения enum OTLP (Unset, Ok, Error);
      - 100–399 → Unset;
      - 400–499 → Unset для SERVER, Error для остальных видов;
      - 500–599 → Error;
      - любое другое число → Error, потому что такой код нельзя интерпретировать (semconv).
13. **Исключение в колбэке (#18).**
    - Имя и атрибуты переносятся и на этом пути.
    - Статус становится Error с сообщением исключения.
    - OK, выставленный колбэком до исключения, остаётся: в спецификации OK финальный.
    - Error, выставленный колбэком, сохраняет своё описание.
14. **CI.**
    - Отдельный workflow `ext-opentelemetry.yml` с `extensions: opentelemetry`, PHP 8.2 и 8.5, lowest и highest; `auto-psr15` и `auto-psr18` ставятся только в нём (`composer require --dev --no-update`). Там запускается только `tests/Acceptance/AutoInstrumentationTest.php`.
    - Сценарий лежит в `tests/Process/http-request.php`: один запрос через `Http::handle` с входящим `traceparent`, затем `finalize(false)`, `finalize(true)` и спан через `Globals`. Тесты запускают его отдельным процессом PHP на каждый сценарий и читают спаны из Trap; номер OTLP-запроса у спана показывает, каким сбросом он ушёл.
    - `GlobalsTest` гоняет тот же сценарий без ext в обычной матрице: регистрация в `Globals` и режим автолоадера.
    - Остальные тесты идут без расширения.

---

## 1. Как работает ext-opentelemetry

### 1.1 API хуков

**[Ф]** Сигнатура: `OpenTelemetry\Instrumentation\hook(?string $class, string $function, ?Closure $pre, ?Closure $post): bool` (`ext/opentelemetry.stub.php`).
- pre-хук получает `($obj|class-string|null, array $params, string $class, string $function, ?string $filename, ?int $lineno, ...)`. Он может вернуть массив, который *подменяет* параметры; psr15 так подменяет запрос.
- post-хук получает `($obj, array $params, $return, ?Throwable $e)`. Он может подменить возвращаемое значение, но только если у хука объявлен тип возврата. Может и бросить исключение на замену (README, разделы «Parameters», «Return values» и «Exceptions»).

**[Ф]** Хук на метод **интерфейса или родителя** срабатывает для каждой реализации. `find_class_observers()` обходит `ce->parent` и `ce->interfaces` (`ext/otel_observer.c:963-993`; тесты `hook_interface_method.phpt` и `hook_inherited_method.phpt`).

**[Ф]** Если на функцию повешено несколько хуков, pre выполняются в порядке FIFO, post — в порядке LIFO (`tests/multiple_hooks_execution_order.phpt`).

**[Ф]** Исключение, брошенное *внутри* хука, ловится и превращается в PHP warning. Наблюдаемый вызов продолжается (`tests/hooks_throw_exception.phpt`).

**[Ф] Ограничения** (README, «Caveats» и «Restrictions»):
- хуки нужно зарегистрировать до первого выполнения функции;
- функции, встроенные оптимизатором, не наблюдаются;
- хуки нельзя снять — в stub нет `unhook`;
- хуки через атрибуты (`#[WithSpan]`) по умолчанию выключены (`opentelemetry.attr_hooks_enabled`) и допускают только один хук на функцию;
- конфликтующее расширение отключает ext (`opentelemetry.conflicts`; известен SourceGuardian);
- внутренние функции PHP наблюдаются только на PHP 8.2+.

**[Ф]** **Встроенного подавления** у хуков нет. Хуки старого стиля вызывают `hook()` напрямую. `HookManager::disable()` уважают только инструментации в стиле SPI, которые идут через `ExtensionHookManager` (`opentelemetry-php/src/API/Instrumentation/AutoInstrumentation/ExtensionHookManager.php:41,50`).

### 1.2 Откуда пакеты авто-инструментации берут провайдер и пропагатор

**[Ф]** Все инструментации contrib, кроме Laravel, используют `new CachedInstrumentation(...)`, затем `->tracer()`, который получает `Globals::tracerProvider()` **при каждом вызове**. Laravel использует SPI `Instrumentation` и `HookManager` (grep по `contrib/src/Instrumentation`).

**[Ф]** `Globals::tracerProvider()` и `Globals::propagator()` сначала смотрят в **текущий Context**, в `ContextKeys::tracerProvider()` и `propagator()`, и только потом откатываются на статические глобальные значения (`vendor/open-telemetry/api/Globals.php:44-56`). Так с api 1.1.0 (raw `opentelemetry-php/api@1.1.0/Globals.php:43`).

**[Ф]** Статические глобальные значения строятся **один раз**, лениво, при первом обращении, из инициализаторов, зарегистрированных через `Globals::registerInitializer()`. Этот метод `@internal`, как и `Globals::reset()`. После первого обращения новые инициализаторы ни на что не влияют (`Globals.php:85-131`).

**[Ф]** `Configurator::create()->withTracerProvider()->withPropagator()->storeInContext()` вместе с `Context::storage()->attach()` — ровно то, что делает публичный `SdkBuilder::buildAndRegisterGlobal()` (`sdk/SdkBuilder.php:116-129`). Это поддерживаемый способ сделать вручную собранный SDK «глобальным».

**[Ф]** Инструментации в стиле SPI, которые регистрирует `SdkAutoloader::registerInstrumentations()`, получают провайдеры `LateBinding*`. Они обращаются к `Globals` **под `Context::getRoot()->activate()`**, поэтому регистрации в контексте не видят (`sdk/SdkAutoloader.php`, `createLateBindingTracerProvider()`). К тому же SPI-инструментации регистрируются только при `OTEL_PHP_AUTOLOAD_ENABLED=true`.

### 1.3 Автолоадер SDK

**[Ф]** `open-telemetry/sdk` указывает `_autoload.php` в composer `files`. Он вызывает `SdkAutoloader::autoload()` при каждой автозагрузке Composer, кроме случаев, когда выполняется composer-скрипт (`sdk/_autoload.php`, `sdk/composer.json`). Bridge требует sdk, так что это выполняется в каждом приложении на Spiral.

**[Ф]** `autoload()` срабатывает только при `OTEL_PHP_AUTOLOAD_ENABLED=true`, если `OTEL_PHP_EXCLUDED_URLS` не совпал. Тогда он:
1. регистрирует инициализатор `Globals` — по переменным окружения или по `OTEL_CONFIG_FILE`;
2. регистрирует SPI-инструментации;
3. при желании запускает `AutoRootSpan`, но только если задан `$_SERVER['REQUEST_METHOD']` и `OTEL_PHP_EXPERIMENTAL_AUTO_ROOT_SPAN=true`. Воркер RoadRunner этому условию никогда не удовлетворяет.

Инициализатор по переменным окружения строит свои экспортёр, процессор, провайдер, meter и logger и вызывает `ShutdownHandler::register($tracerProvider->shutdown(...))` (`sdk/SdkAutoloader.php:68-131`, `sdk/Trace/AutoRootSpan.php`).

**[Ф]** `OTEL_PHP_DISABLED_INSTRUMENTATIONS` содержит имена инструментаций или `all`. Каждый пакет проверяет его в своём `_register.php`, например `Sdk::isInstrumentationDisabled('psr15')` (`sdk/Sdk.php:38-42`; `contrib/.../Psr15/_register.php`; таблица в opentelemetry.io `content/en/docs/languages/php/sdk.md:160-176`).

### 1.4 Shutdown

**[Ф]** `ShutdownHandler::register()` держит callable по слабой ссылке, и если объекта уже нет, вызов пропускается. Обработчики выполняются в порядке **LIFO** в shutdown-функции, которая регистрируется изнутри другой shutdown-функции и поэтому уходит в самый конец очереди (`sdk/Common/Util/ShutdownHandler.php`).

**[Ф]** `TracerProvider::shutdown()` идемпотентен: повторный вызов возвращает `true` (`sdk/Trace/TracerProvider.php:102-109`, `TracerSharedState.php:104-107`). `forceFlush()` на уже закрытом провайдере доходит до процессора, а тот возвращает `false`. Bridge это уже учитывает (`TracerProviderFinalizer`).

### 1.5 Хранилище контекста и fibers

**[Ф]** По умолчанию `Context::storage()` — это `FiberBoundContextStorageExecutionAwareBC`, у него своя вершина стека на каждый Fiber. Если fiber не инициализирован, при обращении выдаётся warning (`context/Context.php:45-49`, `FiberBoundContextStorage.php:30-80`).

**[Ф]** `OTEL_PHP_FIBERS_ENABLED` читается только из `$_SERVER`. Переключению контекста между fibers нужны NTS, PHP 8.1+ и **ext-ffi**, а не ext-opentelemetry (`context/ZendObserverFiber.php`, `context/fiber/initialize_fiber_handler.php`).

**[Ф]** `Context::activate()` оборачивает scope в `DebugScope` только при включённых `assert()` и только если не задан `OTEL_PHP_DEBUG_SCOPES_DISABLED`. `DebugScope` предупреждает о пропущенном или несовпадающем `detach()`. `Context::storage()->attach()` возвращает обычный scope (`context/Context.php:78-92`, `DebugScope.php`).

### 1.6 Долгоживущие воркеры

**[Ф]** Мейнтейнер OTel (brettmc) писал в open-telemetry/opentelemetry-php#1611:
- настоящего асинхронного экспорта в PHP нет;
- batch-процессор экспортирует только тогда, когда спан завершается после истечения задержки;
- `ShutdownHandler` срабатывает только при выходе воркера;
- для RoadRunner они «вручную сбрасывают после каждого запроса» и создают корневой спан на каждый запрос.

**[Ф]** Известные падения ext:
- segfault на Octane + RoadRunner: #1003 / #1168, исправлен в ext 1.0.0RC3;
- ionCube + Octane/RR: #1995, всё ещё открыт.

**[Ф]** Ни contrib Laravel/Symfony, ни бандл FoOT не сбрасывают *трейсы* после каждого запроса:
- grep по `octane|roadrunner|swoole|forceFlush` в `contrib/src/Instrumentation/{Laravel,Symfony}` ничего не находит;
- бандл FoOT закрывает только meter-провайдеры, на `TerminateEvent`/`ConsoleTerminateEvent` (`ObservableHttpKernelEventSubscriber.php:29-32`).

Финализатор bridge со сбросом после каждого запроса (#10) здесь опережает экосистему, и главное, что стоит сделать, — распространить его на спаны из хуков.

---

## 2. Какие авто-инструментации сработают в приложении на Spiral

| Пакет | Что перехватывает | Срабатывает в Spiral | Примечания |
|---|---|---|---|
| `opentelemetry-auto-psr15` | `RequestHandlerInterface::handle`, `MiddlewareInterface::process` | **Да, и очень много.** `Spiral\Http\Http` (RequestHandler), `Pipeline`/`LazyPipeline` (оба интерфейса), `Router` (`RouterInterface extends RequestHandlerInterface`), маршруты (`RouteInterface extends RequestHandlerInterface`), `CoreHandler`, `CallableHandler`, все middleware | **[Ф]** `vendor/spiral/http/src/{Http,Pipeline,LazyPipeline,CallableHandler}.php`; spiral/framework@3.17.2 `src/Router/src/{RouterInterface,RouteInterface,CoreHandler}.php` |
| `-auto-psr18` | `ClientInterface::sendRequest` | HTTP-клиенты приложения **и собственный `PsrTransport` OTLP-экспортёра** (`sdk/Common/Export/Http/PsrTransport.php:85`) | **[Ф]** В `Psr18Instrumentation.php` защиты нет. **[В]** Один спан экспорта на каждый flush, который уйдёт в следующем flush. Самоподпитывающийся ручеёк. |
| `-auto-curl` | `curl_*` | То же, если PSR-18-клиент работает через curl (symfony/http-client, guzzle) | **[В]** Тот же риск самотрассировки |
| `-auto-guzzle` | Guzzle `transfer` | Если используется guzzle | |
| `-auto-pdo`, `-mysqli`, `-postgresql` | Вызовы драйверов | Cycle ORM / Database работают через PDO | Чистая польза: у Spiral нет спанов БД |
| `-auto-psr16` / `-psr6` | Интерфейсы кеша | `CacheRepository` из `spiral/cache` *и* его хранилища реализуют PSR-16 | **[Ф]** `vendor/spiral/cache/src/{CacheRepository,Storage/*}.php`. **[В]** Два спана на вызов. |
| `-auto-psr14` | `EventDispatcherInterface::dispatch` | `EventDispatcher` из `spiral/events` | **[Ф]** `vendor/spiral/events/src/EventDispatcher.php`. Шумно. |
| `-auto-psr3` | Логгеры | Monolog | Пересекается с `Spiral\Telemetry\Monolog\TelemetryProcessor` |
| `-auto-ext-amqp`, `-ext-rdkafka` | Вызовы расширений | Только если приложение использует эти расширения напрямую. RoadRunner Jobs общаются с RR, а не с ext-amqp. | |
| Spiral / RoadRunner | — | **Не существует** | **[Ф]** grep по `src/` в contrib; поиск по Packagist |

### 2.1 psr15 против Spiral, по шагам

Источник: `contrib/src/Instrumentation/Psr15/src/Psr15Instrumentation.php`.

**[Ф]** pre-хук на `handle` ищет корневой спан через `$request->getAttribute(SpanInterface::class)` и только при отсутствии запроса откатывается на `Span::getCurrent()`.
- Если корня нет, он строит **SERVER**-спан с именем метода. Родитель — `Globals::propagator()->extract($request->getHeaders())`, то есть **текущий локальный контекст игнорируется**.
- Спан прикрепляется к `Context::storage()`, сохраняется в атрибут запроса, и хук **возвращает изменённый запрос**.
- Каждый следующий вызов `handle` становится **INTERNAL**-спаном (`<Class>::handle`) с текущим контекстом в качестве родителя.
- post-хук вслепую делает detach у `Context::storage()->scope()` и ставит ERROR при статусе `>= 400`.

**[В]** Под RoadRunner `Dispatcher::serve()` вызывает `Http::handle($request)`, а затем `finalizer->finalize(false)` на каждый запрос (spiral/roadrunner-bridge `src/Http/Dispatcher.php:42-49`). Получается такое дерево:

```
psr15  SERVER   "GET"                      родитель = удалённый traceparent (через пропагатор Globals)
└─ spiral SERVER "GET http://..."          родитель = (правило #16) активный спан psr15
   └─ psr15 INTERNAL Pipeline::handle
      └─ spiral INTERNAL "Middleware processing [X]"
         └─ psr15 INTERNAL X::process
            └─ psr15 INTERNAL Pipeline::handle        (следующий middleware, рекурсия)
               └─ … Router::handle → spiral "Routing" (не scoped) …
```

Это примерно 3 спана на middleware плюс 2 SERVER-спана. Трейс `Routing` у Spiral не scoped (`Router.php:68`), поэтому спаны psr15 `Route::handle` и `CoreHandler::handle` висят на объемлющем спане middleware, а не на «Routing». Это соответствует документированному контракту `scoped`, но выглядит странно.

**[Ф] Как корневым спаном владеют другие:**
- contrib **Laravel** (`Hooks/Illuminate/Contracts/Http/Kernel.php`) и contrib **Symfony** (`SymfonyInstrumentation.php:43-89`) вешают хуки на свои ядра. Каждый всегда строит корневой SERVER-спан из `propagator->extract(request)`, кладёт его в атрибут запроса и добавляет `traceresponse` через `Globals::responsePropagator()`.
- **Бандл FoOT** делает то же самое через `RequestEvent` (`TraceableHttpKernelEventSubscriber.php:150-185`), использует свои провайдеры из контейнера и `Globals` не трогает.
- Ни один из них не устраняет дубли с psr15. Их стеки не PSR-15, поэтому для них вопрос не встаёт.

**[Ф]** В SDK ≥ 1.9.0 есть `@experimental` `SpanKindSuppressionStrategy`, подключаемая через конструктор `TracerProvider` (аргумент `spanSuppressionStrategy`) и `SdkDistribution`. Подавленный вложенный спан того же типа становится `Span::wrap($parentSpanContext)` — незаписываемым спаном с идентификаторами родителя (`sdk/Trace/SpanBuilder.php:132-135`). В sdk 1.8.0 файла нет, в 1.9.0 он есть (raw GitHub).

**[В]** С этой стратегией SERVER-спан Spiral внутри SERVER-спана psr15 аккуратно исчезнет:
- его дочерние спаны вложатся в спан psr15;
- `getContext()` вернёт идентификаторы спана psr15.

INTERNAL-спаны psr15 против спанов Spiral «Middleware processing» стратегия не схлопывает.

---

## 3. Варианты дизайна

### 3.1 Кто владеет провайдером

| Вариант | Как | Плюсы | Минусы |
|---|---|---|---|
| **A. Провайдер даёт bridge (регистрация в Globals)** | При загрузке, после сборки провайдера в контейнере, вызвать `Context::storage()->attach(Configurator::create()->withTracerProvider($p)->withPropagator($prop)->storeInContext())`. Так делает `SdkBuilder::buildAndRegisterGlobal()`. Держать scope всё время жизни приложения и делать detach при завершении. | Один конвейер. Сброс bridge после каждого запроса покрывает спаны из хуков. Только публичный стабильный API (api ≥ 1.1). Работает и с включённым, и с выключенным `OTEL_PHP_AUTOLOAD_ENABLED`: хуки всё равно регистрируются из composer autoload и лениво получают `Globals` при каждом вызове. Покрывает все пакеты на `CachedInstrumentation`, то есть все, кроме Laravel. | **[Ф]** SPI-инструментации (LateBinding под корневым контекстом) этого не видят. Сейчас это только Laravel, но `@todo` в `Globals` говорит, что будущее за SPI. **[Ф]** При включённом автолоадере инициализатор по переменным окружения всё равно построит второй, неиспользуемый провайдер и экспортёр, как только что-то обратится к статическим глобальным значениям, например SPI или `LateBinding`. **[В]** Прикрепить нужно в корневом fiber до первого срабатывания хука, а провайдер сейчас ленивый (строится при первом использовании). Регистрировать лениво, например обернув провайдер в `LateBindingTracerProvider`, который берёт его из контейнера, либо строить при загрузке, если расширение загружено. |
| A′. `Globals::registerInitializer()` | Зарегистрировать инициализатор, возвращающий провайдер bridge | Видно и SPI / LateBinding | `@internal`. Молча игнорируется, если к `Globals` уже обращались; порядок хрупкий. При включённом автолоадере инициализатор SDK всё равно сработает первым и построит свой конвейер. **Не рекомендуется.** |
| **B. Bridge берёт из Globals** | При `OTEL_PHP_AUTOLOAD_ENABLED=true` привязать `TracerProviderInterface` и пропагатор к `Globals::tracerProvider()` и `Globals::propagator()`. Финализатор только делает `forceFlush` и проверяет `instanceof SDK\Trace\TracerProviderInterface`, потому что `Globals` возвращает интерфейс API. | Один конвейер, настроенный переменными окружения или `OTEL_CONFIG_FILE`, который bridge сейчас не поддерживает. Видно SPI-инструментациям. Ресурсы, стратегия подавления и внутренние метрики достаются бесплатно. | Владение flush и shutdown разделено: shutdown делает `ShutdownHandler` SDK, flush — bridge (идемпотентно, см. §1.4). Bridge теряет контроль над обработкой ошибок `DeferredSpanExporter` и отчётами финализатора. Провайдеры метрик и логов не сбрасываются после каждого запроса, если bridge не возьмёт и их. |
| C. Оба, на выбор | Например, переменная `OTEL_SPIRAL_PROVIDER=bridge\|globals`, по умолчанию `globals` при `OTEL_PHP_AUTOLOAD_ENABLED=true`, иначе `bridge` | Покрывает обе группы пользователей | Больше кода и тестов |

**[В] Рекомендация: C**, по умолчанию — A, при `OTEL_PHP_AUTOLOAD_ENABLED=true` — B.

Флаг автолоадера — сильный сигнал, что пользователь хочет отдать конфигурацию SDK. Если в этом случае bridge оставит свой конвейер, экспортировать будут два конвейера, а это и есть сегодняшний баг. В любом режиме bridge обязан сбрасывать тот провайдер, который `Globals` отдаёт хукам.

### 3.2 Кто владеет корневым спаном и как убрать дубли

| Вариант | Эффект |
|---|---|
| 1. Только документация: `OTEL_PHP_DISABLED_INSTRUMENTATIONS=psr15` для Spiral | SERVER-спан и спаны middleware Spiral остаются корнем и единственными HTTP-спанами. Проще всего. Сохраняются имена и атрибуты спанов Spiral. Минус: Spiral пишет старые ключи semconv (`http.method`, `http.url`, `http.status_code`), а psr15 — актуальные (`http.request.method`, `url.full`, …). |
| 2. Подавление по типу спана (sdk ≥ 1.9, `@experimental`) | Корневым SERVER-спаном владеет psr15, SERVER-спан Spiral исчезает. INTERNAL-спаны psr15 остаются. В режиме A bridge должен передать `SpanKindSuppressionStrategy` своему `TracerProvider`. |
| 3. Bridge находит активный корень psr15 через атрибут запроса `Psr\…\SpanInterface` или SERVER-спан в контексте и дополняет его вместо создания своего SERVER-спана | Из одного `Tracer` это невозможно: `Http::handle` вызывает `trace()` без подсказки. Понадобится правка на стороне Spiral или хук на `Spiral\Http\Http::handle`, поставляемый bridge. |
| 4. Bridge поставляет свою инструментацию Spiral на `hook()` | Вне рамок: Spiral уже трассирует свои компоненты через `TracerInterface`. Хуки полезны только для стороннего кода. |

**[В] Рекомендация:** вариант 1 как рекомендация по умолчанию, вариант 2 — по желанию.

Bridge никогда не должен создавать спан за psr15. Спаны consumer'а очереди это не затрагивает: авто-инструментации для RoadRunner Jobs или `spiral/queue` нет, поэтому CONSUMER-спан Spiral по правилу #16 остаётся корнем.

### 3.3 Кто владеет пропагатором

**[Ф]** Bridge привязывает `TextMapPropagatorInterface` к `TraceContextPropagator::getInstance()` и игнорирует `OTEL_PROPAGATORS` (`OpenTelemetryBootloader::initTextMapPropagator`).

**[Ф]** Хуки используют `Globals::propagator()`. При включённом автолоадере это `PropagatorFactory` по переменным окружения (по умолчанию `tracecontext,baggage`), а при выключенном и без регистраций — **Noop** (`Configurator::createNoop()`).

**[В]** Bridge должен использовать тот же пропагатор, что и `Globals`:
- в режиме A — собрать его через `(new PropagatorFactory())->create()` и зарегистрировать вместе с провайдером;
- в режиме B — взять из `Globals`.

**[В]** `TracerFactory` фильтрует входящие заголовки по `propagator->fields()`, поэтому `baggage` и `b3` будут приниматься автоматически. Обёртка `TracestateGetter` по-прежнему нужна для `tracestate` из нескольких полей.

### 3.4 Flush и shutdown

**[Ф]** На выходе обработчики `ShutdownHandler` выполняются в порядке LIFO в самом конце очереди shutdown. Bridge регистрирует свой обработчик при сборке провайдера (лениво). SDK регистрирует свой при первой инициализации `Globals` (лениво).

**[Ф]** Двойной `shutdown()` одного провайдера безвреден.

**[В]** В режиме B bridge не должен закрывать провайдер, которым не владеет:
- `finalize(true)` Spiral из деструктора ядра или при terminate может сработать *раньше* обработчика shutdown SDK. Если bridge закроет провайдер раньше, поздние спаны пропадут. Реальный случай — спаны из хуков, завершающиеся в других деструкторах, например в пользовательском `register_shutdown_function`.
- Поэтому в режиме B bridge при terminate делает `forceFlush`, а `shutdown` оставляет SDK.

**[В]** В режиме A bridge владеет shutdown, как и сейчас. Прикреплённый им scope нужно открепить при terminate, чтобы при включённых assertions не было warning от `DebugScope` о пропущенном detach, если scope создан через `Context::activate()`. `Context::storage()->attach()` обходит отладочную обёртку полностью.

**[В] Самотрассировка экспортёра.** С установленной авто-инструментацией psr18 или curl экспорт OTLP внутри `forceFlush` порождает CLIENT-спан. Bridge сбрасывает вне спана запроса (`finalize(false)` в RR выполняется после `respond`), поэтому этот спан — новый корневой trace, он уйдёт в следующем flush, и так далее.

Возможное смягчение: выполнять `forceFlush`/`shutdown` под контекстом, где активный спан — валидный **несемплированный** `SpanContext`. Тогда семплер по умолчанию `ParentBased` отбросит спаны из хуков. `HookManager::disable()` не поможет: хуки старого стиля его игнорируют. Это нужно подтвердить тестом. Стоит спросить upstream, есть ли для этого штатный способ.

### 3.5 #16 / #17 / `scoped` и спаны из хуков

- **[Ф]** Спаны из хуков и scoped-спаны Spiral делят `Context::storage()` и вкладываются строго по LIFO:
  - ext выполняет post-хуки, даже если вызов бросил исключение;
  - `Tracer::trace()` делает detach в `finally`.

  Значит, спан из хука, активный в момент вызова `trace()` в Spiral, становится родителем спана Spiral (так сейчас без входящего контекста, а с #16 — и с ним), а scoped-спан Spiral, активный в момент срабатывания хука, становится родителем спана из хука.
- **[Ф]** Не-scoped спан Spiral (`Routing`, `Job push`) никогда не активируется, поэтому спаны из хуков внутри него привязываются к объемлющему активному спану. Это документированный контракт.

  **[В]** Это стоит описать в README: спаны БД и HTTP-клиента визуально «выпадут» из `Job push` и `Routing`.
- **Правило #16** (удалённый контекст становится родителем, только если в `Context::getCurrent()` нет валидного активного спана):
  - **[В] Выдерживает и необходимо.** Корень psr15, AutoRootSpan (FPM) или корневой спан любого framework-расширения активен *до* того, как тело `Http::handle` вызовет `trace()`. Без правила SERVER-спан Spiral стал бы соседом спана psr15 под удалённым родителем — так ведёт себя `master` сейчас, — и дочерние спаны из хуков разделились бы между ними.
  - **[Ф]** Когда хук использует Noop-провайдер, `NoopSpanBuilder::startSpan()` оборачивает контекст *родителя*. Активный «спан» тогда — сам удалённый контекст или невалидный, так что правило корректно вырождается (`api/Trace/NoopSpanBuilder.php`).
  - **[В] Противоречие 1, самое важное.** Если в режиме A bridge зарегистрирует **провайдер без пропагатора**, psr15 разберёт заголовки Noop-пропагатором из `Globals`. Он создаст валидный локальный корень без удалённого родителя, по правилу #16 Spiral вложится в него, и входящий `traceparent` **потеряется целиком**. Провайдер и пропагатор нужно регистрировать вместе.
  - **[В] Противоречие 2.** Спан, оставшийся активным между запросами в воркере (хук, чей post не выполнился после fatal, или пользовательский хук вокруг цикла RR), заставит все последующие запросы игнорировать `traceparent`. В #16 это уже отмечено. Стоит подумать, чтобы финализатор bridge при `finalize(false)` замечал непустой scope в хранилище контекста и писал об этом в лог.
  - **[В]** Расхождение: родитель корневого SERVER-спана берётся из psr15 (пропагатор `Globals`), а не из `$context` Spiral. Если пропагаторы различаются (у bridge только tracecontext, а у `Globals` — `OTEL_PROPAGATORS`, например b3), извлечённые родители могут не совпасть. Решается §3.3.
- **#17** (`getContext()`): какой спан Spiral отдаёт наружу. **[Ф]** Потребители `getContext()` в Spiral:
  - `spiral/queue` пишет его в заголовки задачи **до** трейса `Job push` (`Interceptor/Push/Core.php:42-46`), то есть отдаёт объемлющий спан;
  - `TelemetryProcessor` кладёт его в `extra.telemetry` каждой записи лога (`spiral/telemetry`, `Monolog/TelemetryProcessor.php:27`);
  - `Http::handle()` добавляет его в заголовки ответа уже после SERVER-трейса (`spiral/http`, `Http.php:120`).

  Только собственный стек `Tracer` (предложение в #17) спанов из хуков не видит. Запись лога или задача внутри middleware, обёрнутого psr15, получила бы спан Spiral-предка, а не спан psr15 `X::process`, в котором код реально выполняется. При этом `auto-psr3` берёт ids для логов из `Context::getCurrent()`, так что корреляция логов разошлась бы. Только `Context::getCurrent()` тоже не годится: не-scoped спаны не активируются, а `contextInsideNestedTracePointsToNestedTraceWhateverIsActive` (проходит сейчас) требует, чтобы внутри не-scoped спана `getContext()` указывал на него.

  **[В] Правило: стек выбирает, `Context` уточняет.**
  1. Вершина стека — scoped-спан → `Context::getCurrent()`. Там сам этот спан или его потомок, в том числе спан из хука. Если в текущем контексте нет валидного спана (чужой код сломал порядок detach), — вершина стека.
  2. Вершина стека — не-scoped спан → сам этот спан. Спаны из хуков внутри него висят на объемлющем активном спане, но код выполняется «в» этом трейсе.
  3. Стек пуст → последний завершившийся корневой спан этого `Tracer` (на это опирается `Http.php:120`). С psr15 в этот момент активен его SERVER-спан. Отдавать спан Spiral допустимо, потому что для эха `traceparent` подходит любой спан того же trace.
  4. Корня ещё не было → входящий контекст.

  Стек хранится у каждого `Tracer` и используется **только** в `getContext()`. Родителя по-прежнему выбирает `Context` (#16).

  При подавлении по типу спана подавленный спан — это `Span::wrap(parentCtx)`, так что в п. 1 и 3 окажутся идентификаторы psr15. Это допустимо.
- **#18** (статус при исключении): не пересекается, хуки пишут исключения только в свои спаны.
- **#19** (int-коды статуса): пересекается косвенно. Если корнем HTTP остаётся SERVER-спан Spiral (§3.2, вариант 1), его статус — единственный. **[В]** Маппинг int-кодов делать по semconv (SERVER: 5xx → Error, остальное → Unset; CLIENT: 4xx и 5xx → Error), чтобы `spiral/http` мог передавать сам HTTP-код и получать то же, что дал бы psr15 по spec. Сейчас `spiral/http` передаёт `'OK'` для `< 500`, и спан становится OK, а по semconv инструментация OK не ставит. Это правка на стороне spiral/http. psr15 сам ставит ERROR при `>= 400` на своём спане, что расходится с semconv для SERVER, но касается только его спана.

### 3.6 Минимальные версии

**[Ф]** Текущие ограничения: api `^1.1`, sdk `^1.3`, context неявно (через api), php `>=8.2`. Всё нужное есть уже в этих версиях:
- `Configurator`, `ContextKeys`, `CachedInstrumentation`, `Globals` с приоритетом контекста и `HookManager::disable` — в api 1.1.0;
- `Globals::registerInitializer` и `SdkAutoloader` с `ShutdownHandler` — в sdk 1.3.0;
- `Context::storage()` — в context 1.0.0.

Проверено по тегам `opentelemetry-php/{api,sdk,context}` на raw GitHub.

**[Ф]** Подавлению спанов нужен **sdk ≥ 1.9.0**.

**[Ф]** Версия ext — 1.4.2 (`ext/php_opentelemetry.h:26`). Пакеты contrib требуют `ext-opentelemetry: *`, а psr15 ещё и `open-telemetry/sem-conv ^1.38`. Bridge конфликтует только с `sem-conv <1.22`.

**[В]** Bridge **не** должен требовать ext-opentelemetry. Достаточно `suggest` и, если где-то поведение различается, проверки `extension_loaded('opentelemetry')` во время выполнения. Режим A работает без расширения.

### 3.7 CI

**[В]** Использовать `shivammathur/setup-php@v2` с `extensions: opentelemetry`, установка из PECL. Добавить отдельную задачу (или ветку матрицы) для PHP 8.2 и 8.5, плюс ветку `lowest`. Ставить `open-telemetry/opentelemetry-auto-psr15` (и psr18) в require-dev **только в этой задаче** через `composer require --dev` в workflow, потому что:
- хуки регистрируются из composer `files` при автозагрузке;
- снять их нельзя;
- `Globals` статичен.

Иначе все существующие тесты пойдут со спанами psr15. Альтернатива — держать пакеты установленными и по умолчанию выставлять `OTEL_PHP_DISABLED_INSTRUMENTATIONS=all`, включая хуки в отдельных процессах.

**[В]** Для приёмочных тестов переиспользовать OTLP-приёмник Trap (`tests/Acceptance/Support/Trap`) и вызывать `Http::handle()` с запросом, несущим `traceparent`. Проверять:
- один trace id;
- вложенность psr15 SERVER → Spiral SERVER или подавление;
- спан БД/HTTP-клиента из хука под активным спаном Spiral;
- что спаны из хуков экспортируются **тем же** flush, что и спаны Spiral, — это доказывает единый конвейер;
- отсутствие спанов самотрассировки экспортёра.

Поскольку `Globals` статичен на процесс, тесты режима `Globals` положить в `tests/Process` (один процесс PHP на сценарий, как `exit-without-finalize.php`).

**[В]** Использовать `OTEL_PHP_DEBUG_SCOPES_DISABLED=false` с `zend.assertions=1`, чтобы ловить утечки scope.

---

## 4. Решить сейчас (потом менять трудно)

1. **Реализация #16 должна проверять «активен ли локальный спан» через `Context::getCurrent()`, а не через внутренний стек.** Именно это заставляет учитывать спаны из хуков. **[В]** Предложенный фикс так и делает. Сохранить это и добавить Feature-тест, где спан, прикреплённый извне вместо спана из хука, побеждает входящие заголовки. В #16 этот тест уже запланирован.
2. **Стек из #17 должен быть свой у каждого Tracer и не должен заменять `Context` при выборе родителя.** **[В]** Он используется только в `getContext()`. Выбирать родителя по стеку нельзя: это сломает чередование со спанами из хуков. И в самом `getContext()` стек только выбирает, а для scoped-вершины спан берётся из `Context::getCurrent()` (§3.5), иначе логи и задачи не увидят спаны из хуков.
3. **Привязка пропагатора.** `initTextMapPropagator` жёстко задаёт TraceContext и лежит в `BINDINGS` (не синглтон). Переход на `PropagatorFactory` по переменным окружения меняет исходящие заголовки — добавляется `baggage`, — поэтому нужна запись в CHANGELOG. Сделать это до интеграции с ext или вместе с ней и сделать пропагатор синглтоном, потому что в `Globals` должен быть зарегистрирован тот же экземпляр.
4. **Ленивость провайдера.** Провайдер строится при первом использовании (#10). Режиму A нужно, чтобы провайдер был зарегистрирован в `Globals` **до первого срабатывания хука**, а это может случиться раньше любого `trace()` в Spiral. **[В]** Регистрировать при загрузке late-binding обёртку (`OpenTelemetry\API\Trace\LateBindingTracerProvider`, **[Ф]** есть с api 1.1.0), чтобы ленивость сохранилась. Иначе — строить провайдер сразу, если `extension_loaded('opentelemetry')`.
5. **Имя instrumentation scope.** `initTracer` использует `OTEL_SERVICE_NAME` как *имя instrumentation scope* (`OpenTelemetryBootloader.php:57-61`). У спанов из хуков имена scope вида `io.opentelemetry.contrib.php.psr15`, а имя сервиса относится к ресурсу. **[В]** Сменить на фиксированное, например `spiral/otel-bridge` или `io.spiral.telemetry`, с версией. Экспортируемые данные меняются, поэтому объединить с другими изменениями поведения.
6. **Флаг владения shutdown.** **[В]** Решить сейчас, может ли `TracerProviderFinalizer` делать `shutdown()` провайдеру, который он не строил. Ответ должен быть «нет», как в §3.4. Это определяет форму конструктора финализатора: нужен флаг «владею» или отдельный финализатор только со сбросом.

---

## 5. Открытые вопросы

- Есть ли у upstream (или планируется) штатный способ подавить инструментацию трафика экспортёра для хуков старого стиля? Поиск по issues upstream ничего не дал. Предложение: проверить трюк с «несемплированным родителем вокруг flush».
- Сбрасывать ли bridge в режиме B ещё и `Globals::meterProvider()`/`loggerProvider()` после каждого запроса? Это расширение рамок, но пользователи RR с авто-инструментированными метриками упираются в ту же проблему (ветка #1611).
- Отдавать ли на SERVER-спанах Spiral актуальные HTTP-атрибуты semconv, чтобы рекомендация «выключить psr15» ничего не теряла? Это атрибуты на стороне spiral/http, то есть правка в upstream.
- Воркер-режим FrankenPHP страдает от той же проблемы со сбросом (#1611). Покрывать ли его тестами вместе с RoadRunner?

## Источники

Пути указаны относительно `vendor/` этого репозитория или относительно upstream-репозиториев, перечисленных в начале.

- ext: `opentelemetry-php-instrumentation/README.md`; `ext/opentelemetry.stub.php`; `ext/otel_observer.c:83-140,963-993`; `ext/php_opentelemetry.h:26`; `ext/tests/{hooks_throw_exception,multiple_hooks_execution_order,hook_interface_method}.phpt`
- api: `vendor/open-telemetry/api/{Globals.php,Instrumentation/Configurator.php,Instrumentation/CachedInstrumentation.php,Trace/NoopSpanBuilder.php}`; `opentelemetry-php/src/API/Instrumentation/AutoInstrumentation/{HookManager,ExtensionHookManager}.php`; raw `github.com/opentelemetry-php/api/1.1.0/*`
- sdk: `vendor/open-telemetry/sdk/{_autoload.php,SdkAutoloader.php,Sdk.php,SdkBuilder.php,Common/Util/ShutdownHandler.php,Trace/{TracerProvider,TracerSharedState,SpanBuilder,AutoRootSpan}.php,Trace/SpanSuppression/*,Common/Export/Http/PsrTransport.php}`
- context: `vendor/open-telemetry/context/{Context,FiberBoundContextStorage,DebugScope,ZendObserverFiber}.php`, `fiber/initialize_fiber_handler.php`
- contrib: `src/Instrumentation/Psr15/{_register.php,src/Psr15Instrumentation.php,composer.json}`; `Psr18/src/Psr18Instrumentation.php`; `Laravel/src/Hooks/Illuminate/Contracts/Http/Kernel.php`; `Symfony/src/SymfonyInstrumentation.php`
- Бандл FoOT: `src/Instrumentation/Symfony/HttpKernel/{Traceable,Observable}HttpKernelEventSubscriber.php`
- Документация: opentelemetry.io `content/en/docs/zero-code/php/auto.md`, `languages/php/{sdk,instrumentation,context}.md`
- Spiral: `vendor/spiral/http/src/{Http,Pipeline,LazyPipeline}.php`; `vendor/spiral/telemetry/src/AbstractTracer.php`; `vendor/spiral/queue/src/Interceptor/{Consume/Handler,Push/Core}.php`; spiral/framework@3.17.2 `src/Router/src/{Router,RouterInterface,RouteInterface,CoreHandler}.php`; spiral/roadrunner-bridge master `src/Http/Dispatcher.php`
- Issues upstream: open-telemetry/opentelemetry-php #1611 (воркер-режим, flush), #1003/#1168 (segfault Octane+RR, исправлен), #1995 (ionCube+RR, открыт)
- Issues bridge: spiral/otel-bridge #16, #17, #18, #19
