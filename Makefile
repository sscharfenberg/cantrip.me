STAGING_HOST       = cantrip
STAGING_LOGS       = /var/www/mbos/storage/logs
PROD_HOST          = cantrip
PROD_LOGS          = /var/www/mbop/storage/logs
LOCAL_LOGS_STAGING = storage/logs-s
LOCAL_LOGS_PROD    = storage/logs-p

.PHONY: logs-staging logs-prod

logs-staging:
	scp '$(STAGING_HOST):$(STAGING_LOGS)/*' $(LOCAL_LOGS_STAGING)/

logs-prod:
	scp '$(PROD_HOST):$(PROD_LOGS)/*' $(LOCAL_LOGS_PROD)/
