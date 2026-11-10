PHP ?= php84

.PHONY: test test-php test-ui build lint check

test: test-php test-ui

test-php:
	$(PHP) vendor/bin/phpunit

test-ui:
	npm --prefix dashboard test

build:
	npm --prefix dashboard run build

lint:
	find app config database routes tests bin -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l
	$(PHP) -l app.php
	$(PHP) -l cua

check: lint test build
