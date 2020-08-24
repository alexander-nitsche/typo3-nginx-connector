SHELL := /bin/bash

VERSION ?= $$(git describe --tags $$(git rev-list --tags --max-count=1))

build:
	@echo "Pack extension nginx_connector of version $(VERSION)"
	@mkdir -p ./Build
	@rm -f ./Build/nginx_connector_$(VERSION).zip
	@git archive -o ./Build/nginx_connector_$(VERSION).zip $(VERSION)
