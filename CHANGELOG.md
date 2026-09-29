# Changelog

## [2.0.0](https://github.com/schaefersoft/laravel-seq/compare/v1.0.0...v2.0.0) (2026-09-29)


### ⚠ BREAKING CHANGES

* ship nothing without a configured seq url

### Features

* pause shipping with a circuit breaker while seq is unreachable ([779b099](https://github.com/schaefersoft/laravel-seq/commit/779b09928c1781dcaef5f1c254a9c44fdbd1c084))
* ship nothing without a configured seq url ([6f9e1ec](https://github.com/schaefersoft/laravel-seq/commit/6f9e1ece701b45ca3d4b1912147bc1f93ddb4567))

## 1.0.0 (2026-09-28)


### Features

* add clef formatter with seq level mapping ([9304ad9](https://github.com/schaefersoft/laravel-seq/commit/9304ad989b5983ce3fccb5bfcac88926e7aebf67))
* add seq handler that buffers events and ships them in batches ([3d9f8ce](https://github.com/schaefersoft/laravel-seq/commit/3d9f8ceaaf563b51a5421bc43bf6e2ecb4632e2e))
* add Seq logging integration ([39a65d3](https://github.com/schaefersoft/laravel-seq/commit/39a65d3449e134d7925132caa290589f12f17a3f))
* add Seq::fake() to assert shipped events in tests ([593b632](https://github.com/schaefersoft/laravel-seq/commit/593b63297c4ca5c3e9da6bd4b0a8de3f2550b2be))
* add seq:test command and about section ([f338aea](https://github.com/schaefersoft/laravel-seq/commit/f338aead0407898f65ccc2ccf94bd6fbf4cf5600))
* flush buffered events after the response and between queue jobs ([af1cc74](https://github.com/schaefersoft/laravel-seq/commit/af1cc74bbf917943055902149b87c74bc203ad13))
* register seq log driver with config and auto discovery ([b0af26f](https://github.com/schaefersoft/laravel-seq/commit/b0af26f4af2e3c10560ce3af9ad909c6123ea070))
