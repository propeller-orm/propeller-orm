.PHONY: test test/reset_tests.sh

vendor: composer.json
	composer install

test/reset_tests.sh:
	./test/reset_tests.sh

test: vendor test/reset_tests.sh
	phpunit

