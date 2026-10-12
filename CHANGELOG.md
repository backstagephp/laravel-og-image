# Changelog

All notable changes to `laravel-og-image` will be documented in this file.

## v1.4.0 - 2026-10-12

### What's Changed

* Let Larastan boot the package, so PHPStan knows its views by @markvaneijk in https://github.com/backstagephp/laravel-og-image/pull/57
* Add og-image:warm to generate the images of all pages in advance by @markvaneijk in https://github.com/backstagephp/laravel-og-image/pull/56

**Full Changelog**: https://github.com/backstagephp/laravel-og-image/compare/v1.3.0...v1.4.0

## v1.3.0 - 2026-10-12

### What's Changed

* Take the screenshot in the format of the configured extension by @markvaneijk in https://github.com/backstagephp/laravel-og-image/pull/54
* Connect to a running Chrome instead of launching one for every image by @markvaneijk in https://github.com/backstagephp/laravel-og-image/pull/55

**Full Changelog**: https://github.com/backstagephp/laravel-og-image/compare/v1.2.1...v1.3.0

## v1.2.1 - 2026-10-11

### What's Changed

* Grant contents write permission to the changelog workflow by @markvaneijk in https://github.com/backstagephp/laravel-og-image/pull/47
* Allow manually dispatching the changelog workflow by @markvaneijk in https://github.com/backstagephp/laravel-og-image/pull/48
* Bump actions/checkout from 5 to 6 by @dependabot[bot] in https://github.com/backstagephp/laravel-og-image/pull/42
* Bump dependabot/fetch-metadata from 2.3.0 to 2.5.0 by @dependabot[bot] in https://github.com/backstagephp/laravel-og-image/pull/43
* Create the storage folders on the configured disk by @markvaneijk in https://github.com/backstagephp/laravel-og-image/pull/53

**Full Changelog**: https://github.com/backstagephp/laravel-og-image/compare/v1.2.0...v1.2.1

## v1.2.0 - 2026-07-06

### What's Changed

* Skip disk persistence entirely in debug mode by @markvaneijk in https://github.com/backstagephp/laravel-og-image/pull/46

**Full Changelog**: https://github.com/backstagephp/laravel-og-image/compare/v1.1.3...v1.2.0

## v0.3.0 - 2023-03-09

### What’s changed

- Laravel 10 support

**Full Changelog**: https://github.com/backstagephp/laravel-og-image/compare/v0.2.0...v0.3.0

## v0.2.1 - 2023-01-21

- Make sure parameters given as ComponentAttributeBag converts to an array

## v0.2.0 - 2023-01-21

- Added image file extension to the end of the Open Graph Image URL for compatibility with Twitter

## v0.1.1 - 2022-11-23

- Added `noSandbox()` to BrowserShot

## v0.1.0 - 2022-09-09

### What's Changed

- Develop by @Baspa in https://github.com/backstagephp/laravel-og-image/pull/1
- Develop by @Baspa in https://github.com/backstagephp/laravel-og-image/pull/3

### New Contributors

- @Baspa made their first contribution in https://github.com/backstagephp/laravel-og-image/pull/1

**Full Changelog**: https://github.com/backstagephp/laravel-og-image/commits/v0.1.0
