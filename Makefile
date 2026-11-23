HOST               = debbie
STAGING_LOGS       = /var/www/cantrip.staging/storage/logs
PROD_LOGS          = /var/www/cantrip.prod/storage/logs
LOCAL_LOGS_STAGING = storage/logs-s
LOCAL_LOGS_PROD    = storage/logs-p

.PHONY: logs-staging logs-prod

logs-staging:
	scp '$(HOST):$(STAGING_LOGS)/*' $(LOCAL_LOGS_STAGING)/

# Production's logs belong to the runtime user `cantrip` (storage/ is 2770), so
# they leave the server through sudo.
logs-prod:
	ssh $(HOST) 'sudo tar -C $(PROD_LOGS) --exclude=.gitignore -cf - .' | tar -C $(LOCAL_LOGS_PROD) -xf -
