#!/usr/bin/env php
<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2024 LMS Developers
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

$script_parameters = array(
    'test' => 't',
    'fakedate:' => 'f:',
    'fake-date:' => null,
    'force-date:' => null,
    'part-number:' => 'p:',
    'fakehour:' => 'g:',
    'part-size:' => 'l:',
    'interval:' => 'i:',
    'ignore-send-date' => null,
    'extra-file:' => 'e:',
    'backup' => 'b',
    'archive' => 'a',
    'output-directory:' => 'o:',
    'output-file:' => null,
    'no-attachments' => 'n',
    'customerid:' => null,
    'division:' => null,
    'customergroups:' => null,
    'customer-status:' => null,
    'omit-free-days' => null,
);

$script_help = <<<EOF
-t, --test                      print only invoices to send;
-f, --fakedate, --fake-date, --force-date=YYYY/MM/DD       override system date;
-p, --part-number=NN            defines which part of invoices that should be sent;
-g, --fakehour=HH               override system hour; if no fakehour is present - current hour will be used;
                                (deprecated - use --part-number instead of);
-l, --part-size=NN              defines part size of invoices that should be sent
                                (can be specified as percentage value);
-i, --interval=ms               force delay interval between subsequent posts
    --ignore-send-date          send documents which have already been sent earlier;
-e, --extra-file=/tmp/file.pdf  send additional file as attachment
-b, --backup                    make financial document file backup
-a, --archive                   archive financial documents in documents directory
-o, --output-directory=/path    output directory for document backup
    --output-file=/file/name    all invoices, credit notes, pro formas, debit notes and other documents
                                are merged to single file with specified name
-n, --no-attachments            dont attach documents
    --customerid=<id>           limit invoices to specifed customer
    --division=<shortname>
                                limit assignments to customers which belong to specified
                                division
    --customergroups=<group1,group2,...>
                                allow to specify customer groups to which notified customers
                                should be assigned
    --customer-status=<status1,status2,...>
                                send invoices of customers with specified status only
    --omit-free-days            dont send invoices on free days
EOF;

require_once('script-options.php');

define('K_TCPDF_EXTERNAL_CONFIG', true);

$backup = isset($options['backup']);
if ($backup) {
    if (isset($options['output-directory'])) {
        $output_dir = $options['output-directory'];
        if (!is_dir($output_dir)) {
            die('Output directory does not exist!' . PHP_EOL);
        }
    } elseif (isset($options['output-file'])) {
        $output_file = $options['output-file'];
    } else {
        $output_dir = getcwd();
    }
}

$archive = isset($options['archive']);
if ($archive && $backup) {
    die("Archive and backup modes cannot be used simultaneously!" . PHP_EOL);
}

$no_attachments = isset($options['no-attachments']);

if (!$no_attachments) {
    // Initialize templates engine (must be before locale settings)
    $SMARTY = new LMSSmarty;

    // test for proper version of Smarty

    if (defined('Smarty::SMARTY_VERSION')) {
        $ver_chunks = preg_split('/[- ]/', preg_replace('/^smarty-/i', '', Smarty::SMARTY_VERSION), -1, PREG_SPLIT_NO_EMPTY);
    } else {
        $ver_chunks = null;
    }
    if (count($ver_chunks) < 1 || version_compare('3.1', $ver_chunks[0]) > 0) {
        die('Wrong version of Smarty engine! We support only Smarty-3.x greater than 3.1.' . PHP_EOL);
    }

    define('SMARTY_VERSION', $ver_chunks[0]);

    $SMARTY->assign('_PAYTYPES', $PAYTYPES);
}

$SYSLOG = SYSLOG::getInstance();

// Initialize Session, Auth and LMS classes
$AUTH = null;
$LMS = new LMS($DB, $AUTH, $SYSLOG);

$plugin_manager = LMSPluginManager::getInstance();
$LMS->setPluginManager($plugin_manager);

$divisionid = isset($options['division']) ? $LMS->getDivisionIdByShortName($options['division']) : null;
if (!empty($divisionid)) {
    ConfigHelper::setFilter($divisionid);
}

if (!$no_attachments) {
    // Set some template and layout variables

    $SMARTY->setTemplateDir(null);
    $custom_templates_dir = ConfigHelper::getConfig('phpui.custom_templates_dir');
    if (!empty($custom_templates_dir) && file_exists(SMARTY_TEMPLATES_DIR . DIRECTORY_SEPARATOR . $custom_templates_dir)
        && !is_file(SMARTY_TEMPLATES_DIR . DIRECTORY_SEPARATOR . $custom_templates_dir)) {
        $SMARTY->AddTemplateDir(SMARTY_TEMPLATES_DIR . DIRECTORY_SEPARATOR . $custom_templates_dir);
    }
    $SMARTY->AddTemplateDir(
        array(
            SMARTY_TEMPLATES_DIR . DIRECTORY_SEPARATOR . 'default',
            SMARTY_TEMPLATES_DIR,
        )
    );
    $SMARTY->setCompileDir(SMARTY_COMPILE_DIR);

    $SMARTY->assignByRef('layout', $layout);
}

$invoice_filename = ConfigHelper::getConfig('sendinvoices.invoice_filename', 'invoice_%docid');
$dnote_filename = ConfigHelper::getConfig('sendinvoices.debitnote_filename', 'dnote_%docid');

$document_attachment_filename = ConfigHelper::getConfig('documents.attachment_filename', '%filename');

$invoice_type = strtolower(ConfigHelper::getConfig('invoices.type'));
$document_type = strtolower(ConfigHelper::getConfig('documents.type', ConfigHelper::getConfig('phpui.document_type', '', true)));

if ($backup || $archive) {
    $part_size = 0;
    $customer_status_condition = '';
} else {
    // now it's time for script settings
    $smtp_options = array(
        'host' => ConfigHelper::getConfig('sendinvoices.smtp_host'),
        'port' => ConfigHelper::getConfig('sendinvoices.smtp_port'),
        'user' => ConfigHelper::getConfig('sendinvoices.smtp_username', ConfigHelper::getConfig('sendinvoices.smtp_user')),
        'pass' => ConfigHelper::getConfig('sendinvoices.smtp_password', ConfigHelper::getConfig('sendinvoices.smtp_pass')),
        'auth' => ConfigHelper::getConfig('sendinvoices.smtp_auth_type', ConfigHelper::getConfig('sendinvoices.smtp_auth')),
        'ssl_verify_peer' => ConfigHelper::checkConfig('sendinvoices.smtp_ssl_verify_peer', true),
        'ssl_verify_peer_name' => ConfigHelper::checkConfig('sendinvoices.smtp_ssl_verify_peer_name', true),
        'ssl_allow_self_signed' => ConfigHelper::checkConfig('sendinvoices.smtp_ssl_allow_self_signed'),
    );

    $customergroups = ConfigHelper::getConfig('sendinvoices.customergroups', '', true);
    $debug_email = ConfigHelper::getConfig('sendinvoices.debug_email', '', true);
    $sender_name = ConfigHelper::getConfig('sendinvoices.sender_name', '', true);
    $sender_email = ConfigHelper::getConfig('sendinvoices.sender_email', '', true);
    $mail_subject = ConfigHelper::getConfig('sendinvoices.mail_subject', 'Invoice No. %invoice');
    $mail_body = ConfigHelper::getConfig('sendinvoices.mail_body', ConfigHelper::getConfig('mail.sendinvoice_mail_body'));
    $mail_format = ConfigHelper::getConfig('sendinvoices.mail_format', 'text');
    $notify_email = ConfigHelper::getConfig('sendinvoices.notify_email', '', true);
    $blind_notify_email = ConfigHelper::getConfig('sendinvoices.blind_notify_email', '', true);
    $reply_email = ConfigHelper::getConfig('sendinvoices.reply_email', '', true);
    $add_message = ConfigHelper::checkConfig('sendinvoices.add_message');
    $message_attachments = ConfigHelper::checkConfig('sendinvoices.message_attachments');
    $aggregate_documents = ConfigHelper::checkConfig('sendinvoices.aggregate_documents');
    $financial_history_reverse_order = ConfigHelper::checkConfig('sendinvoices.financial_history_reverse_order', true);
    $financial_history_item_description_format = ConfigHelper::getConfig('sendinvoices.financial_history_item_description_format', '%comment');
    $dsn_email = ConfigHelper::getConfig('sendinvoices.dsn_email', '', true);
    $mdn_email = ConfigHelper::getConfig('sendinvoices.mdn_email', '', true);
    $part_size = $options['part-size'] ?? ConfigHelper::getConfig('sendinvoices.limit', '0');

    $use_all_accounts = ConfigHelper::checkConfig('sendinvoices.use_all_accounts');
    $use_only_alternative_accounts = ConfigHelper::checkConfig('sendinvoices.use_only_alternative_accounts');

    $allowed_customer_status = Utils::determineAllowedCustomerStatus(
        $options['customer-status'] ?? ConfigHelper::getConfig('sendinvoices.allowed_customer_status', ''),
        -1
    );

    if (empty($allowed_customer_status)) {
        $customer_status_condition = '';
    } else {
        $customer_status_condition = ' AND c.status IN (' . implode(',', $allowed_customer_status) . ')';
    }

    $interval = $options['interval'] ?? ConfigHelper::getConfig('sendinvoices.interval', 0);
    if ($interval == 'random') {
        $interval = -1;
    } else {
        $interval = intval($interval);
    }

    if (empty($sender_email)) {
        die("Fatal error: sender_email unset! Can't continue, exiting." . PHP_EOL);
    }

    $smtp_auth = empty($smtp_auth) ? ConfigHelper::getConfig('mail.smtp_auth_type') : $smtp_auth;
    if (!empty($smtp_auth) && !preg_match('/^LOGIN|PLAIN|CRAM-MD5|NTLM$/i', $smtp_auth)) {
        die("Fatal error: smtp_auth setting not supported! Can't continue, exiting." . PHP_EOL);
    }

    $part_number = $options['part-number'] ?? ($options['fakehour'] ?? null);
    if (isset($part_number)) {
        $part_number = intval($part_number);
    } else {
        $part_number = intval(date('H', time()));
    }

    if (isset($options['extra-file'])) {
        $extrafile = $options['extra-file'] ?? null;
    } else {
        $extrafile = ConfigHelper::getConfig('sendinvoices.extra_file', null, true);
    }
    if ($extrafile && !is_readable($extrafile)) {
        echo "Warning: unable to read additional file or directory contents [$extrafile]!" . PHP_EOL;
        $extrafile = null;
    }
}

if (isset($options['force-date'])) {
    $fakedate = $options['force-date'];
} elseif (isset($options['fake-date'])) {
    $fakedate = $options['fake-date'];
} elseif (isset($options['fakedate'])) {
    $fakedate = $options['fakedate'];
} else {
    $fakedate = null;
}

$customerid = isset($options['customerid']) && intval($options['customerid']) ? $options['customerid'] : null;

$current_time = strtotime('now');
if (empty($fakedate)) {
    $currtime = $current_time;
} else {
    $currtime = strtotime($fakedate);
}

$omit_free_days = isset($options['omit-free-days']);

[$year, $month, $day] = explode('/', date('Y/n/j', $current_time));

$weekday = date('N', $current_time);
$holidays = array(
    $year => getHolidays($year),
);
if ($omit_free_days && ($weekday > 5 || isset($holidays[$year][$current_time]))) {
    die('Invoices are not sent, because current day is free day!' . PHP_EOL);
}

[$year, $month, $day] = explode('/', date('Y/n/j', $currtime));

$daystart = mktime(0, 0, 0, $month, $day, $year);
$dayend = mktime(23, 59, 59, $month, $day, $year);

if ($archive) {
    $groupnames = '';
} else {
// prepare customergroups in sql query
    if ($backup) {
        $customergroups = null;
    }
    if (isset($options['customergroups'])) {
        $customergroups = $options['customergroups'];
    }
    if (!empty($customergroups)) {
        $ORs = preg_split("/([\s]+|[\s]*,[\s]*)/", mb_strtoupper($customergroups), -1, PREG_SPLIT_NO_EMPTY);
        $customergroup_ORs = array();
        foreach ($ORs as $OR) {
            $ANDs = preg_split("/([\s]*\+[\s]*)/", $OR, -1, PREG_SPLIT_NO_EMPTY);
            $customergroup_ANDs_regular = array();
            $customergroup_ANDs_inversed = array();
            foreach ($ANDs as $AND) {
                if (strpos($AND, '!') === false) {
                    $customergroup_ANDs_regular[] = $AND;
                } else {
                    $customergroup_ANDs_inversed[] = substr($AND, 1);
                }
            }
            $customergroup_ORs[] = '('
                . (empty($customergroup_ANDs_regular) ? '1 = 1' : "EXISTS (SELECT COUNT(*) FROM customergroups
                JOIN vcustomerassignments ON vcustomerassignments.customergroupid = customergroups.id
                WHERE vcustomerassignments.customerid = c.id
                AND UPPER(customergroups.name) IN ('" . implode("', '", $customergroup_ANDs_regular) . "')
                HAVING COUNT(*) = " . count($customergroup_ANDs_regular) . ')')
                . (empty($customergroup_ANDs_inversed) ? '' : " AND NOT EXISTS (SELECT COUNT(*) FROM customergroups
                JOIN vcustomerassignments ON vcustomerassignments.customergroupid = customergroups.id
                WHERE vcustomerassignments.customerid = c.id
                AND UPPER(customergroups.name) IN ('" . implode("', '", $customergroup_ANDs_inversed) . "')
                HAVING COUNT(*) > 0)")
                . ')';
        }
        $customergroups = ' AND (' . implode(' OR ', $customergroup_ORs) . ')';
    }

    if (!$backup) {
        $test = isset($options['test']);
        if ($test) {
            echo "WARNING! You are using test mode." . PHP_EOL;
        }

        if (!empty($part_size) && preg_match('/^[0-9]+$/', $part_size)) {
            $part_offset = $part_number * $part_size;
        }
    }
}

if (empty($customergroups)) {
    $customergroups = '';
}

if (!$no_attachments) {
    $plugin_manager->executeHook('smarty_initialized', $SMARTY);
}

if ($backup || $archive) {
    $args = array(DOC_INVOICE, DOC_INVOICE_PRO, DOC_CNOTE, DOC_DNOTE);
} else {
    $args = array(CONTACT_EMAIL | CONTACT_INVOICES | CONTACT_DISABLED,
        CONTACT_EMAIL | CONTACT_INVOICES, DOC_INVOICE, DOC_INVOICE_PRO, DOC_CNOTE, DOC_DNOTE);

    if ($omit_free_days) {
        $yesterday = strtotime('yesterday', $current_time);
        $yesterday_year = date('Y', $yesterday);
        if (!isset($holidays[$yesterday_year])) {
            $holidays[$yesterday_year] = getHolidays($yesterday_year);
        }
        if (date('N', $yesterday) > 5 || isset($holidays[$yesterday_year][$yesterday])) {
            $prevday = $daystart;
            do {
                $nextday = $prevday;
                $prevday = strtotime('yesterday', $prevday);
                $prevyear = date('Y', $prevday);
                if (!isset($holidays[$prevyear])) {
                    $holidays[$prevyear] = getHolidays($prevyear);
                }
            } while (date('N', $prevday) > 5 || isset($holidays[$prevyear][$prevday]));
            $days = round(($daystart - $nextday) / 86400);
            $daystart = $nextday;

            $diff_days = round(($current_time - $currtime) / 86400);
            if ($days < $diff_days) {
                $daystart = strtotime(($diff_days - $days) . ' days ago', $daystart);
            }
        }
    }

    if (!empty($part_size) && preg_match('/^(?<percent>[0-9]+)%$/', $part_size, $m)) {
        $percent = intval($m['percent']);
        if ($percent < 1 || $percent > 99) {
            $part_size = 0;
        } else {
            $count = intval($DB->GetOne(
                "SELECT COUNT(*)
                FROM documents d
                LEFT JOIN customeraddressview c ON c.id = d.customerid
                JOIN (
                    SELECT customerid, " . $DB->GroupConcat('contact') . " AS email
                    FROM customercontacts
                    WHERE (type & ?) = ?
                    GROUP BY customerid
                ) m ON m.customerid = c.id
                WHERE "
                    . ($divisionid ? 'd.divisionid = ' . $divisionid : '1 = 1')
                    . ($customerid ? ' AND c.id = ' . $customerid : '')
                    . " AND c.deleted = 0
                    AND d.cancelled = 0
                    AND d.type IN (?, ?, ?, ?)
                    AND c.invoicenotice = 1
                    AND d.cdate >= $daystart
                    AND d.cdate <= $dayend"
                    . ($customergroups ?: ''),
                $args
            ));
            if (empty($count)) {
                die;
            }

            $part_size = ceil(($percent * $count) / 100);
            $part_offset = $part_number * $part_size;
            if ((!$part_offset && $part_number) || $part_offset >= $count) {
                die;
            }
        }
    }
}

$ignore_send_date = isset($options['ignore-send-date']) || ConfigHelper::checkConfig('sendinvoices.ignore_send_date');

$query = "SELECT d.id, d.number, d.cdate, d.name, d.customerid,
            d.type AS doctype, d.archived,
            d.senddate, n.template" . ($backup || $archive ? '' : ', m.email') . ",
            (CASE WHEN EXISTS (SELECT 1 FROM documents d2 WHERE d2.reference = d.id AND d2.type < 0) THEN 1 ELSE 0 END) AS documentreferenced
    FROM documents d
    LEFT JOIN customeraddressview c ON c.id = d.customerid"
    . ($backup || $archive ? '' : " JOIN (SELECT customerid, " . $DB->GroupConcat('contact') . " AS email
        FROM customercontacts WHERE (type & ?) = ? GROUP BY customerid) m ON m.customerid = c.id")
    . " LEFT JOIN numberplans n ON n.id = d.numberplanid
    WHERE " . ($customerid ? 'c.id = ' . $customerid : '1 = 1')
        . $customer_status_condition
        . ($divisionid ? ' AND d.divisionid = ' . $divisionid : '')
        . " AND c.deleted = 0 AND d.cancelled = 0 AND d.type IN (?, ?, ?, ?)" . ($backup || $archive ? '' : " AND c.invoicenotice = 1")
        . ($archive ? " AND d.archived = 0" : '') . "
        AND d.cdate >= $daystart AND d.cdate <= $dayend"
        . ($customergroups ?: '')
    . " ORDER BY d.number" . (!empty($part_size) ? " LIMIT $part_size OFFSET $part_offset" : '');
$docs = $DB->GetAll($query, $args);

if (!empty($docs)) {
    if ($backup) {
        $output_file = isset($output_file) && $invoice_type == 'pdf' && $document_type == 'pdf' ? $output_file : null;
        if (isset($output_file)) {
            $pdf_merge_backend = ConfigHelper::getConfig('documents.pdf_merge_backend', 'fpdi');
            if ($pdf_merge_backend == 'pdfunite') {
                $fpdi = new LMSPdfUniteBackend();
            } else {
                $fpdi = new LMSFpdiBackend();
                $fpdi->setPDFVersion(ConfigHelper::getConfig('invoices.pdf_version', '1.7'));
            }
        }

        foreach ($docs as $doc) {
            $doc['invoice_filename'] = $invoice_filename;
            $doc['dnote_filename'] = $dnote_filename;
            $document = $LMS->GetTradeDocument($doc);
            if (!$quiet) {
                echo "Document " . $document['filename'] . " backed up." . PHP_EOL;
            }

            $referenced_documents = array();
            $files = array();

            if (!$no_attachments && !empty($doc['documentreferenced'])) {
                $docrefs = $LMS->getDocumentReferences($doc['id']);

                if (!empty($docrefs)) {
                    foreach ($docrefs as $docid => $docref) {
                        $referenced_document = $LMS->GetDocumentFullContents($docid);
                        if (empty($referenced_document)) {
                            continue;
                        }
                        foreach ($referenced_document['attachments'] as $attachment) {
                            $extension = '';

                            if (!empty($attachment['type'])) {
                                $filename = str_replace(
                                    array(
                                        '%filename',
                                        '%type',
                                        '%document',
                                        '%docid'
                                    ),
                                    array(
                                        $attachment['filename'],
                                        $DOCTYPES[$referenced_document['type']],
                                        $referenced_document['fullnumber'],
                                        $docid,
                                    ),
                                    $document_attachment_filename
                                );

                                if (!preg_match('/\.[[:alnum:]]+$/i', $filename)) {
                                    if (preg_match('/(?<extension>\.[[:alnum:]]+)$/i', $attachment['filename'], $m)) {
                                        $extension = $m['extension'];
                                    } elseif (preg_match('#/(?<extension>[[:alnum:]]+)$#i', $attachment['contenttype'], $m)) {
                                        $extension = '.' . $m['extension'];
                                    }
                                }
                            } else {
                                $filename = $attachment['filename'];
                            }

                            $files[] = array(
                                'content_type' => $attachment['contenttype'],
                                'filename' => preg_replace('/[^[:alnum:]_\.]/i', '_', $filename) . $extension,
                                'data' => $attachment['contents'],
                            );
                        }
                        $referenced_documents[] = $docid;
                    }
                }
            }

            if (!$test) {
                if (isset($output_file)) {
                    $fpdi->AppendPage($document['data']);
                } else {
                    $fh = fopen($output_dir . DIRECTORY_SEPARATOR . $document['filename'], 'w');
                    fwrite($fh, $document['data'], strlen($document['data']));
                    fclose($fh);
                }

                if (!empty($files)) {
                    foreach ($files as $file) {
                        if (isset($output_file)) {
                            $fpdi->AppendPage($file['data']);
                        } else {
                            $fh = fopen($output_dir . DIRECTORY_SEPARATOR . $file['filename'], 'w');
                            fwrite($fh, $file['data'], strlen($file['data']));
                            fclose($fh);
                        }
                    }
                }
            }
        }

        if (!$test && isset($output_file)) {
            $fpdi->WriteToFile($output_file);
        }
    } elseif ($archive) {
        foreach ($docs as $doc) {
            $result = $LMS->ArchiveTradeDocument($doc['id']);
            if (!$quiet && isset($result['ok'])) {
                if ($result['ok']) {
                    echo "Document ID: " . $doc['id'] . " archived with name " . $result['filename'] . "." . PHP_EOL;
                } else {
                    echo $result['error'] . PHP_EOL;
                }
            }
        }
    } else {
        $docs_to_send = array();
        foreach ($docs as $doc) {
            if ($ignore_send_date || empty($doc['senddate'])) {
                $docs_to_send[] = $doc;
            }
        }
        if (empty($docs_to_send)) {
            die;
        }
        $docs = $docs_to_send;
        $which = 0;
        $tmp = explode(',', ConfigHelper::getConfig('invoices.default_printpage'));
        foreach ($tmp as $t) {
            if (trim($t) == 'original') {
                $which |= DOC_ENTITY_ORIGINAL;
            } elseif (trim($t) == 'copy') {
                $which |= DOC_ENTITY_COPY;
            } elseif (trim($t) == 'duplicate') {
                $which |= DOC_ENTITY_DUPLICATE;
            }
        }

        if (!$which) {
            $which = DOC_ENTITY_ORIGINAL;
        }

        $duplicate_date = 0;

        $LMS->SendInvoices($docs, 'backend', compact(
            'SMARTY',
            'invoice_filename',
            'dnote_filename',
            'debug_email',
            'mail_body',
            'mail_subject',
            'mail_format',
            'currtime',
            'sender_email',
            'sender_name',
            'extrafile',
            'dsn_email',
            'reply_email',
            'mdn_email',
            'notify_email',
            'blind_notify_email',
            'quiet',
            'test',
            'add_message',
            'message_attachments',
            'aggregate_documents',
            'financial_history_reverse_order',
            'financial_history_item_description_format',
            'interval',
            'no_attachments',
            'use_all_accounts',
            'use_only_alternative_accounts',
            'which',
            'duplicate_date',
            'smtp_options'
        ));
    }
}
