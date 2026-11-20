<?php
/**
 * CustomerDossierService.php
 * -------------------------------------------------------
 * Builds the customer dossier PDF on demand.
 *
 * Nothing is cached or merged permanently: the file is
 * generated in memory only for the request that asks for it,
 * so replacing a document can never leave a stale dossier.
 * -------------------------------------------------------
 */

class CustomerDossierService
{
    private const IMAGE_MAX_EDGE = 1400;
    private const IMAGE_QUALITY  = 82;

    /** Longest page image box (points) inside the A4 content area. */
    private const DOC_IMAGE_WIDTH  = 515.0;
    private const DOC_IMAGE_HEIGHT = 560.0;

    /**
     * @param int[] $documentIds Selected document ids; empty = all active documents.
     * @return array{bytes:string, filename:string, documents:int, pages:int}
     */
    public function build(int $customerId, array $documentIds = [], ?int $officerId = null): array
    {
        $customer = (new Customer())->findDetailed($customerId);

        if (!$customer) {
            throw new ValidationException([t('Customer not found.')]);
        }

        $documents = (new CustomerDocument())->forCustomer($customerId);

        if ($documentIds) {
            $wanted = array_map('intval', $documentIds);
            $documents = array_values(array_filter(
                $documents,
                static fn(array $doc): bool => in_array((int) $doc['id'], $wanted, true)
            ));
        }

        $portrait = null;

        foreach ($documents as $doc) {
            if ($doc['document_category'] === 'portrait') {
                $portrait = $doc;
                break;
            }
        }

        $pdf = new PdfDocument(t('Customer dossier') . ' · ' . $customer['reference']);
        $pdf->setFooter(Settings::businessName() . ' · ' . date('d/m/Y H:i'));

        $pdf->title(t('Customer dossier'));
        $pdf->subtitle($customer['reference'] . ' · ' . $customer['full_name']);

        $pdf->heading(t('Customer identity'));
        $pdf->row(t('Full name'), (string) $customer['full_name']);
        $pdf->row(t('Reference'), (string) $customer['reference']);
        $pdf->row(t('Gender'), $customer['gender'] ? t(ucfirst((string) $customer['gender'])) : '—');
        $pdf->row(t('NIDA'), $customer['nida'] ?: '—');
        $pdf->row(t('Phone'), $customer['phone'] ? formatPhone($customer['phone']) : '—');
        $pdf->row(t('Alternate phone'), $customer['phone_alt'] ? formatPhone($customer['phone_alt']) : '—');
        $pdf->row(t('Emergency contact'), $customer['emergency_contact'] ?: '—');
        $pdf->row(t('Officer'), (string) ($customer['officer_name'] ?? '—'));
        $pdf->row(t('Registered'), formatDate($customer['registered_at']));

        // Portrait: preferred from the digital file, else the legacy photo.
        $portraitImage = null;

        if ($portrait) {
            $path = DocumentStorage::absolutePath($portrait['storage_key'] ?: $portrait['path']);
            $portraitImage = $path ? $this->jpeg($path) : null;
        }

        if ($portraitImage === null && !empty($customer['photo_path'])) {
            $path = DocumentStorage::absolutePath($customer['photo_path'])
                ?? DocumentStorage::legacyAbsolutePath($customer['photo_path']);
            $portraitImage = $path ? $this->jpeg($path) : null;
        }

        if ($portraitImage !== null) {
            $pdf->heading(t('Customer portrait'));
            $pdf->image($portraitImage['bytes'], $portraitImage['w'], $portraitImage['h'], 150.0, 190.0);
        }

        $pdf->heading(t('Loan summary'));
        $loans = (new Loan())->forCustomer($customerId);

        if (!$loans) {
            $pdf->paragraph(t('No loans issued to this customer.'));
        } else {
            foreach ($loans as $loan) {
                $pdf->row(
                    (string) $loan['loan_reference'],
                    t('Issued :date · payable :amount · paid :paid · balance :balance · :status', [
                        'date'     => formatDate($loan['loan_date']),
                        'amount'   => money($loan['total_amount_payable']),
                        'paid'     => money($loan['amount_paid']),
                        'balance'  => money($loan['remaining_balance']),
                        'status'   => t(ucwords(strtolower((string) $loan['loan_status']))),
                    ]),
                    110.0
                );
            }
        }

        $pdf->heading(t('Documents'));

        if (!$documents) {
            $pdf->paragraph(t('No documents selected.'));
        }

        foreach ($documents as $doc) {
            $meta = t('Version :n', ['n' => (int) $doc['version_number']])
                . ' · ' . t('Uploaded :date', ['date' => formatDate($doc['uploaded_at'])]);

            if (!empty($doc['uploaded_by_name'])) {
                $meta .= ' · ' . $doc['uploaded_by_name'];
            }

            if (!empty($doc['issue_date'])) {
                $meta .= ' · ' . t('Issued :date', ['date' => formatDate($doc['issue_date'])]);
            }

            if (!empty($doc['expiry_date'])) {
                $meta .= ' · ' . t('Expires :date', ['date' => formatDate($doc['expiry_date'])]);
            }

            // The portrait is already shown above the loan summary.
            if ($portrait && (int) $portrait['id'] === (int) $doc['id']) {
                $pdf->keepTogether(80);
                $pdf->heading(CustomerDocument::label((string) $doc['document_category']), 10.5);
                $pdf->paragraph($meta, 8.5, 11.5);
                continue;
            }

            $path = DocumentStorage::absolutePath($doc['storage_key'] ?: $doc['path'])
                ?? DocumentStorage::legacyAbsolutePath($doc['path']);
            $image = $path !== null ? $this->jpeg($path) : null;

            $drawH = $image !== null
                ? min(
                    self::DOC_IMAGE_HEIGHT,
                    $image['h'] * min(1.0, self::DOC_IMAGE_WIDTH / max(1, $image['w']))
                )
                : 0.0;

            // Keep the heading, metadata and a full image on the same page.
            $pdf->keepTogether($drawH + 90);
            $pdf->heading(CustomerDocument::label((string) $doc['document_category']), 10.5);
            $pdf->paragraph($meta, 8.5, 11.5);

            if ($path === null) {
                $pdf->paragraph(t('The document image is not available in private storage.'), 9.0);
                continue;
            }

            if ($image === null) {
                $pdf->paragraph(t('The document image could not be read.'), 9.0);
                continue;
            }

            $pdf->image($image['bytes'], $image['w'], $image['h'], self::DOC_IMAGE_WIDTH, self::DOC_IMAGE_HEIGHT);
        }

        $pdf->heading(t('Verification note'));
        $pdf->paragraph(
            t('This dossier is a compilation of images supplied by the customer and stored by the loan officer. It is not a certificate of identity, and OCR or check digits must not be treated as proof of authenticity. Verify original documents before approval.'),
            8.5,
            11.5
        );

        return [
            'bytes'     => $pdf->toBytes(),
            'filename'  => $customer['reference'] . '-dossier-' . date('Ymd-His') . '.pdf',
            'documents' => count($documents),
            'pages'     => $pdf->getPageCount(),
        ];
    }

    /**
     * Re-encode any supported image as a JPEG payload for the PDF.
     *
     * @return array{bytes:string,w:int,h:int}|null
     */
    private function jpeg(string $absolute): ?array
    {
        $source = @imagecreatefromstring((string) @file_get_contents($absolute));

        if ($source === false) {
            return null;
        }

        $width  = imagesx($source);
        $height = imagesy($source);
        $scale  = min(1.0, self::IMAGE_MAX_EDGE / max(1, max($width, $height)));

        $targetW = max(1, (int) round($width * $scale));
        $targetH = max(1, (int) round($height * $scale));

        $canvas = $source;

        if ($targetW !== $width || $targetH !== $height) {
            $canvas = imagecreatetruecolor($targetW, $targetH);
            $white  = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
        }

        ob_start();
        imagejpeg($canvas, null, self::IMAGE_QUALITY);
        $bytes = (string) ob_get_clean();

        if ($canvas !== $source) {
            imagedestroy($canvas);
        }

        imagedestroy($source);

        if ($bytes === '') {
            return null;
        }

        return ['bytes' => $bytes, 'w' => $targetW, 'h' => $targetH];
    }
}
