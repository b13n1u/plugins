<?php
/**
 * i-MSCP SpamAssassin plugin
 * Copyright (C) 2013-2015 Sascha Bay <info@space2place.de>
 * Copyright (C) 2013-2015 Rene Schuster <mail@reneschuster.de>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

$database = quoteIdentifier(iMSCP_Registry::get('config')->DATABASE_NAME . '_spamassassin');
$table = quoteIdentifier('userpref');

return array(
	'up' => "
		ALTER TABLE $database.$table DROP INDEX `user_pref`;
		ALTER TABLE $database.$table ADD UNIQUE `user_pref` ( `username`, `preference`, `value`);
	"
);
