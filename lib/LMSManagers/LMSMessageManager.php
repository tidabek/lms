<?php

/*
 *  LMS version 1.11-git
 *
 *  Copyright (C) 2001-2020 LMS Developers
 *
 *  Please, see the doc/AUTHORS for more information about authors!
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
 *  $Id$
 */

/**
 * LMSMessageManager
 *
 */
class LMSMessageManager extends LMSManager implements LMSMessageManagerInterface
{

    public function GetMessages($customerid, $limit = null)
    {
        $userid = Auth::GetCurrentUser();

        $result = $this->db->GetAll(
            'SELECT
                i.messageid AS id,
                i.status,
                i.error,
                i.destination,
                i.lastdate,
                i.lastreaddate,
                m.subject,
                m.type,
                m.cdate,
                m.startdate,
                u.name AS username,
                u.id AS userid,
                fc.id AS filecontainerid
            FROM messageitems i'
            . (empty($userid)
                ? ''
                : ' LEFT JOIN customers c ON c.id = i.customerid
                LEFT JOIN userdivisions ud ON ud.divisionid = c.divisionid AND ud.userid = ' . $userid
            )
            . ' JOIN messages m ON m.id = i.messageid
            LEFT JOIN filecontainers fc ON fc.messageid = m.id
            LEFT JOIN vusers u ON u.id = m.userid
            WHERE i.customerid = ?'
            . (empty($userid) ? '' : ' AND (c.id IS NULL OR ud.userid IS NOT NULL)')
            . ' ORDER BY m.cdate DESC'
            . ($limit ? ' LIMIT ' . $limit : ''),
            array($customerid)
        );

        if (!empty($result)) {
            foreach ($result as &$message) {
                if (!empty($message['filecontainerid'])) {
                    if (!isset($file_manager)) {
                        $file_manager = new LMSFileManager($this->db, $this->auth, $this->cache, $this->syslog);
                    }
                    $file_containers = $file_manager->GetFileContainers('messageid', $message['id']);
                    $message['files'] = $file_containers[0]['files'];
                }
            }
            unset($message);
        }

        return $result;
    }

    public function MessageTemplateExists($type, $name)
    {
        return $this->db->GetOne(
            'SELECT id FROM templates WHERE type = ? AND name = ?',
            array($type, $name)
        );
    }

    private function AddMessageTemplateAttachments($templateid, $attachments)
    {
        if (empty($attachments)) {
            return true;
        } else {
            $dir = STORAGE_DIR . DIRECTORY_SEPARATOR . 'messagetemplates' . DIRECTORY_SEPARATOR . $templateid;

            $storage_dir_permission = intval(ConfigHelper::getConfig('storage.dir_permission', '0700'), 8);
            $storage_dir_owneruid = ConfigHelper::getConfig('storage.dir_owneruid', 'root');
            $storage_dir_ownergid = ConfigHelper::getConfig('storage.dir_ownergid', 'root');

            @umask(0007);

            @mkdir($dir, $storage_dir_permission);
            @chown($dir, $storage_dir_owneruid);
            @chgrp($dir, $storage_dir_ownergid);

            foreach ($attachments as $attachment) {
                $dstfile = $dir . DIRECTORY_SEPARATOR . $attachment['name'];
                if (!@rename($attachment['tmpname'], $dstfile)) {
                    return 'Cannot move temporary file \'' . $attachment['tmpname'] . '\' to target file \'' . $dstfile . '\'!';
                }

                @chown($dstfile, $storage_dir_owneruid);
                @chgrp($dstfile, $storage_dir_ownergid);

                $this->db->Execute(
                    'INSERT INTO templateattachments
                        (templateid, filename, contenttype)
                        VALUES (?, ?, ?)',
                    array(
                        $templateid,
                        $attachment['name'],
                        $attachment['type'],
                    )
                );
            }

            return true;
        }
    }

    private function DeleteMessageTemplateAttachments($templateid, $attachments)
    {
        if (empty($attachments)) {
            return true;
        } else {
            $dir = STORAGE_DIR . DIRECTORY_SEPARATOR . 'messagetemplates' . DIRECTORY_SEPARATOR . $templateid;

            $attachments = $this->db->GetAll(
                'SELECT ta.*
                FROM templateattachments ta
                WHERE ta.templateid = ?
                    AND ta.id IN ?',
                array(
                    $templateid,
                    $attachments,
                )
            );

            if (!empty($attachments)) {
                foreach ($attachments as $attachment) {
                    $dstfile = $dir . DIRECTORY_SEPARATOR . $attachment['filename'];
                    if (!@unlink($dstfile)) {
                        return 'Cannot delete target file \'' . $dstfile . '\'!';
                    }
                }

                $this->db->Execute(
                    'DELETE FROM templateattachments
                    WHERE id IN ?',
                    array(
                        Utils::array_column($attachments, 'id'),
                    )
                );
            }

            return true;
        }
    }

    public function AddMessageTemplate($type, $name, $subject, $helpdesk_queues, $helpdesk_message_types, $message, $contenttype = 'text', array $attachments = array())
    {
        $this->db->BeginTrans();

        $args = array(
            'type' => $type,
            'name' => $name,
            'subject' => $subject,
            'message' => $message,
            'contenttype' => stripos($contenttype, 'html') === false ? 'text/plain' : 'text/html',
        );
        if ($this->db->Execute(
            'INSERT INTO templates (type, name, subject, message, contenttype)
            VALUES (?, ?, ?, ?, ?)',
            array_values($args)
        )) {
            $id = $this->db->GetLastInsertID('templates');

            if ($this->syslog) {
                $args[SYSLOG::RES_TMPL] = $id;
                $this->syslog->AddMessage(SYSLOG::RES_TMPL, SYSLOG::OPER_ADD, $args);
            }

            if ($type == TMPL_HELPDESK) {
                if (!empty($helpdesk_queues)) {
                    foreach ($helpdesk_queues as $queueid) {
                        $this->db->Execute(
                            'INSERT INTO rttemplatequeues
                            (templateid, queueid)
                            VALUES (?, ?)',
                            array($id, $queueid)
                        );
                    }
                }
                if (!empty($helpdesk_message_types)) {
                    foreach ($helpdesk_message_types as $message_type) {
                        $this->db->Execute(
                            'INSERT INTO rttemplatetypes
                            (templateid, messagetype)
                            VALUES (?, ?)',
                            array($id, $message_type)
                        );
                    }
                }
            }

            $result = $this->AddMessageTemplateAttachments($id, $attachments);
            if (is_string($result)) {
                $this->db->RollbackTrans();
                die($result);
            }

            $this->db->CommitTrans();

            return $id;
        }

        $this->db->RollbackTrans();

        return false;
    }

    public function UpdateMessageTemplate($id, $type, $name, $subject, $helpdesk_queues, $helpdesk_message_types, $message, $contenttype = 'text', array $attachments = array(), array $attachments_to_delete = array())
    {
        $this->db->BeginTrans();

        $args = array(
            'type' => $type,
            'name' => $name,
            'subject' => $subject,
            'message' => $message,
            'contenttype' => $contenttype == 'html' ? 'text/html' : 'text/plain',
            SYSLOG::RES_TMPL => intval($id),
        );
        if (empty($name)) {
            unset($args['name']);
            $res = $this->db->Execute(
                'UPDATE templates
                SET type = ?, subject = ?, message = ?, contenttype = ?
                WHERE id = ?',
                array_values($args)
            );
        } else {
            $res = $this->db->Execute(
                'UPDATE templates
                SET type = ?, name = ?, subject = ?, message = ?, contenttype = ?
                WHERE id = ?',
                array_values($args)
            );
        }
        if ($res && $this->syslog) {
            $args[SYSLOG::RES_TMPL] = $id;
            $this->syslog->AddMessage(SYSLOG::RES_TMPL, SYSLOG::OPER_UPDATE, $args);
        }

        $helpdesk_manager = new LMSHelpdeskManager($this->db, $this->auth, $this->cache, $this->syslog);
        $queues = $helpdesk_manager->GetMyQueues();
        if (!empty($queues)) {
            $this->db->Execute(
                'DELETE FROM rttemplatequeues WHERE templateid = ? AND queueid IN ?',
                array($id, $queues)
            );
        }

        $this->db->Execute('DELETE FROM rttemplatetypes WHERE templateid = ?', array($id));

        if ($type == TMPL_HELPDESK) {
            if (!empty($helpdesk_queues)) {
                foreach ($helpdesk_queues as $queueid) {
                    $this->db->Execute(
                        'INSERT INTO rttemplatequeues
                        (templateid, queueid)
                        VALUES (?, ?)',
                        array($id, $queueid)
                    );
                }
            }
            if (!empty($helpdesk_message_types)) {
                foreach ($helpdesk_message_types as $message_type) {
                    $this->db->Execute(
                        'INSERT INTO rttemplatetypes
                        (templateid, messagetype)
                        VALUES (?, ?)',
                        array($id, $message_type)
                    );
                }
            }
        }

        $result = $this->AddMessageTemplateAttachments($id, $attachments);
        if (is_string($result)) {
            $this->db->RollbackTrans();
            die($result);
        }

        $result = $this->DeleteMessageTemplateAttachments($id, $attachments_to_delete);
        if (is_string($result)) {
            $this->db->RollbackTrans();
            die($result);
        }

        $this->db->CommitTrans();

        return $res;
    }

    public function DeleteMessageTemplates(array $ids)
    {
        $dir = STORAGE_DIR . DIRECTORY_SEPARATOR . 'messagetemplates';

        foreach ($ids as $id) {
            rrmdir($dir . DIRECTORY_SEPARATOR . $id);
        }

        return $this->db->Execute(
            'DELETE FROM templates WHERE id IN ?',
            array($ids)
        );
    }

    public function GetMessageTemplateAttachments($templateid)
    {
        $attachments = $this->db->GetAllByKey(
            'SELECT
                ta.*
            FROM templateattachments ta
            JOIN templates t ON t.id = ta.templateid
            WHERE ta.templateid = ?',
            'id',
            array($templateid)
        );
        if (empty($attachments)) {
            $attachments = array();
        } else {
            $dir = STORAGE_DIR . DIRECTORY_SEPARATOR . 'messagetemplates' . DIRECTORY_SEPARATOR . $templateid;

            foreach ($attachments as &$attachment) {
                $attachment['filepath'] = $dir . DIRECTORY_SEPARATOR . $attachment['filename'];
            }
            unset($attachment);
        }

        return $attachments;
    }

    public function GetMessageTemplates($type = 0)
    {
        $helpdesk_manager = new LMSHelpdeskManager($this->db, $this->auth, $this->cache, $this->syslog);
        $queues = $helpdesk_manager->GetMyQueues();

        $templates = $this->db->GetAllByKey(
            'SELECT
                t.id,
                t.type,
                t.name,
                t.subject,
                t.message,
                t.contenttype,
                tt.messagetypes,
                tq.queues,
                tq.queuenames
            FROM templates t
            LEFT JOIN (
                SELECT templateid, ' . $this->db->GroupConcat('messagetype') . ' AS messagetypes
                FROM rttemplatetypes
                GROUP BY templateid
            ) tt ON tt.templateid = t.id
            LEFT JOIN (
                SELECT templateid, ' . $this->db->GroupConcat('queueid') . ' AS queues,
                    ' . $this->db->GroupConcat('q.name') . ' AS queuenames
                FROM rttemplatequeues
                JOIN rtqueues q ON q.id = queueid
                WHERE ' . (empty($queues) ? '1=0' : 'queueid IN (' . implode(',', $queues) . ')') . '
                GROUP BY templateid
            ) tq ON tq.templateid = t.id
            WHERE 1 = 1'
            . (empty($type) ? '' : ' AND t.type = ' . intval($type))
            . ' ORDER BY t.name',
            'id'
        );

        $attachments = $this->db->GetAll(
            'SELECT
                ta.*
            FROM templateattachments ta
            JOIN templates t ON t.id = ta.templateid
            WHERE 1 = 1'
            . (empty($type) ? '' : ' AND t.type = ' . intval($type))
        );
        if (!empty($attachments)) {
            $dir = STORAGE_DIR . DIRECTORY_SEPARATOR . 'messagetemplates';

            foreach ($attachments as $attachment) {
                $templateid = $attachment['templateid'];
                if (empty($templates[$templateid]['attachments'])) {
                    $templates[$templateid]['attachments'] = array();
                }
                $attachment['size'] = filesize($dir . DIRECTORY_SEPARATOR . $templateid . DIRECTORY_SEPARATOR . $attachment['filename']);
                $templates[$templateid]['attachments'][$attachment['id']] = $attachment;
            }
        }

        return $templates;
    }

    public function GetMessageTemplatesByQueueAndType($queueid, $type)
    {
        return $this->db->GetAll(
            'SELECT DISTINCT t.id, t.name, t.subject, t.message
			FROM templates t
			LEFT JOIN rttemplatequeues tq ON tq.templateid = t.id AND tq.queueid ' . (is_array($queueid) ? 'IN' : '=') . ' ?
			LEFT JOIN rttemplatetypes tt ON tt.templateid = t.id AND tt.messagetype = ?
			LEFT JOIN (
				SELECT t2.id AS templateid
				FROM templates t2
				LEFT JOIN rttemplatequeues tq2 ON tq2.templateid = t2.id
				GROUP BY t2.id
				HAVING COUNT(tq2.templateid) = 0
			) t3 ON t3.templateid = t.id
			LEFT JOIN (
				SELECT t4.id AS templateid
				FROM templates t4
				LEFT JOIN rttemplatequeues tt2 ON tt2.templateid = t4.id
				GROUP BY t4.id
				HAVING COUNT(tt2.templateid) = 0
			) t5 ON t5.templateid = t.id
			WHERE t.type = ? AND (tq.templateid IS NOT NULL OR t.id = t3.templateid)
				AND (tt.templateid IS NOT NULL OR t.id = t5.templateid)  
			GROUP BY t.id, t.name, t.subject, t.message',
            array(is_array($queueid) ? $queueid : intval($queueid), $type, TMPL_HELPDESK)
        );
    }

    public function GetMessageList(array $params)
    {
        extract($params);
        foreach (array('search', 'cat', 'status') as $var) {
            if (!isset(${$var})) {
                ${$var} = null;
            }
        }
        if (!isset($order)) {
            $order = 'cdate,desc';
        }

        if (isset($datefrom)) {
            $datefrom = intval($datefrom);
        } else {
            $datefrom = 0;
        }

        if (isset($dateto)) {
            $dateto = intval($dateto);
        } else {
            $dateto = 0;
        }

        if (!isset($type)) {
            $type = '';
        }

        if (!isset($count)) {
            $count = false;
        }

        if ($order=='') {
            $order='cdate,desc';
        }


        [$order, $direction] = sscanf($order, '%[^,],%s');
        ($direction=='desc') ? $direction = 'desc' : $direction = 'asc';

        switch ($order) {
            case 'subject':
                $sqlord = ' ORDER BY m.subject';
                break;
            case 'type':
                $sqlord = ' ORDER BY m.type';
                break;
            case 'cnt':
                $sqlord = ' ORDER BY cnt';
                break;
            default:
                $sqlord = ' ORDER BY m.cdate';
                break;
        }

        if ($search!='' && $cat) {
            switch ($cat) {
                case 'userid':
                    $where[] = 'm.userid = '.intval($search);
                    break;
                case 'username':
                    $where[] = 'UPPER(u.name) ?LIKE? UPPER(' . $this->db->Escape('%' . $search . '%') . ')';
                    $userjoin = true;
                    break;
                case 'subject':
                    $where[] = 'UPPER(m.subject) ?LIKE? UPPER(' . $this->db->Escape('%' . $search . '%') . ')';
                    break;
                case 'destination':
                    $where[] = 'EXISTS (SELECT 1 FROM messageitems i
					WHERE i.messageid = m.id AND UPPER(i.destination) ?LIKE? UPPER(' . $this->db->Escape('%' . $search . '%') . '))';
                    break;
                case 'customerid':
                    $where[] = 'EXISTS (SELECT 1 FROM messageitems i
					WHERE i.customerid = '.intval($search).' AND i.messageid = m.id)';
                    break;
                case 'name':
                    $where[] = 'EXISTS (SELECT 1 FROM messageitems i
					JOIN customers c ON (c.id = i.customerid)
					WHERE i.messageid = m.id AND UPPER(c.lastname) ?LIKE? UPPER(' . $this->db->Escape('%' . $search . '%') . '))';
                    break;
            }
        }

        if ($type) {
            $type = intval($type);
            $where[] = 'm.type = '.$type;
        }

        if ($datefrom) {
            $where[] = 'm.cdate >= ' . $datefrom;
        }

        if ($dateto) {
            $where[] = 'm.cdate <= ' . $dateto;
        }

        if ($status) {
            switch ($status) {
                case MSG_NEW:
                    $where[] = 'x.sent + x.delivered + x.error = 0';
                    break;
                case MSG_ERROR:
                    $where[] = 'x.error > 0';
                    break;
                case MSG_SENT:
                    $where[] = 'x.sent = x.cnt';
                    break;
                case MSG_DELIVERED:
                    $where[] = 'x.delivered = x.cnt';
                    break;
                case MSG_CANCELLED:
                    $where[] = 'x.cancelled = x.cnt';
                    break;
                case MSG_BOUNCED:
                    $where[] = 'x.bounced = x.cnt';
                    break;
            }
        }

        if (!empty($where)) {
            $where = 'WHERE '.implode(' AND ', $where);
        }

        $userid = Auth::GetCurrentUser();

        if ($count) {
            return $this->db->GetOne('SELECT COUNT(m.id)
				FROM messages m
				JOIN (
					SELECT i.messageid,
						COUNT(*) AS cnt,
						COUNT(CASE WHEN i.status = ' . MSG_SENT . ' THEN 1 ELSE NULL END) AS sent,
						COUNT(CASE WHEN i.status = ' . MSG_DELIVERED . ' THEN 1 ELSE NULL END) AS delivered,
						COUNT(CASE WHEN i.status = ' . MSG_ERROR . ' THEN 1 ELSE NULL END) AS error,
						COUNT(CASE WHEN i.status = ' . MSG_CANCELLED . ' THEN 1 ELSE NULL END) AS cancelled,
						COUNT(CASE WHEN i.status = ' . MSG_BOUNCED . ' THEN 1 ELSE NULL END) AS bounced
					FROM messageitems i'
                    . (empty($userid) ? '' : ' LEFT JOIN customers c ON c.id = i.customerid LEFT JOIN userdivisions ud ON ud.divisionid = c.divisionid AND ud.userid = ' . $userid)
                    . ' LEFT JOIN (
						SELECT DISTINCT a.customerid FROM vcustomerassignments a
							JOIN excludedgroups e ON (a.customergroupid = e.customergroupid)
						WHERE e.userid = lms_current_user()
					) e ON (e.customerid = i.customerid)
					WHERE e.customerid IS NULL'
                    . (empty($userid) ? '' : ' AND (c.id IS NULL OR ud.userid IS NOT NULL)')
                    . ' GROUP BY i.messageid
				) x ON (x.messageid = m.id) '
                .(!empty($userjoin) ? 'JOIN vusers u ON (u.id = m.userid) ' : '')
                .(!empty($where) ? $where : ''));
        }

        $result = $this->db->GetAll(
            'SELECT
                m.id,
                m.cdate,
                m.startdate,
                m.type,
                m.subject,
                x.cnt,
                x.sent,
                x.delivered,
                x.error,
                x.cancelled,
                x.bounced,
                fc.id AS filecontainerid
            FROM messages m
            JOIN (
                SELECT i.messageid,
                    COUNT(*) AS cnt,
                    COUNT(CASE WHEN i.status = ' . MSG_SENT . ' THEN 1 ELSE NULL END) AS sent,
                    COUNT(CASE WHEN i.status = ' . MSG_DELIVERED . ' THEN 1 ELSE NULL END) AS delivered,
                    COUNT(CASE WHEN i.status = ' . MSG_ERROR . ' THEN 1 ELSE NULL END) AS error,
                    COUNT(CASE WHEN i.status = ' . MSG_CANCELLED . ' THEN 1 ELSE NULL END) AS cancelled,
                    COUNT(CASE WHEN i.status = ' . MSG_BOUNCED . ' THEN 1 ELSE NULL END) AS bounced
                FROM messageitems i'
                . (empty($userid) ? '' : ' LEFT JOIN customers c ON c.id = i.customerid LEFT JOIN userdivisions ud ON ud.divisionid = c.divisionid AND ud.userid = ' . $userid)
                . ' LEFT JOIN (
                    SELECT DISTINCT a.customerid
                    FROM vcustomerassignments a
                    JOIN excludedgroups e ON a.customergroupid = e.customergroupid
                    WHERE e.userid = lms_current_user()
                ) e ON e.customerid = i.customerid
                WHERE e.customerid IS NULL'
                . (empty($userid) ? '' : ' AND (c.id IS NULL OR ud.userid IS NOT NULL)')
                . ' GROUP BY i.messageid
            ) x ON x.messageid = m.id
            LEFT JOIN filecontainers fc ON fc.messageid = m.id '
            .(!empty($userjoin) ? 'JOIN vusers u ON u.id = m.userid ' : '')
            .(!empty($where) ? $where : '')
            .$sqlord . ' ' . $direction
            . (isset($limit) ? ' LIMIT ' . $limit : '')
            . (isset($offset) ? ' OFFSET ' . $offset : '')
        );

        if (!empty($result)) {
            foreach ($result as &$message) {
                if (!empty($message['filecontainerid'])) {
                    if (!isset($file_manager)) {
                        $file_manager = new LMSFileManager($this->db, $this->auth, $this->cache, $this->syslog);
                    }
                    $file_containers = $file_manager->GetFileContainers('messageid', $message['id']);
                    $message['files'] = $file_containers[0]['files'];
                }
            }
            unset($message);
        }

        $result['type'] = $type;
        $result['status'] = $status;
        $result['order'] = $order;
        $result['direction'] = $direction;

        return $result;
    }

    public function addMessage(array $params)
    {
        $result = array();

        $this->db->Execute(
            'INSERT INTO messages
            (type, cdate, subject, body, userid, sender, contenttype, startdate)
            VALUES (?, ?NOW?, ?, ?, ?, ?, ?, ?)',
            array(
                $params['type'],
                $params['subject'],
                $params['body'],
                $params['userid'] ?? Auth::GetCurrentUser(),
                $params['type'] == MSG_MAIL && isset($params['sender']) ? '"' . $params['sender']['name'] . '" <' . $params['sender']['mail'] . '>' : '',
                $params['contenttype'] ?? 'text/plain',
                isset($params['startdate']) ? $params['startdate'] : null,
            )
        );

        $result['id'] = $msgid  = $this->db->GetLastInsertID('messages');

        $msgitems = array();

        foreach ($params['recipients'] as &$row) {
            switch ($params['type']) {
                case MSG_MAIL:
                    $row['destination'] = explode(',', $row['email']);
                    break;
                case MSG_WWW:
                    $row['destination'] = array(trans('www'));
                    break;
                case MSG_USERPANEL:
                    $row['destination'] = array(trans('userpanel'));
                    break;
                case MSG_USERPANEL_URGENT:
                    $row['destination'] = array(trans('userpanel urgent'));
                    break;
                default:
                    $row['destination'] = explode(',', $row['phone']);
            }

            $customerid = $row['id'] ?? 0;
            foreach ($row['destination'] as $destination) {
                $this->db->Execute(
                    'INSERT INTO messageitems
                    (messageid, customerid, destination, status, error, externalmsgid, attempts)
                    VALUES (?, ?, ?, ?, ?, ?, ?)',
                    array(
                        $msgid,
                        empty($customerid) ? null : $customerid,
                        $destination,
                        $row['status'] ?? MSG_NEW,
                        $row['error'] ?? null,
                        !empty($row['externalmsgid']) ? $row['externalmsgid'] : null,
                        !empty($params['attempts']) ? $params['attempts'] : null,
                    )
                );
                if (!isset($msgitems[$customerid])) {
                    $msgitems[$customerid] = array();
                }
                $msgitems[$customerid][$destination] = $this->db->GetLastInsertID('messageitems');
            }
        }
        unset($row);

        $result['items'] = $msgitems;

        return $result;
    }

    public function updateMessageItems(array $params)
    {
        $args = array();

        if (strcmp($params['original_subject'], $params['real_subject'])) {
            $args['subject'] = $params['real_subject'];
        }

        if (strcmp($params['original_body'], $params['real_body'])) {
            $args['body'] = $params['real_body'];
        }

        if (!empty($args)) {
            $fields = array_keys($args);
            $args['messageid'] = $params['messageid'];

            return $this->db->Execute(
                'UPDATE messageitems
                SET ' . implode(' = ?, ', $fields) . ' = ?
                WHERE messageid = ?
                  AND customerid ' . (!empty($params['customerid']) ? '= ' . intval($params['customerid']) : 'IS NULL'),
                $args
            );
        }

        return 0;
    }

    public function getSingleMessage($id, $details = false)
    {
        $message = $this->db->GetRow(
            'SELECT m.*, u.name AS username, u.id AS userid
            FROM messages m
            LEFT JOIN vusers u ON u.id = m.userid
            WHERE m.id = ?',
            array($id)
        );

        if ($details && !empty($message)) {
            $message['items'] = $this->db->GetAll(
                'SELECT i.messageid AS id, i.status, i.error,
                    i.destination, i.lastdate, i.lastreaddate
                FROM messageitems i
                WHERE i.messageid = ?',
                array($id)
            );
        }

        return $message;
    }
}
