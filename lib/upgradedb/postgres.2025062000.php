<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2025 LMS Developers
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307,
 *  USA.
 *
 */

$this->BeginTrans();

if (!$this->ResourceExists('templateattachments', LMSDB::RESOURCE_TYPE_TABLE)) {
    $this->Execute("
        CREATE SEQUENCE templateattachments_id_seq;
        CREATE TABLE templateattachments (
            id integer DEFAULT nextval('templateattachments_id_seq'::text) NOT NULL,
            templateid integer NOT NULL
                CONSTRAINT templateattachments_templateid_fkey REFERENCES templates (id) ON DELETE CASCADE ON UPDATE CASCADE,
            filename varchar(255) 	DEFAULT '' NOT NULL,
            contenttype varchar(255) DEFAULT '' NOT NULL,
            PRIMARY KEY (id)
        )
    ");
}

$this->Execute("UPDATE dbinfo SET keyvalue = ? WHERE keytype = ?", array('2025062000', 'dbversion'));

$this->CommitTrans();
