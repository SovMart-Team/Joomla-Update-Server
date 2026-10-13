.PHONY: package clean check

PACKAGE_NAME := plg_system_sovmartupdateserver
SOURCE_DIR := plugin
BUILD_DIR := build

package: check
	mkdir -p $(BUILD_DIR)
	rm -f $(BUILD_DIR)/$(PACKAGE_NAME).zip
	cd $(SOURCE_DIR) && zip -qr ../$(BUILD_DIR)/$(PACKAGE_NAME).zip . -x '*.DS_Store'

check:
	find $(SOURCE_DIR) -name '*.php' -print0 | xargs -0 -n1 php -l
	php tests/validate-manifest.php
	php tests/update-server-manager.php

clean:
	rm -f $(BUILD_DIR)/$(PACKAGE_NAME).zip
