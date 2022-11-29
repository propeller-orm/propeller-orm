.PHONY: test test/reset_tests.sh

vendor: composer.json
	composer install

test/reset_tests.sh:
	./test/reset_tests.sh

test: vendor test/reset_tests.sh
	php vendor/bin/phpunit --default-time-limit=5 --enforce-time-limit

docker:
	docker build test/ --tag=ghcr.io/propeller-orm/propeller-orm/mysql-test-image:latest
	docker push ghcr.io/propeller-orm/propeller-orm/mysql-test-image:latest

