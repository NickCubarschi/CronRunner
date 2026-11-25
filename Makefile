COMPOSER_IMAGE := composer:2.11.2
IMAGE := cron-runner
ADAPTER ?= laravel
PHPCS_VERSION := 3.13.6
PHPSTAN_VERSION := 2.1.17
PHPSTAN_LEVEL := 8

.PHONY: lock build run test format analyse

build:
	docker build --target app -t $(IMAGE) .

run:
	docker run --rm --init -e CRON_RUNNER_ADAPTER=$(ADAPTER) $(IMAGE)

# Build the test image and run the whole test suite in it
test:
	docker build --target test -t $(IMAGE)-test .
	docker run --rm $(IMAGE)-test

lock:
	docker run --rm \
		--user $$(id -u):$$(id -g) \
		-v "$(CURDIR)":/app \
		-w /app \
		$(COMPOSER_IMAGE) \
		composer update --no-install --no-interaction --no-scripts

format:
	docker run --rm \
		--user $$(id -u):$$(id -g) \
		-e COMPOSER_HOME=/tmp/composer \
		-v "$(CURDIR)":/app \
		-w /app \
		$(COMPOSER_IMAGE) \
		sh -c 'composer global require --quiet squizlabs/php_codesniffer:$(PHPCS_VERSION) \
			&& /tmp/composer/vendor/bin/phpcbf --standard=PSR12 index.php src tests || [ $$? -eq 1 ]'

analyse:
	docker build --target test -t $(IMAGE)-test .
	docker run --rm $(IMAGE)-test \
		sh -c 'php -r "copy(\"https://github.com/phpstan/phpstan/releases/download/$(PHPSTAN_VERSION)/phpstan.phar\", \"/tmp/phpstan.phar\");" \
			&& php /tmp/phpstan.phar analyse --no-progress --memory-limit=1G --level=$(PHPSTAN_LEVEL) index.php src tests'
