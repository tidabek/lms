<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2021 LMS Developers
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

class LMSTcpdfInvoice extends LMSInvoice
{
    // default font
    public const TCPDF_FONT = 'liberationsans';

    private $use_alert_color;

    private $transfer_form_on_separate_page = false;

    public function __construct($title, $pagesize = 'A4', $orientation = 'portrait')
    {
        parent::__construct('LMSTcpdfBackend', $title, $pagesize, $orientation);

        $this->backend->setPDFVersion(ConfigHelper::getConfig('invoices.pdf_version', '1.7'));

        $font = ConfigHelper::getConfig('invoices.pdf_font', self::TCPDF_FONT);
        $this->backend->SetFont($font, 'I', 7);
        $this->backend->SetFont($font, 'B', 7);
        $this->backend->SetFont($font, 'BI', 7);
        $this->backend->SetFont($font, '', 7);

        $this->use_alert_color = ConfigHelper::checkConfig('invoices.use_alert_color');

        $this->transfer_form_on_separate_page = ConfigHelper::checkConfig('invoices.transfer_form_on_separate_page');

        [$margin_top, $margin_right, $margin_bottom, $margin_left] = explode(',', ConfigHelper::getConfig('invoices.tcpdf_margins', '27,15,25,15'));

        $this->backend->SetMargins(trim($margin_left), trim($margin_top), trim($margin_right));
        $this->backend->SetAutoPageBreak(true, trim($margin_bottom));
    }

    protected function Table()
    {
        $hide_discount = ConfigHelper::checkConfig('invoices.hide_discount');
        $hide_prodid = ConfigHelper::checkConfig('invoices.hide_prodid');
        $show_tax_category = ConfigHelper::checkConfig('invoices.show_tax_category', true) && !empty($this->data['taxcategories']);

        /* set the line width and headers font */
        $this->backend->SetFillColor(255, 255, 255);
        $this->backend->SetTextColor(0);
        $this->backend->SetDrawColor(0, 0, 0);
        $this->backend->SetLineWidth(0.1);
        $this->backend->SetFont(null, 'B', 7);

        $margins = $this->backend->getMargins();
        $table_width = $this->backend->getPageWidth() - ($margins['left'] + $margins['right']);

        /* invoice headers */
        $heads['no'] = trans('No.');
        $heads['name'] = trans('Name of Product, Commodity or Service');
        if (!$hide_prodid) {
            $heads['prodid'] = trans('<!invoice>Product ID');
        }
        if ($show_tax_category) {
            $heads['taxcategory'] = trans('Tax Category');
        }
        $heads['content'] = trans('Unit');
        $heads['count'] = trans('Amount');
        if (!$hide_discount && (!empty($this->data['pdiscount']) || !empty($this->data['vdiscount'])
            || !empty($this->data['invoice']['pdiscount']) || !empty($this->data['invoice']['vdiscount']))) {
            $heads['discount'] = trans('Discount');
        }
        $heads['basevalue'] = ($this->data['netflag'] ? trans('Unitary Net Value') : trans('Unitary gross value'));
        $heads['totalbase'] = trans('Net Value');
        $heads['taxlabel'] = trans('Tax Rate');
        $heads['totaltax'] = trans('Tax Value');
        $heads['total'] = trans('Gross Value');

        /* width of the columns on the invoice */
        foreach ($heads as $name => $text) {
            //$h_width[$name] = $this->getStringWidth($text, '', 'B', 8);
            $h_width[$name] = $this->backend->getWrapStringWidth($text, 'B');
        }

        /* change the column widths if are wider than the header */
        if ($this->data['content']) {
            foreach ($this->data['content'] as $item) {
                $t_width['no'] = 7;
                $t_width['name'] = $this->backend->getStringWidth($item['description']);
                if (!$hide_prodid) {
                    $t_width['prodid'] = $this->backend->getStringWidth($item['prodid']);
                }
                if ($show_tax_category) {
                    $t_width['taxcategory'] = $this->backend->getStringWidth(sprintf('%02d', $item['taxcategory']));
                }
                $t_width['content'] = $this->backend->getStringWidth($item['content']);
                $t_width['count'] = $this->backend->getStringWidth((float)$item['count']);
                if (!$hide_discount) {
                    $pdiscount = floatval($item['pdiscount']);
                    if (!empty($this->data['pdiscount']) && !empty($pdiscount)) {
                        $t_width['discount'] = $this->backend->getStringWidth(Localisation::formatNumber($item['pdiscount']) . ' %');
                    } elseif (!empty($this->data['vdiscount']) && !empty($item['vdiscount'])) {
                        $t_width['discount'] = $this->backend->getStringWidth(Localisation::smartFormatNumber($item['vdiscount'])) + 1;
                    }
                }
                if ($this->data['netflag']) {
                    $t_width['basevalue'] = $this->backend->getStringWidth(Localisation::smartFormatNumber($item['basevalue'])) + 1;
                } else {
                    $t_width['basevalue'] = $this->backend->getStringWidth(Localisation::smartFormatNumber($item['value'])) + 1;
                }
                $t_width['totalbase'] = $this->backend->getStringWidth(Localisation::formatNumber($item['totalbase'])) + 1;
                $t_width['taxlabel'] = $this->backend->getStringWidth($item['taxlabel']) + 1;
                $t_width['totaltax'] = $this->backend->getStringWidth(Localisation::formatNumber($item['totaltax'])) + 1;
                $t_width['total'] = $this->backend->getStringWidth(Localisation::formatNumber($item['total'])) + 1;
            }
        }

        foreach ($t_width as $name => $w) {
            if ($w > $h_width[$name]) {
                $h_width[$name] = $w;
            }
        }

        if (isset($this->data['invoice']['content']) && $this->data['doctype'] == DOC_CNOTE) {
            foreach ($this->data['invoice']['content'] as $item) {
                $t_width['no'] = 7;
                $t_width['name'] = $this->backend->getStringWidth($item['description']);
                if (!$hide_prodid) {
                    $t_width['prodid'] = $this->backend->getStringWidth($item['prodid']);
                }
                if ($show_tax_category) {
                    $t_width['taxcategory'] = $this->backend->getStringWidth(sprintf('%02d', $item['taxcategory']));
                }
                $t_width['content'] = $this->backend->getStringWidth($item['content']);
                $t_width['count'] = $this->backend->getStringWidth((float)$item['count']);
                if (!$hide_discount) {
                    $pdiscount = floatval($item['pdiscount']);
                    if (!empty($this->data['invoice']['pdiscount']) && !empty($pdiscount)) {
                        $t_width['discount'] = $this->backend->getStringWidth(Localisation::formatNumber($item['pdiscount']). ' %');
                    } elseif (!empty($this->data['invoice']['vdiscount']) && !empty($item['vdiscount'])) {
                        $t_width['discount'] = $this->backend->getStringWidth(Localisation::smartFormatNumber($item['vdiscount'])) + 1;
                    }
                }
                if ($this->data['invoice']['netflag']) {
                    $t_width['basevalue'] = $this->backend->getStringWidth(Localisation::smartFormatNumber($item['basevalue'])) + 1;
                } else {
                    $t_width['basevalue'] = $this->backend->getStringWidth(Localisation::smartFormatNumber($item['value'])) + 1;
                }
                $t_width['totalbase'] = $this->backend->getStringWidth(Localisation::formatNumber($item['totalbase'])) + 1;
                $t_width['taxlabel'] = $this->backend->getStringWidth($item['taxlabel']) + 1;
                $t_width['totaltax'] = $this->backend->getStringWidth(Localisation::formatNumber($item['totaltax'])) + 1;
                $t_width['total'] = $this->backend->getStringWidth(Localisation::formatNumber($item['total'])) + 1;
            }
        }

        foreach ($t_width as $name => $w) {
            if ($w > $h_width[$name]) {
                $h_width[$name] = $w;
            }
        }

        /* dynamic setting the width of the table 'name' */
        $sum = 0;
        foreach ($h_width as $name => $w) {
            if ($name != 'name') {
                $sum += $w;
            }
        }
        $h_width['name'] = $table_width - $sum;

        $h_head = 0;
        /* invoice data table headers */
        foreach ($heads as $item => $name) {
//          $h_cell = $this->backend->getStringHeight($h_width[$item], $heads[$item], true, false, 0, 1);

            $this->backend->startTransaction();
            $old_y = $this->backend->GetY();
            $this->backend->MultiCell($h_width[$item], 0, $name, 1, 'C', true, 1, '', '', true, 0, false, false, 0);
            $h_cell = $this->backend->GetY() - $old_y;
            $this->backend->rollbackTransaction(true);

            if ($h_cell > $h_head) {
                $h_head = $h_cell;
            }
        }
        foreach ($heads as $item => $name) {
            $this->backend->MultiCell($h_width[$item], $h_head, $name, 1, 'C', true, 0, '', '', true, 0, false, false, $h_head, 'M');
        }

        $this->backend->Ln();
        $this->backend->SetFont(null, '', 7);

        /* invoice correction data */
        if (isset($this->data['invoice']) && $this->data['doctype'] == DOC_CNOTE) {
            $this->backend->Ln(3);
            $this->backend->writeHTMLCell(0, 0, '', '', '<b>' . trans('Was:') . '</b>', 0, 1, 0, true, 'L');
            $this->backend->Ln(3);
            $i = 1;
            if ($this->data['invoice']['content']) {
                foreach ($this->data['invoice']['content'] as $item) {
                    $h = $this->backend->getStringHeight($h_width['name'], $item['description'], true, false, '', 1) + 1;
                    $this->backend->Cell($h_width['no'], $h, $i . '.', 1, 0, 'C', 0, '', 1);
                    $this->backend->MultiCell($h_width['name'], $h, $item['description'], 1, 'L', false, 0, '', '', true, 0, false, false, $h, 'M');
                    if (!$hide_prodid) {
                        $this->backend->Cell($h_width['prodid'], $h, $item['prodid'], 1, 0, 'C', 0, '', 1);
                    }
                    if ($show_tax_category) {
                        $this->backend->Cell($h_width['taxcategory'], $h, empty($item['taxcategory']) ? '' : sprintf('%02d', $item['taxcategory']), 1, 0, 'C', 0, '', 1);
                    }
                    $this->backend->Cell($h_width['content'], $h, $item['content'], 1, 0, 'C', 0, '', 1);
                    $this->backend->Cell($h_width['count'], $h, (float)$item['count'], 1, 0, 'C', 0, '', 1);
                    if (!$hide_discount) {
                        $pdiscount = floatval($item['pdiscount']);
                        $vdiscount = floatval($item['vdiscount']);
                        if ((!empty($this->data['pdiscount']) || !empty($this->data['invoice']['pdiscount'])) && !empty($pdiscount)) {
                            $this->backend->Cell($h_width['discount'], $h, Localisation::formatNumber($item['pdiscount']) . ' %', 1, 0, 'R', 0, '', 1);
                        } elseif ((!empty($this->data['vdiscount']) || !empty($this->data['invoice']['vdiscount'])) && !empty($item['vdiscount'])) {
                            $this->backend->Cell($h_width['discount'], $h, Localisation::smartFormatNumber($item['vdiscount']), 1, 0, 'R', 0, '', 1);
                        } elseif (((!empty($this->data['pdiscount']) || !empty($this->data['invoice']['pdiscount'])) && empty($pdiscount))
                            || ((!empty($this->data['vdiscount']) || !empty($this->data['invoice']['vdiscount'])) && empty($vdiscount))) {
                            $this->backend->Cell($h_width['discount'], $h, Localisation::smartFormatNumber(0), 1, 0, 'R', 0, '', 1);
                        }
                    }
                    if ($this->data['invoice']['netflag']) {
                        $this->backend->Cell($h_width['basevalue'], $h, Localisation::smartFormatNumber($item['basevalue']), 1, 0, 'R', 0, '', 1);
                    } else {
                        $this->backend->Cell($h_width['basevalue'], $h, Localisation::smartFormatNumber($item['value']), 1, 0, 'R', 0, '', 1);
                    }
                    $this->backend->Cell($h_width['totalbase'], $h, Localisation::formatNumber($item['totalbase']), 1, 0, 'R', 0, '', 1);
                    $this->backend->Cell($h_width['taxlabel'], $h, $item['taxlabel'], 1, 0, 'C', 0, '', 1);
                    $this->backend->Cell($h_width['totaltax'], $h, Localisation::formatNumber($item['totaltax']), 1, 0, 'R', 0, '', 1);
                    $this->backend->Cell($h_width['total'], $h, Localisation::formatNumber($item['total']), 1, 0, 'R', 0, '', 1);
                    $this->backend->Ln();
                    $i++;
                }
            }

            /* invoice correction summary table - headers */
            $sum = 0;
            foreach ($h_width as $name => $w) {
                if (in_array($name, array('no', 'name', 'prodid', 'taxcategory', 'content', 'count', 'discount', 'basevalue'))) {
                    $sum += $w;
                }
            }

            $this->backend->SetFont(null, 'B', 7);
            $this->backend->Cell($sum, 5, trans('Total:'), 0, 0, 'R', 0, '', 1);
            $this->backend->SetFont(null, '', 7);
            $this->backend->Cell($h_width['totalbase'], 5, Localisation::formatNumber($this->data['invoice']['totalbase']), 1, 0, 'R', 0, '', 1);
            $this->backend->SetFont(null, 'B', 7);
            $this->backend->Cell($h_width['taxlabel'], 5, 'x', 1, 0, 'C', 0, '', 1);
            $this->backend->SetFont(null, '', 7);
            $this->backend->Cell($h_width['totaltax'], 5, Localisation::formatNumber($this->data['invoice']['totaltax']), 1, 0, 'R', 0, '', 1);
            $this->backend->Cell($h_width['total'], 5, Localisation::formatNumber($this->data['invoice']['total']), 1, 0, 'R', 0, '', 1);
            $this->backend->Ln();

            /* invoice correction summary table - data */
            if ($this->data['invoice']['taxest']) {
                $i = 1;
                foreach ($this->data['invoice']['taxest'] as $item) {
                    $this->backend->SetFont(null, 'B', 7);
                    $this->backend->Cell($sum, 5, trans('in it:'), 0, 0, 'R', 0, '', 1);
                    $this->backend->SetFont(null, '', 7);
                    $this->backend->Cell($h_width['totalbase'], 5, Localisation::formatNumber($item['base']), 1, 0, 'R', 0, '', 1);
                    $this->backend->Cell($h_width['taxlabel'], 5, $item['taxlabel'], 1, 0, 'C', 0, '', 1);
                    $this->backend->Cell($h_width['totaltax'], 5, Localisation::formatNumber($item['tax']), 1, 0, 'R', 0, '', 1);
                    $this->backend->Cell($h_width['total'], 5, Localisation::formatNumber($item['total']), 1, 0, 'R', 0, '', 1);
                    $this->backend->Ln(12);
                    $i++;
                }
            }

            /* reason of issue of invoice correction */
            if ($this->data['reason'] != '') {
                $this->backend->writeHTMLCell(0, 0, '', '', '<b>' . trans('Reason:') . ' ' . $this->data['reason'] . '</b>', 0, 1, 0, true, 'L');
            }
            $this->backend->writeHTMLCell(0, 0, '', '', '<b>' . trans('Corrected to:') . '</b>', 0, 1, 0, true, 'L');
            $this->backend->Ln(3);
        }

        /* invoice data */
        $i = 1;
        foreach ($this->data['content'] as $item) {
            $h = $this->backend->getStringHeight($h_width['name'], $item['description'], true, false, '', 1) + 1;
            $this->backend->Cell($h_width['no'], $h, $i . '.', 1, 0, 'C', 0, '', 1);
            $this->backend->MultiCell($h_width['name'], $h, $item['description'], 1, 'L', false, 0, '', '', true, 0, false, false, $h, 'M');
            if (!$hide_prodid) {
                $this->backend->Cell($h_width['prodid'], $h, $item['prodid'], 1, 0, 'C', 0, '', 1);
            }
            if ($show_tax_category) {
                $this->backend->Cell($h_width['taxcategory'], $h, empty($item['taxcategory']) ? '' : sprintf('%02d', $item['taxcategory']), 1, 0, 'C', 0, '', 1);
            }
            $this->backend->Cell($h_width['content'], $h, $item['content'], 1, 0, 'C', 0, '', 1);
            $this->backend->Cell($h_width['count'], $h, (float)$item['count'], 1, 0, 'C', 0, '', 1);
            if (!$hide_discount) {
                $pdiscount = floatval($item['pdiscount']);
                $vdiscount = floatval($item['vdiscount']);
                if ((!empty($this->data['pdiscount']) || !empty($this->data['invoice']['pdiscount'])) && !empty($pdiscount)) {
                    $this->backend->Cell($h_width['discount'], $h, Localisation::formatNumber($item['pdiscount']) . ' %', 1, 0, 'R', 0, '', 1);
                } elseif ((!empty($this->data['vdiscount']) || !empty($this->data['invoice']['vdiscount'])) && !empty($item['vdiscount'])) {
                    $this->backend->Cell($h_width['discount'], $h, Localisation::smartFormatNumber($item['vdiscount']), 1, 0, 'R', 0, '', 1);
                } elseif (((!empty($this->data['pdiscount']) || !empty($this->data['invoice']['pdiscount'])) && empty($pdiscount))
                    || ((!empty($this->data['vdiscount']) || !empty($this->data['invoice']['vdiscount'])) && empty($vdiscount))) {
                    $this->backend->Cell($h_width['discount'], $h, Localisation::smartFormatNumber(0), 1, 0, 'R', 0, '', 1);
                }
            }
            if ($this->data['netflag']) {
                $this->backend->Cell($h_width['basevalue'], $h, Localisation::smartFormatNumber($item['basevalue']), 1, 0, 'R', 0, '', 1);
            } else {
                $this->backend->Cell($h_width['basevalue'], $h, Localisation::smartFormatNumber($item['value']), 1, 0, 'R', 0, '', 1);
            }
            $this->backend->Cell($h_width['totalbase'], $h, Localisation::formatNumber($item['totalbase']), 1, 0, 'R', 0, '', 1);
            $this->backend->Cell($h_width['taxlabel'], $h, $item['taxlabel'], 1, 0, 'C', 0, '', 1);
            $this->backend->Cell($h_width['totaltax'], $h, Localisation::formatNumber($item['totaltax']), 1, 0, 'R', 0, '', 1);
            $this->backend->Cell($h_width['total'], $h, Localisation::formatNumber($item['total']), 1, 0, 'R', 0, '', 1);
            $this->backend->Ln();
            $i++;
        }

        /* invoice summary table - headers */
        $sum = 0;
        foreach ($h_width as $name => $w) {
            if (in_array($name, array('no', 'name', 'prodid', 'taxcategory', 'content', 'count', 'discount', 'basevalue'))) {
                $sum += $w;
            }
        }

        $this->backend->SetFont(null, 'B', 7);
        $this->backend->Cell($sum, 5, trans('Total:'), 0, 0, 'R', 0, '', 1);
        $this->backend->SetFont(null, '', 7);
        $this->backend->Cell($h_width['totalbase'], 5, Localisation::formatNumber($this->data['totalbase']), 1, 0, 'R', 0, '', 1);
        $this->backend->SetFont(null, 'B', 7);
        $this->backend->Cell($h_width['taxlabel'], 5, 'x', 1, 0, 'C', 0, '', 1);
        $this->backend->SetFont(null, '', 7);
        $this->backend->Cell($h_width['totaltax'], 5, Localisation::formatNumber($this->data['totaltax']), 1, 0, 'R', 0, '', 1);
        $this->backend->Cell($h_width['total'], 5, Localisation::formatNumber($this->data['total']), 1, 0, 'R', 0, '', 1);
        $this->backend->Ln();

        /* invoice summary table - data */
        if ($this->data['taxest']) {
            $i = 1;
            foreach ($this->data['taxest'] as $item) {
                $this->backend->SetFont(null, 'B', 7);
                $this->backend->Cell($sum, 5, trans('in it:'), 0, 0, 'R', 0, '', 1);
                $this->backend->SetFont(null, '', 7);
                $this->backend->Cell($h_width['totalbase'], 5, Localisation::formatNumber($item['base']), 1, 0, 'R', 0, '', 1);
                $this->backend->Cell($h_width['taxlabel'], 5, $item['taxlabel'], 1, 0, 'C', 0, '', 1);
                $this->backend->Cell($h_width['totaltax'], 5, Localisation::formatNumber($item['tax']), 1, 0, 'R', 0, '', 1);
                $this->backend->Cell($h_width['total'], 5, Localisation::formatNumber($item['total']), 1, 0, 'R', 0, '', 1);
                $this->backend->Ln();
                $i++;
            }
        }

        $this->backend->Ln(3);
        /* difference between the invoice and the invoice correction */
        if (isset($this->data['invoice']) && $this->data['doctype'] == DOC_CNOTE) {
            $total = $this->data['total'] - $this->data['invoice']['total'];
            $totalbase = $this->data['totalbase'] - $this->data['invoice']['totalbase'];
            $totaltax = $this->data['totaltax'] - $this->data['invoice']['totaltax'];

            $this->backend->SetFont(null, 'B', 7);
            $this->backend->Cell($sum, 5, trans('Difference value:'), 0, 0, 'R', 0, '', 1);
            $this->backend->SetFont(null, '', 7);
            $this->backend->Cell($h_width['totalbase'], 5, Localisation::formatNumber($totalbase), 1, 0, 'R', 0, '', 1);
            $this->backend->SetFont(null, 'B', 7);
            $this->backend->Cell($h_width['taxlabel'], 5, 'x', 1, 0, 'C', 0, '', 1);
            $this->backend->SetFont(null, '', 7);
            $this->backend->Cell($h_width['totaltax'], 5, Localisation::formatNumber($totaltax), 1, 0, 'R', 0, '', 1);
            $this->backend->Cell($h_width['total'], 5, Localisation::formatNumber($total), 1, 0, 'R', 0, '', 1);
            $this->backend->Ln();
        }
    }

    protected function invoice_jpk_flags()
    {
        global $DOC_FLAGS;
        $flags = array();

        if (!empty($this->data['splitpayment'])) {
            $flags[] = $DOC_FLAGS[DOC_FLAG_SPLIT_PAYMENT];
        }

        foreach ($this->data['flags'] as $flag => $value) {
            if (!empty($value)) {
                if (($flag & DOC_FLAG_TELECOM_SERVICE) && $this->data['cdate'] >= mktime(0, 0, 0, 7, 1, 2021)) {
                    $flags[] = trans('WSTO_EE');
                } else {
                    $flags[] = $DOC_FLAGS[$flag];
                }
            }
        }

        if (!empty($this->data['taxcategories'])) {
            foreach ($this->data['taxcategories'] as $taxcategory) {
                $flags[] = sprintf('GTU_%02d', $taxcategory);
            }
        }

        if (!empty($flags)) {
            $this->backend->SetFont(null, '', 9);
            $this->backend->writeHTMLCell(0, 0, '', 3, trans('JPK:') . ' <b>' . implode(', ', $flags) . '</b>', 0, 1, 0, true, 'C');
        }
    }

    protected function invoice_date()
    {
        $this->backend->SetFont(null, '', 8);
        $this->backend->writeHTMLCell(0, 0, '', 10, trans('Settlement date:') . ' <b>' . date("d.m.Y", $this->data['cdate']) . '</b>', 0, 1, 0, true, 'R');
        if (!ConfigHelper::checkConfig('invoices.hide_sale_date')) {
            $this->backend->writeHTMLCell(0, 0, '', '', trans('Sale date:') . ' <b>' . date("d.m.Y", $this->data['sdate']) . '</b>', 0, 1, 0, true, 'R');
        }
    }

    protected function invoice_title()
    {
        $this->backend->SetY(29);
        $this->backend->SetFont(null, 'B', 16);
        $docnumber = docnumber(array(
            'number' => $this->data['number'],
            'template' => $this->data['template'],
            'cdate' => $this->data['cdate'],
            'customerid' => $this->data['customerid'],
        ));
        if (isset($this->data['invoice']) && $this->data['doctype'] == DOC_CNOTE) {
            $title = trans('Credit Note No. $a', $docnumber);
        } elseif ($this->data['doctype'] == DOC_INVOICE) {
            $title = trans('Invoice No. $a', $docnumber);
        } else {
            $title = trans('Pro Forma Invoice No. $a', $docnumber);
        }
        $this->backend->Write(0, $title, '', 0, 'C', true, 0, false, false, 0);

        if (isset($this->data['invoice']) && $this->data['doctype'] == DOC_CNOTE) {
            $this->backend->SetFont(null, 'B', 12);
            $docnumber = docnumber(array(
                'number' => $this->data['invoice']['number'],
                'template' => $this->data['invoice']['template'],
                'cdate' => $this->data['invoice']['cdate'],
                'customerid' => $this->data['customerid'],
            ));
            $this->backend->Write(0, trans('for Invoice No. $a', $docnumber), '', 0, 'C', true, 0, false, false, 0);
        }

        //$this->backend->SetFont(null, '', 16);
        //$this->backend->Write(0, $this->data['type'], '', 0, 'C', true, 0, false, false, 0);

        if ($this->data['type'] == DOC_ENTITY_DUPLICATE) {
            $this->backend->SetFont(null, '', 10);
            $title = trans('DUPLICATE, draw-up date:') . ' ' . date('d.m.Y', $this->data['duplicate-date']
                ?: time());
            $this->backend->Write(0, $title, '', 0, 'C', true, 0, false, false, 0);
        }
    }

    protected function invoice_seller()
    {
        $this->backend->SetFont(null, '', 8);
        $seller = '<b>' . trans('Seller:') . '</b><br>';
        $tmp = str_replace('%ten%', format_ten($this->data['division_ten'], $this->data['export']), $this->data['division_header']);

        if (!ConfigHelper::checkConfig('invoices.show_only_alternative_accounts')
            || empty($this->data['bankaccounts'])) {
            $accounts = array(bankaccount($this->data['customerid'], $this->data['account'], $this->data['export']));
        } else {
            $accounts = array();
        }
        if (ConfigHelper::checkConfig('invoices.show_all_accounts')
            || ConfigHelper::checkConfig('invoices.show_only_alternative_accounts')) {
            $accounts = array_merge($accounts, $this->data['bankaccounts']);
        }
        foreach ($accounts as &$account) {
            $account = format_bankaccount($account, $this->data['export']);
        }
        $account_text = ($this->use_alert_color ? '<span style="color:red">' : '')
            . implode("\n", $accounts)
            . ($this->use_alert_color ? '</span>' : '');

        $tmp = str_replace(
            array(
                '%bankaccount',
                '%bankname',
                '%extid',
            ),
            array(
                $account_text,
                $this->data['div_bank'] ?? '',
                $this->data['extid'] ?? '-',
            ),
            $tmp
        );

        if (ConfigHelper::checkConfig('invoices.customer_bankaccount', true)) {
            $tmp .= "\n" . trans('Bank account:') . "\n" . '<B>' . $account_text . '<B>';
        }

        $tmp = preg_split('/\r?\n/', $tmp);
        foreach ($tmp as $line) {
            $seller .= $line . '<br>';
        }
        $this->backend->Ln(0);
        $this->backend->writeHTMLCell(80, '', '', 45, $seller, 0, 1, 0, true, 'L');
    }

    protected function invoice_buyer()
    {
        $oldy = $this->backend->GetY();

        $buyer = '<b>' . trans('Purchaser:') . '</b><br>';

        $buyer .= $this->data['name'] . '<br>';
        $buyer .= $this->data['address'] . '<br>';
        $buyer .= $this->data['zip'] . ' ' . $this->data['city'];
        if ($this->data['export']) {
            $buyer .= ', ' . trans($this->data['country']);
        }
        $buyer .= '<br>';
        if ($this->data['ten']) {
            $currentSystemLanguage = Localisation::getCurrentSystemLanguage();
            Localisation::setSystemLanguage($this->data['lang']);
            $buyer .= trans('TEN') . ': ' . format_ten($this->data['ten'], $this->data['export']) . '<br>';
            Localisation::setSystemLanguage($currentSystemLanguage);
        } elseif (!ConfigHelper::checkConfig('invoices.hide_ssn', true) && $this->data['ssn']) {
            $buyer .= trans('SSN') . ': ' . $this->data['ssn'] . '<br>';
        }
        if (ConfigHelper::checkConfig('invoices.show_customerid', true)) {
            $buyer .= '<b>' . trans('Customer No.:') . ' ' . $this->data['customerid'] . '</b><br>';
        }
        $this->backend->SetFont(null, '', 8);
        $this->backend->writeHTMLCell(80, '', '', '', $buyer, 0, 1, 0, true, 'L');

        $y = $this->backend->GetY();

        if (ConfigHelper::checkConfig('invoices.post_address', true)) {
            $postbox = '';
            if ($this->data['post_name'] || $this->data['post_address']) {
                $lines = document_address(array(
                    'name' => $this->data['post_name'] ?: $this->data['name'],
                    'address' => $this->data['post_address'],
                    'street' => $this->data['post_street'],
                    'zip' => $this->data['post_zip'],
                    'postoffice' => $this->data['post_postoffice'],
                    'city' => $this->data['post_city'],
                ));
                $postbox .= implode('<br>', $lines);
            } else {
                $postbox .= $this->data['name'] . '<br>';
                $postbox .= $this->data['address'] . '<br>';
                $postbox .= $this->data['zip'] . ' ' . $this->data['city'] . '<br>';
            }

            if ($this->data['export']) {
                $postbox .= trans(empty($this->data['post_country']) ? $this->data['country'] : $this->data['post_country']) . '<br>';
            }

            $this->backend->SetFont(null, 'B', 10);
            $this->backend->writeHTMLCell(80, '', 125, 50, $postbox, 0, 1, 0, true, 'L');
        }

        if (ConfigHelper::checkConfig('invoices.customer_credentials', true)) {
            $pin = str_replace(
                array('%cid', '%pin'),
                array(
                    sprintf('%04d', $this->data['customerid']),
                    strlen($this->data['customerpin']) < 4 ? sprintf('%04d', $this->data['customerpin']) : $this->data['customerpin']
                ),
                ConfigHelper::getConfig(
                    'invoices.customer_credentials_format',
                    '<b>' . trans('Customer ID: %cid') . '</b><br>'
                    . '<b>' . trans('PIN: %pin') . '</b><br>'
                )
            );

            $this->backend->SetFont(null, 'B', 7);
            $this->backend->writeHTMLCell('', '', 125, $oldy + round(($y - $oldy) / 2), $pin, 0, 1, 0, true, 'L');
        }

        $this->backend->SetY($y);
    }

    protected function invoice_recipient()
    {
        if (empty($this->data['recipient_address_id'])) {
            return 0;
        }

        $this->backend->SetFont(null, '', 8);

        $this->backend->writeHTMLCell(80, '', '', '', '<b>' . trans('Recipient:') . '</b>', 0, 1, 0, true, 'L');

        $rec_lines = document_address(array(
            'name' => $this->data['rec_name'],
            'address' => $this->data['rec_address'],
            'street' => $this->data['rec_street'],
            'zip' => $this->data['rec_zip'],
            'postoffice' => $this->data['rec_postoffice'],
            'city' => $this->data['rec_city'],
        ));

        foreach ($rec_lines as $line) {
            $this->backend->writeHTMLCell(80, '', '', '', $line, 0, 1, 0, true, 'L');
        }
    }

    protected function invoice_data()
    {
        /* print table */
        $this->backend->writeHTMLCell('', '', '', '', '', 0, 1, 0, true, 'L');
        $this->Table();
    }

    protected function invoice_to_pay()
    {
        global $PAYTYPES;

        $show_balance_summary = ConfigHelper::checkConfig('invoices.show_balance_summary');

        $this->backend->Ln(-9);
        $this->backend->SetFont(null, 'B', $show_balance_summary ? 9 : 14);
        if (isset($this->data['rebate'])) {
            $this->backend->writeHTMLCell(
                0,
                0,
                '',
                '',
                trans(
                    $this->data['doctype'] != DOC_CNOTE
                        ? ($PAYTYPES[$this->data['paytype']]['features'] & INVOICE_FEATURE_TO_PAY
                            ? 'Invoice value: $a (to repay)'
                            : 'Invoice value: $a'
                        )
                        : ($PAYTYPES[$this->data['paytype']]['features'] & INVOICE_FEATURE_TO_PAY
                            ? 'Correction value: $a (to repay)'
                            : 'Correction value: $a'
                        ),
                    Utils::formatMoney($this->data['value'], $this->data['currency'])
                ),
                0,
                1,
                0,
                true,
                'L'
            );
        } else {
            if (!$show_balance_summary && $this->use_alert_color) {
                $this->backend->SetTextColorArray(array(255, 0, 0));
            }
            $this->backend->writeHTMLCell(
                0,
                0,
                '',
                '',
                trans(
                    $this->data['doctype'] != DOC_CNOTE
                        ? ($PAYTYPES[$this->data['paytype']]['features'] & INVOICE_FEATURE_TO_PAY
                            ? 'Invoice value: $a (to pay)'
                            : 'Invoice value: $a'
                        )
                        : ($PAYTYPES[$this->data['paytype']]['features'] & INVOICE_FEATURE_TO_PAY
                            ? 'Correction value: $a (to pay)'
                            : 'Correction value: $a'
                        ),
                    Utils::formatMoney($this->data['value'], $this->data['currency'])
                ),
                0,
                1,
                0,
                true,
                'L'
            );
            if (!$show_balance_summary && $this->use_alert_color) {
                $this->backend->SetTextColor();
            }
        }

        $this->backend->SetFont(null, '', 7);
        if (!ConfigHelper::checkConfig('invoices.hide_in_words')) {
            $this->backend->writeHTMLCell(0, 5, '', '', trans('In words:') . ' ' . moneyf_in_words($this->data['value'], $this->data['currency']), 0, 1, 0, true, 'L');
        }
    }

    protected function invoice_balance()
    {
        $this->backend->SetFont(null, 'B', 9);

        $show_balance_summary = $this->data['doctype'] == DOC_DNOTE
            ? ConfigHelper::checkConfig('notes.show_balance_summary', ConfigHelper::checkConfig('invoices.show_balance_summary'))
            : ConfigHelper::checkConfig('invoices.show_balance_summary');

        $balance = $this->data['customerbalance'];

        if ($show_balance_summary) {
            if (isset($this->data['invoice']) && $this->data['doctype'] == DOC_CNOTE) {
                $total = $this->data['total'] - $this->data['invoice']['total'];
            } else {
                $total = $this->data['total'];
            }
            $previous_balance = $balance + $total;

            if ($previous_balance > 0) {
                $comment = trans('(excess payment)');
            } elseif ($previous_balance < 0) {
                $comment = trans('(to pay)');
            } else {
                $comment = '';
            }

            $this->backend->writeHTMLCell(
                0,
                0,
                '',
                '',
                trans(
                    'Previous balance: $a $b',
                    Utils::formatMoney(abs($previous_balance) / $this->data['currencyvalue'], $this->data['currency']),
                    $comment
                ),
                0,
                1,
                0,
                true,
                'L'
            );
        } else {
            if ($balance > 0) {
                $comment = trans('(excess payment)');
            } elseif ($balance < 0) {
                $comment = trans('(to pay)');
            } else {
                $comment = '';
            }

            if ($this->use_alert_color) {
                $this->backend->SetTextColorArray(array(255, 0, 0));
            }
            $this->backend->writeHTMLCell(
                0,
                0,
                '',
                '',
                trans(
                    'Your balance on date of document issue: $a $b',
                    Utils::formatMoney($balance / $this->data['currencyvalue'], $this->data['currency']),
                    $comment
                ),
                0,
                1,
                0,
                true,
                'L'
            );
            if ($this->use_alert_color) {
                $this->backend->SetTextColor();
            }
            $this->backend->writeHTMLCell(0, 0, '', '', trans('Balance includes current invoice'), 0, 1, 0, true, 'L');
        }

        if ($show_balance_summary) {
            $this->backend->SetFont(null, 'B', 14);
            if ($this->use_alert_color) {
                $this->backend->SetTextColorArray(array(255, 0, 0));
            }
            $this->backend->writeHTMLCell(
                0,
                0,
                '',
                '',
                $balance > 0
                    ? trans(
                        'Excess payment: $a',
                        Utils::formatMoney($balance / $this->data['currencyvalue'], $this->data['currency'])
                    )
                    : trans(
                        'Total to pay: $a',
                        Utils::formatMoney(-$balance / $this->data['currencyvalue'], $this->data['currency'])
                    ),
                0,
                1,
                0,
                true,
                'L'
            );
            if ($this->use_alert_color) {
                $this->backend->SetTextColor();
            }
        }
    }

    protected function invoice_pricing_method()
    {
        $this->backend->SetFont(null, 'B', 9);

        if (isset($this->data['netflag']) && !empty($this->data['netflag'])) {
            $comment = trans('net');
        } else {
            $comment = trans('gross');
        }
        $this->backend->writeHTMLCell(0, 0, '', '', trans('The document is issued according to the $a price', $comment), 0, 1, 0, true, 'L');
    }

    protected function invoice_dates()
    {
        global $PAYTYPES;

        $this->backend->SetFont(null, '', 8);

        if ($this->use_alert_color) {
            $this->backend->SetTextColorArray(array(255, 0, 0));
        }
        if ($PAYTYPES[$this->data['paytype']]['features'] & INVOICE_FEATURE_DEADLINE) {
            $this->backend->writeHTMLCell(0, 0, '', 17, trans('Deadline:') . ' <b>' . date("d.m.Y", $this->data['pdate']) . '</b>', 0, 1, 0, true, 'R');
        } else {
            $this->backend->writeHTMLCell(0, 0, '', 17, '<b>' . trans('Payment Cleared') . '</b>', 0, 1, 0, true, 'R');
        }
        if ($this->use_alert_color) {
            $this->backend->SetTextColor();
        }

        if ($this->data['doctype'] != DOC_DNOTE && !ConfigHelper::checkConfig('invoices.hide_payment_type')
            || $this->data['doctype'] == DOC_DNOTE && !ConfigHelper::checkConfig('notes.hide_payment_type', ConfigHelper::checkConfig('invoices.hide_payment_type'))) {
            $this->backend->writeHTMLCell(0, 0, '', '', trans('Payment type:') . ' <b>' . trans($this->data['paytypename']) . '</b>', 0, 1, 0, true, 'R');
            if (!empty($this->data['splitpayment'])) {
                $this->backend->writeHTMLCell(0, 0, '', '', '<b>' . trans('(split payment)') . '</b>', 0, 1, 0, true, 'R');
            }
            if (!empty($this->data['flags'][DOC_FLAG_RECEIPT])) {
                $this->backend->writeHTMLCell(0, 0, '', '', '<b>' . trans('<!invoice>(receipt)') . '</b>', 0, 1, 0, true, 'R');
            }
        }
    }

    protected function invoice_expositor()
    {
        if (($this->data['doctype'] != DOC_DNOTE && !ConfigHelper::checkConfig('invoices.hide_expositor')
            || $this->data['doctype'] == DOC_DNOTE && !ConfigHelper::checkConfig('notes.hide_expositor', ConfigHelper::CheckConfig('invoices.hide_expositor')))
            && !empty($this->data['expositor'])) {
            $this->backend->SetFont(null, '', 8);
            $this->backend->writeHTMLCell(0, 0, '', '', trans('Expositor: <b>$a</b>', $this->data['expositor']), 0, 1, 0, true, 'R');
        }
    }

    protected function invoice_comment()
    {
        if (!empty($this->data['comment'])) {
            if (($this->data['doctype'] != DOC_DNOTE && ConfigHelper::checkConfig('invoices.qr2pay')
                || $this->data['doctype'] == DOC_DNOTE && ConfigHelper::checkConfig('notes.qr2pay', ConfigHelper::checkConfig('invoices.qr2pay')))
                && !isset($this->data['rebate'])) {
                $width = 150;
            } else {
                $width = 0;
            }
            $this->backend->SetFont(null, '', 8);
            $this->backend->Ln(5);
            $this->backend->writeHTMLCell($width, 0, '', '', trans('Comment:') . ' ' . $this->data['comment'], 0, 1, 0, true, 'C');
        }
    }

    protected function invoice_footnote()
    {
        if (!empty($this->data['division_footer'])) {
            //$this->backend->Ln(145);
            //$this->backend->SetFont(null, 'B', 10);
            //$this->backend->Write(0, trans('Notes:'), '', 0, 'L', true, 0, false, false, 0);
            $tmp = $this->data['division_footer'];

            $accounts = array(bankaccount($this->data['customerid'], $this->data['account'], $this->data['export']));
            if ($this->data['doctype'] != DOC_DNOTE && ConfigHelper::checkConfig('invoices.show_all_accounts')
                || $this->data['doctype'] == DOC_DNOTE && ConfigHelper::checkConfig('notes.show_all_accounts', ConfigHelper::checkConfig('invoices.show_all_accounts'))) {
                $accounts = array_merge($accounts, $this->data['bankaccounts']);
            }
            foreach ($accounts as &$account) {
                $account = format_bankaccount($account, $this->data['export']);
            }

            $tmp = str_replace(
                array(
                    '%bankaccount',
                    '%bankname',
                    '%extid',
                ),
                array(
                    implode("\n", $accounts),
                    $this->data['div_bank'] ?? '',
                    $this->data['extid'] ?? '-',
                ),
                $tmp
            );

            $this->backend->SetFont(null, '', 8);
            //$h = $this->backend->getStringHeight(0, $tmp);
            $tmp = mb_ereg_replace('\r?\n', '<br>', $tmp);
            if (($this->data['doctype'] != DOC_DNOTE && ConfigHelper::checkConfig('invoices.qr2pay')
                || $this->data['doctype'] == DOC_DNOTE && ConfigHelper::checkConfig('notes.qr2pay', ConfigHelper::checkConfig('invoices.qr2pay')))
                && !isset($this->data['rebate'])) {
                $width = 150;
            } else {
                $width = 0;
            }
            $this->backend->Ln(5);
            $this->backend->writeHTMLCell($width, 0, '', '', $tmp, 0, 1, 0, true, 'C');
        }
    }

    protected function invoice_memo()
    {
        if (!empty($this->data['memo'])) {
            $tmp = $this->data['memo'];

            $this->backend->SetFont(null, 'I', 7);
            $this->backend->SetFont(null, 'B', 7);
            $this->backend->SetFont(null, 'BI', 7);
            $this->backend->SetFont(null);

            $tmp = mb_ereg_replace('\r?\n', '<br>', $tmp);
            if (($this->data['doctype'] != DOC_DNOTE && ConfigHelper::checkConfig('invoices.qr2pay')
                || $this->data['doctype'] == DOC_DNOTE && ConfigHelper::checkConfig('notes.qr2pay', ConfigHelper::checkConfig('invoices.qr2pay')))
                && !isset($this->data['rebate'])) {
                $width = 150;
            } else {
                $width = 0;
            }
            $this->backend->Ln(5);
            $this->backend->writeHTMLCell($width, 0, '', '', $tmp, 0, 1, 0, true, 'C');
        }
    }

    public function invoice_header_image()
    {
        $image_path = $this->data['doctype'] != DOC_DNOTE
            ? ConfigHelper::getConfig('invoices.header_image', '', true)
            : ConfigHelper::getConfig('notes.header_image', ConfigHelper::getConfig('invoices.header_image', '', true), true);

        if (!strlen($image_path)) {
            return;
        }

        if (strpos($image_path, DIRECTORY_SEPARATOR) !== 0) {
            $image_path = SYS_DIR . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . $image_path;
        }

        if (!is_readable($image_path) || !preg_match('/\.(?<ext>svg|gif|jpg|jpeg|png)$/i', $image_path, $m)) {
            return;
        }

        switch (strtolower($m['ext'])) {
            case 'svg':
                $this->backend->ImageSVG($image_path, 12, 8, 40, 0);
                break;
            default:
                $this->backend->Image($image_path, 12, 8, 40, 0);
                break;
        }
    }

    public function invoice_cancelled()
    {
        if ($this->data['cancelled']) {
            $x = $this->backend->GetX();
            $y = $this->backend->GetY();

            $this->backend->setTextColorArray(array(128, 128, 128));
            $this->backend->StartTransform();
            $this->backend->SetFont(null, '', 40);
            $this->backend->Rotate(45, 10, 210);
            $this->backend->Translate(30, 0);
            $this->backend->SetXY(10, 210);
            $this->backend->Write(0, trans('CANCELLED'), '', 0, 'C', true, 0, false, false, 0);
            $this->backend->StopTransform();
            $this->backend->setTextColorArray(array(0, 0, 0));

            $this->backend->SetXY($x, $y);
        }
    }

    public function invoice_no_accountant()
    {
        if (!empty($this->data['dontpublish']) && $this->data['dontpublish'] && !$this->data['cancelled']) {
            $x = $this->backend->GetX();
            $y = $this->backend->GetY();

            $this->backend->setTextColorArray(array(128, 128, 128));
            $this->backend->StartTransform();
            $this->backend->SetFont(null, '', 40);
            $this->backend->Rotate(45, 10, 210);
            $this->backend->Translate(30, 0);
            $this->backend->SetXY(10, 210);
            $this->backend->Write(0, trans('NO ACCOUNTANT DOCUMENT'), '', 0, 'C', true, 0, false, false, 0);
            $this->backend->StopTransform();
            $this->backend->setTextColorArray(array(0, 0, 0));

            $this->backend->SetXY($x, $y);
        }
    }

    public function invoice_qr2pay_code()
    {
        $x = $this->backend->GetX();
        $y = $this->backend->GetY();

        $docnumber = docnumber(array(
            'number' => $this->data['number'],
            'template' => $this->data['template'],
            'cdate' => $this->data['cdate'],
            'customerid' => $this->data['customerid'],
        ));

        if (($this->data['doctype'] != DOC_DNOTE && !ConfigHelper::checkConfig('invoices.show_only_alternative_accounts')
            || $this->data['doctype'] == DOC_DNOTE && !ConfigHelper::checkConfig('notes.show_only_alternative_accounts', ConfigHelper::checkConfig('invoices.show_only_alternative_accounts')))
            || empty($this->data['bankaccounts'])) {
            $accounts = array(bankaccount($this->data['customerid'], $this->data['account'], $this->data['export']));
        } else {
            $accounts = array();
        }
        if ($this->data['doctype'] != DOC_DNOTE
            && (ConfigHelper::checkConfig('invoices.show_all_accounts')
            || ConfigHelper::checkConfig('invoices.show_only_alternative_accounts'))
            || $this->data['doctype'] == DOC_DNOTE
            && (ConfigHelper::checkConfig('notes.show_all_accounts', ConfigHelper::checkConfig('invoices.show_all_accounts'))
            || ConfigHelper::checkConfig('notes.show_only_alternative_accounts', ConfigHelper::checkConfig('invoices.show_only_alternative_accounts')))) {
            $accounts = array_merge($accounts, $this->data['bankaccounts']);
        }
        $account = reset($accounts);

        if ($this->data['doctype'] != DOC_DNOTE && ConfigHelper::checkConfig('invoices.customer_balance_in_form')
            || $this->data['doctype'] == DOC_DNOTE && ConfigHelper::checkConfig('notes.customer_balance_in_form', ConfigHelper::checkConfig('invoices.customer_balance_in_form'))) {
            $payment_value = $this->data['customerbalance'] * -1;
        } else {
            $payment_value = $this->data['value'];
        }

        $qr2pay_comment = ConfigHelper::getConfig(
            'notes.qr2pay_comment',
            ConfigHelper::getConfig(
                'invoices.qr2pay_comment',
                trans('QR Payment for Internet Invoice no. %number')
            )
        );

        $customerid = $this->data['customerid'];

        $this->backend->SetFont(null, '', 7);
        $this->backend->writeHTMLCell(150, 0, '', '', trans("&nbsp; <BR> Scan and Pay <BR> You can make a transfer simply and quickly using your phone. <BR> To make a transfer, please scan QRcode on you smartphone in your bank's application."), 0, 1, 0, true, 'R');
        $tmp = preg_replace('/[^0-9]/', '', $this->data['division_ten'])
            . '|'
            . 'PL'
            . '|'
            . $account
            . '|'
            . str_pad(($payment_value < 0 ? 0 : $payment_value) * 100, 6, 0, STR_PAD_LEFT)
            . '|'
            . mb_substr($this->data['division_shortname'], 0, 20)
            . '|'
            . str_replace(
                array(
                    '%number',
                ),
                array(
                    $docnumber,
                ),
                preg_replace_callback(
                    '/%(\\d*)cid/',
                    function ($m) use ($customerid) {
                        return sprintf('%0' . $m[1] . 'd', $customerid);
                    },
                    $qr2pay_comment
                )
            )
            . '|||';
        $style['position'] = 'R';
        $this->backend->write2DBarcode($tmp, 'QRCODE,M', $x, $y, 30, 30, $style);
        unset($tmp);
    }

    public function invoice_body_standard()
    {
        if (!empty($this->data['div_ccode'])) {
            Localisation::setSystemLanguage($this->data['div_ccode']);
        }

        if (ConfigHelper::checkConfig('invoices.jpk_flags')) {
            $this->invoice_jpk_flags();
        }

        $this->invoice_cancelled();
        $this->invoice_no_accountant();
        $this->invoice_header_image();
        $this->invoice_date();
        $this->invoice_dates();
        $this->invoice_title();
        $this->invoice_seller();
        $this->invoice_buyer();
        $this->invoice_recipient();
        $this->invoice_data();
        $this->invoice_to_pay();
        $this->invoice_expositor();
        if (ConfigHelper::checkConfig('invoices.show_pricing_method', true)) {
            $this->invoice_pricing_method();
        }
        if (ConfigHelper::checkConfig('invoices.show_balance', true)
            || ConfigHelper::checkConfig('invoices.show_expired_balance')) {
            $this->invoice_balance();
        }
        if (ConfigHelper::checkConfig('invoices.qr2pay') && !isset($this->data['rebate'])) {
            $this->invoice_qr2pay_code();
        }
        $this->invoice_comment();
        $this->invoice_footnote();

        if (ConfigHelper::checkConfig('invoices.show_memo', true)) {
            $this->invoice_memo();
        }

        $docnumber = docnumber(array(
            'number' => $this->data['number'],
            'template' => $this->data['template'],
            'cdate' => $this->data['cdate'],
            'customerid' => $this->data['customerid'],
        ));
        if ($this->data['doctype'] == DOC_INVOICE_PRO) {
            $this->backend->SetTitle(trans('Pro Forma Invoice No. $a', $docnumber));
        } else {
            $this->backend->SetTitle(trans('Invoice No. $a', $docnumber));
        }
        $this->backend->SetAuthor($this->data['division_name']);
        $this->backend->setBarcode($docnumber);

        /* setup your cert & key file */
        $cert = 'file://' . LIB_DIR . '/tcpdf/config/lms.cert';
        $key = 'file://' . LIB_DIR . '/tcpdf/config/lms.key';

        /* setup signature additional information */
        $info = array(
            'Name' => $this->data['division_name'],
            'Location' => trans('Invoices'),
            'Reason' => $this->data['doctype'] == DOC_INVOICE_PRO ? trans('Pro Forma Invoice No. $a', $docnumber) : trans('Invoice No. $a', $docnumber),
            'ContactInfo' => $this->data['division_author']
        );

        /* set document digital signature & protection */
        if (file_exists($cert) && file_exists($key)) {
            $this->backend->setSignature($cert, $key, 'lms-invoices', '', 1, $info);
            $this->backend->setSignatureAppearance(13, 10, 50, 20);
        }

        if (!$this->data['disable_protection'] && $this->data['protection_password']) {
            $this->backend->SetProtection(
                array('modify', 'copy', 'annot-forms', 'fill-forms', 'extract', 'assemble'),
                '',
                $this->data['protection_password'],
                1
            );
        }

        if (!empty($this->data['div_ccode'])) {
            Localisation::resetSystemLanguage();
        }
    }

    protected function invoice_transferform($transferform)
    {
        $transferform->backend = $this->backend; // only for invoice
        $payment_barcode = 0;
        $payment_docnumber = docnumber(array(
            'number' => $this->data['number'],
            'template' => $this->data['template'],
            'cdate' => $this->data['cdate'],
            'customerid' => $this->data['customerid'],
        ));

        $customerid = $this->data['customerid'];

        $payment_title = ConfigHelper::getConfig('invoices.payment_title', null, true);
        if (empty($payment_title)) {
            if (ConfigHelper::checkConfig('invoices.customer_balance_in_form')) {
                $payment_title = trans('Payment for liabilities');
            } else {
                $payment_title = trans('Payment for invoice No. $a', $payment_docnumber);
            }
        } else {
            $payment_title = str_replace(
                array(
                    '%number',
                ),
                array(
                    $payment_docnumber,
                ),
                preg_replace_callback(
                    '/%(\\d*)cid/',
                    function ($m) use ($customerid) {
                        return sprintf('%0' . $m[1] . 'd', $customerid);
                    },
                    $payment_title
                )
            );
        }

        if (ConfigHelper::checkConfig('invoices.customer_balance_in_form')) {
            $payment_value = ($this->data['customerbalance'] / $this->data['currencyvalue']) * -1;
            $payment_barcode = trans('Customer ID: $a', $customerid);
        } else {
            $payment_value = $this->data['value'];
            $payment_barcode = $payment_docnumber;
        }

        $tranferform_common_data = $transferform->GetCommonData(array('customerid' => $this->data['customerid'], 'export' => $this->data['export']));

        $account = null;
        if (!ConfigHelper::checkConfig('invoices.show_only_alternative_accounts')
            || empty($this->data['bankaccounts'])) {
            $account = bankaccount($this->data['customerid'], $this->data['account'], $this->data['export']);
        } elseif (ConfigHelper::checkConfig('invoices.show_all_accounts')
            || ConfigHelper::checkConfig('invoices.show_only_alternative_accounts')) {
            $account = reset($this->data['bankaccounts']);
        }

        $tranferform_custom_data = array(
            'title' => $payment_title,
            'value' => $payment_value < 0 ? 0 : $payment_value,
            'export' => $this->data['export'],
            'currency' => $this->data['currency'],
            'paytype' => $this->data['paytype'],
            'pdate' => $this->data['pdate'],
            'account' => $account,
            'barcode' => $payment_barcode,
            'division_shortname' => $this->data['division_shortname'],
            'division_name' => $this->data['division_name'],
            'division_address' => $this->data['division_address'],
            'division_zip' => $this->data['division_zip'],
            'division_city' => $this->data['division_city'],
            'division_countryid' => $this->data['division_countryid'],
            'division_ten' => $this->data['division_ten'],
        );
        $tranferform_data = $transferform->SetCustomData($tranferform_common_data, $tranferform_custom_data);

        $transferform->Draw($tranferform_data, 0, 190);
    }

    public function invoice_body_ft0100()
    {
        global $PAYTYPES;

        if (!empty($this->data['div_ccode'])) {
            Localisation::setSystemLanguage($this->data['div_ccode']);
        }

        if (ConfigHelper::checkConfig('invoices.jpk_flags')) {
            $this->invoice_jpk_flags();
        }

        $this->invoice_cancelled();
        $this->invoice_no_accountant();
        $this->invoice_header_image();
        $this->invoice_date();
        $this->invoice_dates();
        $this->invoice_title();
        $this->invoice_seller();
        $this->invoice_buyer();
        $this->invoice_recipient();
        $this->invoice_data();
        $this->invoice_to_pay();
        $this->invoice_expositor();
        if (ConfigHelper::checkConfig('invoices.show_pricing_method', true)) {
            $this->invoice_pricing_method();
        }
        if (ConfigHelper::checkConfig('invoices.show_balance', true)
            || ConfigHelper::checkConfig('invoices.show_expired_balance')) {
            $this->invoice_balance();
        }
        if (ConfigHelper::checkConfig('invoices.qr2pay') && !isset($this->data['rebate'])) {
            $this->invoice_qr2pay_code();
        }
        $this->invoice_comment();
        $this->invoice_footnote();

        if (ConfigHelper::checkConfig('invoices.show_memo', true)) {
            $this->invoice_memo();
        }

        if ((!isset($this->data['transfer-forms']) || !empty($this->data['transfer-forms']))
            && ($PAYTYPES[$this->data['paytype']]['features'] & INVOICE_FEATURE_TRANSFER_FORM)
            && ($this->data['customerbalance'] < 0 || ConfigHelper::checkConfig('invoices.always_show_form', true))
            && !isset($this->data['rebate'])) {
            /* FT-0100 form */
            $lms = LMS::getInstance();
            if ($lms->checkCustomerConsent($this->data['customerid'], CCONSENT_TRANSFERFORM) || !empty($this->data['transfer-forms'])) {
                if ($this->backend->GetY() > 180 || $this->transfer_form_on_separate_page) {
                    $this->backend->AppendPage();
                }

                $this->invoice_transferform(new LMSTcpdfTransferForm('Transfer form', $pagesize = 'A4', $orientation = 'portrait'));
            }
        }

        $docnumber = docnumber(array(
            'number' => $this->data['number'],
            'template' => $this->data['template'],
            'cdate' => $this->data['cdate'],
            'customerid' => $this->data['customerid'],
        ));
        if ($this->data['doctype'] == DOC_INVOICE_PRO) {
            $this->backend->SetTitle(trans('Pro Forma Invoice No. $a', $docnumber));
        } else {
            $this->backend->SetTitle(trans('Invoice No. $a', $docnumber));
        }
        $this->backend->SetAuthor($this->data['division_name']);

        /* setup your cert & key file */
        $cert = 'file://' . LIB_DIR . '/tcpdf/config/lms.cert';
        $key = 'file://' . LIB_DIR . '/tcpdf/config/lms.key';

        /* setup signature additional information */
        $info = array(
            'Name' => $this->data['division_name'],
            'Location' => trans('Invoices'),
            'Reason' => $this->data['doctype'] == DOC_INVOICE_PRO ? trans('Pro Forma Invoice No. $a', $docnumber) : trans('Invoice No. $a', $docnumber),
            'ContactInfo' => $this->data['division_author']
        );

        /* set document digital signature & protection */
        if (file_exists($cert) && file_exists($key)) {
            $this->backend->setSignature($cert, $key, 'lms-invoices', '', 1, $info);
            $this->backend->setSignatureAppearance(13, 10, 50, 20);
        }

        if (!$this->data['disable_protection'] && $this->data['protection_password']) {
            $this->backend->SetProtection(
                array('modify', 'copy', 'annot-forms', 'fill-forms', 'extract', 'assemble'),
                '',
                $this->data['protection_password'],
                1
            );
        }

        if (!empty($this->data['div_ccode'])) {
            Localisation::resetSystemLanguage();
        }
    }
}
