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

class Leadwerk_Migration_Archive_Exception extends Exception {}
class Leadwerk_Migration_Backups_Exception extends Exception {}
class Leadwerk_Migration_Export_Exception extends Exception {}
class Leadwerk_Migration_Http_Exception extends Exception {}
class Leadwerk_Migration_Import_Exception extends Exception {}
class Leadwerk_Migration_CRC_Exception extends Exception {}
class Leadwerk_Migration_Upload_Exception extends Exception {}
class Leadwerk_Migration_Not_Accessible_Exception extends Exception {}
class Leadwerk_Migration_Not_Seekable_Exception extends Exception {}
class Leadwerk_Migration_Not_Tellable_Exception extends Exception {}
class Leadwerk_Migration_Not_Readable_Exception extends Exception {}
class Leadwerk_Migration_Not_Writable_Exception extends Exception {}
class Leadwerk_Migration_Not_Truncatable_Exception extends Exception {}
class Leadwerk_Migration_Not_Closable_Exception extends Exception {}
class Leadwerk_Migration_Not_Found_Exception extends Exception {}
class Leadwerk_Migration_Not_Directory_Exception extends Exception {}
class Leadwerk_Migration_Not_Valid_Secret_Key_Exception extends Exception {}
class Leadwerk_Migration_Quota_Exceeded_Exception extends Exception {}
class Leadwerk_Migration_Storage_Exception extends Exception {}
class Leadwerk_Migration_Compatibility_Exception extends Exception {}
class Leadwerk_Migration_Feedback_Exception extends Exception {}
class Leadwerk_Migration_Database_Exception extends Exception {}
class Leadwerk_Migration_Not_Encryptable_Exception extends Exception {}
class Leadwerk_Migration_Not_Decryptable_Exception extends Exception {}
class Leadwerk_Migration_Import_Halted_Exception extends Exception {}
