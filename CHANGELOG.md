# Changelog

## [1.3.0](https://github.com/spiral/otel-bridge/compare/1.2.3...1.3.0) (2026-10-16)


### Features

* require PHP 8.2 ([50b7660](https://github.com/spiral/otel-bridge/commit/50b7660aab8bafe62547bf1e517d7a75bd2d07af))


### Bug Fixes

* combine repeated tracestate header fields into one list ([#32](https://github.com/spiral/otel-bridge/issues/32)) ([77555ea](https://github.com/spiral/otel-bridge/commit/77555ea841b99f7b2fdc572886b025f83f6ad77f))
* conflict with open-telemetry/sem-conv below 1.22 ([6f86815](https://github.com/spiral/otel-bridge/commit/6f86815f6707bdd640641a4e3781b2c174b0c34c))
* encode structured attribute values as the OTel spec prescribes ([#31](https://github.com/spiral/otel-bridge/issues/31)) ([1831759](https://github.com/spiral/otel-bridge/commit/1831759d77d383712780e601db63b132c9b8156c))
* honour OTEL_SDK_DISABLED and OTEL_TRACES_SAMPLER ([#33](https://github.com/spiral/otel-bridge/issues/33)) ([38c9532](https://github.com/spiral/otel-bridge/commit/38c95323ac38c68dcc3d925847ae88530024246b))
* keep one tracer stack and flush it on finalize ([#10](https://github.com/spiral/otel-bridge/issues/10)) ([#36](https://github.com/spiral/otel-bridge/issues/36)) ([664d1db](https://github.com/spiral/otel-bridge/commit/664d1dbe44c4a2f6f8d62bd7d84579e2ac721255))
* make OTEL_TRACES_EXPORTER=none silent and keep exporter errors out of flush and shutdown ([#30](https://github.com/spiral/otel-bridge/issues/30)) ([a548ad5](https://github.com/spiral/otel-bridge/commit/a548ad5ed96293f2325ddc4987b0af5e4a6d3348))
* nest spans under the active span when a remote parent is present ([#16](https://github.com/spiral/otel-bridge/issues/16)) ([#40](https://github.com/spiral/otel-bridge/issues/40)) ([4e7f441](https://github.com/spiral/otel-bridge/commit/4e7f441c04dae857c6fe4b2f70f724a6c6721982))


### Documentation

* rewrite README with installation, configuration and badges ([a7ba0bc](https://github.com/spiral/otel-bridge/commit/a7ba0bceee172154137527e85840039673593b8d))


### Dependencies

* require open-telemetry/api ^1.1 and open-telemetry/sdk ^1.3 ([09cc1e6](https://github.com/spiral/otel-bridge/commit/09cc1e604572ed7eb1f9244f4b1689c78667aec1))


### Code Refactoring

* fix psalm issues and drop the baseline ([c67347b](https://github.com/spiral/otel-bridge/commit/c67347b9426039ae801f26993fe66b26c240bdd4))
