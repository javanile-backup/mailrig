#!make

include .env
export $(shell sed 's/=.*//' .env)

build:
	chmod +x mailrig.php docker-entrypoint.sh
	docker-compose -f test/docker-compose.yml build mailrig

fixtures:
	for account in test/tasks/accounts/*.json.example; do \
        envsubst < $${account} > $${account%.example}; \
    done

tdd: build fixtures
	#docker-compose -f test/docker-compose.yml up --build --force-recreate
	#docker-compose -f test/docker-compose.yml up --build move2
	docker-compose -f test/docker-compose.yml run --rm -T mailrig task tasks/move.3.json

push:
	git add .
	git commit -am "push"
	git push
	docker build -t javanile/mailrig .
	docker push javanile/mailrig
