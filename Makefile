ARGS ?=
PROD_ENV ?= .env.prod.local
COMMANDS = init up down logs test e2e lint build migrate console shell \
	worker-logs worker-start worker-stop failed-show failed-retry \
	prod-build prod-up prod-down format
.PHONY: $(COMMANDS)
$(COMMANDS):
	PROD_ENV=$(PROD_ENV) ./dev $@ $(ARGS)
