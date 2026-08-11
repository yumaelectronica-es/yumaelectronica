<?php
/**
 * Yuma Electrónica — proforma invoice PDF generator (FPDF, no Composer).
 */
require __DIR__ . '/fpdf/fpdf.php';

function ye_enc($text) {
    return mb_convert_encoding((string) $text, 'Windows-1252', 'UTF-8');
}

class YumaInvoicePdf extends FPDF {
    public $orderNumber = '';

    function Header() {
        $this->SetFont('Helvetica', 'B', 20);
        $this->SetTextColor(57, 181, 74);
        $this->Cell(0, 10, ye_enc('Yuma Electrónica'), 0, 1, 'L');
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(90, 90, 90);
        $this->Cell(0, 4.5, ye_enc('Yuma Electrónica S.L. — CIF B30290647'), 0, 1, 'L');
        $this->Cell(0, 4.5, ye_enc('C. Libertador Simón Bolívar 2, Sur, 14013, Córdoba, España'), 0, 1, 'L');
        $this->Cell(0, 4.5, ye_enc('soporte@yumaelectronica.es · +34 639 42 59 32 · www.yumaelectronica.es'), 0, 1, 'L');
        $this->SetDrawColor(230, 230, 230);
        $this->Line(10, 32, 200, 32);
        $this->Ln(6);
    }

    function Footer() {
        $this->SetY(-20);
        $this->SetDrawColor(230, 230, 230);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(130, 130, 130);
        $this->MultiCell(0, 3.8, ye_enc(
            'Este documento es una factura proforma emitida a efectos informativos y de confirmación del pedido. ' .
            'No tiene validez como factura fiscal. La factura definitiva se emitirá tras la confirmación del pago.'
        ), 0, 'C');
        $this->SetFont('Helvetica', '', 7.5);
        $this->Cell(0, 4, ye_enc('Página ' . $this->PageNo() . ' de {nb} — Pedido ' . $this->orderNumber), 0, 0, 'C');
    }
}

function money2($n) {
    return number_format((float) $n, 2, ',', '.') . ' EUR';
}

function taxLabel($region) {
    if ($region === 'canarias') return 'IGIC (7%)';
    if ($region === 'ceuta_melilla') return 'IPSI (no incluido)';
    return 'IVA (21%)';
}

/**
 * Builds the proforma invoice and returns the raw PDF content as a string.
 */
function buildProformaInvoicePdf($order) {
    $pdf = new YumaInvoicePdf();
    $pdf->orderNumber = $order['orderNumber'] ?? '';
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetTextColor(20, 20, 20);

    // Title + invoice meta
    $pdf->SetFont('Helvetica', 'B', 15);
    $pdf->Cell(0, 8, ye_enc('FACTURA PROFORMA'), 0, 1, 'L');
    $pdf->SetFont('Helvetica', '', 9);
    $date = !empty($order['date']) ? date('d/m/Y', strtotime($order['date'])) : date('d/m/Y');
    $pdf->Cell(0, 5, ye_enc('Nº de pedido: ' . ($order['orderNumber'] ?? '')), 0, 1, 'L');
    $pdf->Cell(0, 5, ye_enc('Fecha: ' . $date), 0, 1, 'L');
    $pdf->Ln(4);

    // Client block
    $pdf->SetFillColor(248, 249, 250);
    $pdf->Rect(10, $pdf->GetY(), 190, 30, 'F');
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetX(14);
    $pdf->Cell(0, 6, ye_enc('Datos del cliente'), 0, 1, 'L');
    $pdf->SetFont('Helvetica', '', 9);
    $clientName = !empty($order['isCompany']) ? ($order['companyName'] ?? '') : ($order['contactName'] ?? $order['shippingName'] ?? '');
    $pdf->SetX(14);
    $pdf->Cell(0, 5, ye_enc($clientName), 0, 1, 'L');
    if (!empty($order['isCompany']) && !empty($order['companyTaxId'])) {
        $pdf->SetX(14);
        $pdf->Cell(0, 5, ye_enc('CIF/NIF: ' . $order['companyTaxId']), 0, 1, 'L');
    }
    $billAddr = ($order['billingAddress'] ?? $order['shippingAddress'] ?? '') . ', ' .
        ($order['billingPostalCode'] ?? $order['postalCode'] ?? '') . ' ' .
        ($order['billingCity'] ?? $order['city'] ?? '') . ', ' .
        ($order['billingProvince'] ?? $order['province'] ?? '');
    $pdf->SetX(14);
    $pdf->Cell(0, 5, ye_enc($billAddr), 0, 1, 'L');
    $pdf->SetX(14);
    $pdf->Cell(0, 5, ye_enc(($order['email'] ?? '') . ($order['phone'] ? ' · ' . $order['phone'] : '')), 0, 1, 'L');
    $pdf->Ln(6);

    // Items table header
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetFillColor(57, 181, 74);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(95, 8, ye_enc('  Descripción'), 0, 0, 'L', true);
    $pdf->Cell(20, 8, ye_enc('Cant.'), 0, 0, 'C', true);
    $pdf->Cell(35, 8, ye_enc('Precio unit.'), 0, 0, 'R', true);
    $pdf->Cell(40, 8, ye_enc('Subtotal  '), 0, 1, 'R', true);
    $pdf->SetTextColor(20, 20, 20);

    $pdf->SetFont('Helvetica', '', 8.5);
    $fill = false;
    foreach (($order['items'] ?? []) as $item) {
        $qty = (int) ($item['qty'] ?? 1);
        $unit = (float) ($item['unitPrice'] ?? 0);
        $pdf->SetFillColor(248, 249, 250);
        $y0 = $pdf->GetY();
        $pdf->MultiCell(95, 5.5, ye_enc('  ' . ($item['name'] ?? '')), 0, 'L', $fill);
        $y1 = $pdf->GetY();
        $rowH = max(5.5, $y1 - $y0);
        $pdf->SetXY(105, $y0);
        $pdf->Cell(20, $rowH, (string) $qty, 0, 0, 'C', $fill);
        $pdf->Cell(35, $rowH, ye_enc(money2($unit)), 0, 0, 'R', $fill);
        $pdf->Cell(40, $rowH, ye_enc(money2($unit * $qty) . '  '), 0, 1, 'R', $fill);
        $fill = !$fill;
    }
    $pdf->Ln(4);

    // Totals block (right-aligned)
    $rows = [];
    if (!empty($order['warrantySubtotal'])) $rows[] = ['Garantía ampliada', $order['warrantySubtotal']];
    if (!empty($order['removalSubtotal'])) $rows[] = ['Retirada de equipo antiguo', $order['removalSubtotal']];
    if (!empty($order['installationSubtotal'])) $rows[] = ['Instalación', $order['installationSubtotal']];
    if (!empty($order['couponDiscountAmount'])) $rows[] = ['Descuento (' . ($order['couponCode'] ?? '') . ')', -$order['couponDiscountAmount']];
    $rows[] = [($order['shippingCost'] ?? 0) > 0 ? 'Envío' : 'Envío (gratis)', $order['shippingCost'] ?? 0];

    $pdf->SetFont('Helvetica', '', 9);
    foreach ($rows as $r) {
        $pdf->Cell(150, 5.5, '', 0, 0);
        $pdf->Cell(20, 5.5, ye_enc($r[0]), 0, 0, 'L');
        $pdf->Cell(20, 5.5, ye_enc(money2($r[1])), 0, 1, 'R');
    }

    $base = isset($order['baseExTax']) ? (float) $order['baseExTax'] : 0;
    $total = (float) ($order['total'] ?? 0);
    $taxAmount = $total - $base;

    $pdf->Cell(150, 5.5, '', 0, 0);
    $pdf->Cell(20, 5.5, ye_enc('Base imponible'), 0, 0, 'L');
    $pdf->Cell(20, 5.5, ye_enc(money2($base)), 0, 1, 'R');
    $pdf->Cell(150, 5.5, '', 0, 0);
    $pdf->Cell(20, 5.5, ye_enc(taxLabel($order['taxRegion'] ?? 'peninsula')), 0, 0, 'L');
    $pdf->Cell(20, 5.5, ye_enc(money2($taxAmount)), 0, 1, 'R');

    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetFillColor(240, 250, 242);
    $pdf->Cell(150, 8, '', 0, 0);
    $pdf->Cell(20, 8, ye_enc('TOTAL'), 0, 0, 'L', true);
    $pdf->Cell(20, 8, ye_enc(money2($total)), 0, 1, 'R', true);
    $pdf->Ln(6);

    // Payment info
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(0, 6, ye_enc('Forma de pago'), 0, 1, 'L');
    $pdf->SetFont('Helvetica', '', 9);
    $pd = $order['paymentDetails'] ?? [];
    if (($order['payment'] ?? '') === 'bizum') {
        $pdf->Cell(0, 5, ye_enc('Bizum al número: ' . ($pd['bizumNumber'] ?? '')), 0, 1, 'L');
        $pdf->Cell(0, 5, ye_enc('Titular: ' . ($pd['bizumBeneficiary'] ?? '')), 0, 1, 'L');
    } else {
        $pdf->Cell(0, 5, ye_enc('Transferencia bancaria a:'), 0, 1, 'L');
        $pdf->Cell(0, 5, ye_enc('IBAN: ' . ($pd['iban'] ?? '')), 0, 1, 'L');
        $pdf->Cell(0, 5, ye_enc('BIC/SWIFT: ' . ($pd['bic'] ?? '') . '   Banco: ' . ($pd['bankName'] ?? '')), 0, 1, 'L');
        $pdf->Cell(0, 5, ye_enc('Titular: ' . ($pd['beneficiary'] ?? ''))  , 0, 1, 'L');
    }
    $pdf->Cell(0, 5, ye_enc('Concepto de la transferencia: ' . ($order['orderNumber'] ?? '')), 0, 1, 'L');

    return $pdf->Output('S');
}
