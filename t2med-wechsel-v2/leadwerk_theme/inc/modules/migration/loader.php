<?php
/**
 * Copyright (C) 2014-2025 ServMask Inc.
 * Modifications Copyright (C) 2026 Leadwerk.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Upstream attribution: This file derives from the All-in-One WP Migration plugin, developed by
 *
 * ███████╗███████╗██████╗ ██╗   ██╗███╗   ███╗ █████╗ ███████╗██╗  ██╗
 * ██╔════╝██╔════╝██╔══██╗██║   ██║████╗ ████║██╔══██╗██╔════╝██║ ██╔╝
 * ███████╗█████╗  ██████╔╝██║   ██║██╔████╔██║███████║███████╗█████╔╝
 * ╚════██║██╔══╝  ██╔══██╗╚██╗ ██╔╝██║╚██╔╝██║██╔══██║╚════██║██╔═██╗
 * ███████║███████╗██║  ██║ ╚████╔╝ ██║ ╚═╝ ██║██║  ██║███████║██║  ██╗
 * ╚══════╝╚══════╝╚═╝  ╚═╝  ╚═══╝  ╚═╝     ╚═╝╚═╝  ╚═╝╚══════╝╚═╝  ╚═╝
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}

// Include all the files that you want to load in here
if ( defined( 'WP_CLI' ) ) {
	require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/command/class-leadwerk_migration-wp-cli-command.php';
}

require_once LEADWERK_MIGRATION_VENDOR_PATH . '/bandar/bandar/lib/Bandar.php';
require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/cron/class-leadwerk_migration-cron.php';

require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/filesystem/class-leadwerk_migration-directory.php';
require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/filesystem/class-leadwerk_migration-file-htaccess.php';
require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/filesystem/class-leadwerk_migration-file-index.php';
require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/filesystem/class-leadwerk_migration-file-robots.php';
require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/filesystem/class-leadwerk_migration-file-webconfig.php';
require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/filesystem/class-leadwerk_migration-file.php';

require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/filter/class-leadwerk_migration-recursive-exclude-filter.php';
require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/filter/class-leadwerk_migration-recursive-extension-filter.php';

require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/iterator/class-leadwerk_migration-recursive-directory-iterator.php';
require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/iterator/class-leadwerk_migration-recursive-iterator-iterator.php';

require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/archiver/class-leadwerk_migration-archiver.php';
require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/archiver/class-leadwerk_migration-compressor.php';
require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/archiver/class-leadwerk_migration-extractor.php';

require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/checksum/class-leadwerk_migration-crc.php';

require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/database/class-leadwerk_migration-database.php';
require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/database/class-leadwerk_migration-database-mysql.php';
require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/database/class-leadwerk_migration-database-mysqli.php';
require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/database/class-leadwerk_migration-database-sqlite.php';
require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/database/class-leadwerk_migration-database-mariadb.php';
require_once LEADWERK_MIGRATION_VENDOR_PATH . '/servmask/database/class-leadwerk_migration-database-utility.php';

require_once LEADWERK_MIGRATION_CONTROLLER_PATH . '/class-leadwerk_migration-backups-controller.php';
require_once LEADWERK_MIGRATION_CONTROLLER_PATH . '/class-leadwerk_migration-dashboard-controller.php';
require_once LEADWERK_MIGRATION_CONTROLLER_PATH . '/class-leadwerk_migration-export-controller.php';
require_once LEADWERK_MIGRATION_CONTROLLER_PATH . '/class-leadwerk_migration-import-controller.php';
require_once LEADWERK_MIGRATION_CONTROLLER_PATH . '/class-leadwerk_migration-main-controller.php';
require_once LEADWERK_MIGRATION_CONTROLLER_PATH . '/class-leadwerk_migration-rest-controller.php';
require_once LEADWERK_MIGRATION_CONTROLLER_PATH . '/class-leadwerk_migration-sync-controller.php';
require_once LEADWERK_MIGRATION_CONTROLLER_PATH . '/class-leadwerk_migration-reset-controller.php';
require_once LEADWERK_MIGRATION_CONTROLLER_PATH . '/class-leadwerk_migration-schedules-controller.php';
require_once LEADWERK_MIGRATION_CONTROLLER_PATH . '/class-leadwerk_migration-status-controller.php';

require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-archive.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-archive-crc.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-clean.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-compatibility.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-config-file.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-config.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-content.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-database-file.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-database.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-download.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-enumerate-content.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-enumerate-media.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-enumerate-plugins.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-enumerate-tables.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-enumerate-themes.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-init.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-media.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-plugins.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/export/class-leadwerk_migration-export-themes.php';

require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-blogs.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-check-compression.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-check-encryption.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-clean.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-compatibility.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-confirm.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-content.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-database-file.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-database.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-done.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-enumerate.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-mu-plugins.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-options.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-permalinks.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-upload.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-users.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-validate.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-validate-crc.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/import/class-leadwerk_migration-import-wordpress-version.php';

require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-backups.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-compatibility.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-deprecated.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-extensions.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-handler.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-log.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-message.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-notification.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-status.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-template.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-security.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-health.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-integrity.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-mail-smtp.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-scheduler.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-site-identity.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-release.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-sync-hub.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-runtime-reconcile.php';
require_once LEADWERK_MIGRATION_MODEL_PATH . '/class-leadwerk_migration-sync.php';
