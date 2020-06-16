#!make

include .env
export $(shell sed 's/=.*//' .env)

build:
	chmod +x mailman.php
	docker-compose -f test/docker-compose.yml build mailman

fixtures:
	for account in test/tasks/accounts/*.json.example; do \
        envsubst < $${account} > $${account%.example}; \
    done

tdd: build fixtures
	docker-compose -f test/docker-compose.yml up --build move1
	#docker-compose -f test/docker-compose.yml up --build move2
