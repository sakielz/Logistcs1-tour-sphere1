<?php

if (!function_exists('exportTrackedReport')) {
    function exportTrackedReport(PDO $pdo, int $userId, string $module, string $title, string $format, array $rows): void
    {
        if (!in_array($format, ['pdf', 'excel'], true)) {
            http_response_code(400);
            exit('Unsupported report format. Choose PDF or Excel.');
        }

        $headers = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $headers = array_keys($row);
                break;
            }
        }

        $filenameDate = date('Y-m-d_His');
        $documentNumber = 'RPT-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $extension = $format === 'pdf' ? 'pdf' : 'xls';
        $filename = strtolower($documentNumber) . '.' . $extension;
        $relativePath = '/uploads/documents/reports/' . $filename;
        $outputDirectory = dirname(__DIR__) . '/uploads/documents/reports';

        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0755, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException('Could not create the report archive directory.');
        }

        if ($format === 'excel') {
            $escape = static function ($value): string {
                return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            };
            $xml = '<?xml version="1.0" encoding="UTF-8"?>';
            $xml .= '<?mso-application progid="Excel.Sheet"?>';
            $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
            $xml .= '<Worksheet ss:Name="Report"><Table>';
            $xml .= '<Row><Cell><Data ss:Type="String">' . $escape($title) . '</Data></Cell></Row>';
            $xml .= '<Row><Cell><Data ss:Type="String">Generated ' . $escape(date('Y-m-d H:i:s')) . '</Data></Cell></Row>';
            if ($headers) {
                $xml .= '<Row>';
                foreach ($headers as $header) {
                    $xml .= '<Cell><Data ss:Type="String">' . $escape(ucwords(str_replace('_', ' ', (string)$header))) . '</Data></Cell>';
                }
                $xml .= '</Row>';
                foreach ($rows as $row) {
                    $xml .= '<Row>';
                    foreach ($headers as $header) {
                        $xml .= '<Cell><Data ss:Type="String">' . $escape($row[$header] ?? '') . '</Data></Cell>';
                    }
                    $xml .= '</Row>';
                }
            }
            $xml .= '</Table></Worksheet></Workbook>';
            $fileContents = $xml;
            $contentType = 'application/vnd.ms-excel; charset=UTF-8';
        } else {
            $toAscii = static function ($value): string {
                $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$value);
                return $value === false ? '' : preg_replace('/[^\x20-\x7E]/', ' ', $value);
            };
            $escapePdf = static function ($value) use ($toAscii): string {
                return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $toAscii($value));
            };

            $lines = [$title, 'Module: ' . $module, 'Generated: ' . date('Y-m-d H:i:s'), 'Rows: ' . count($rows), ''];
            if ($headers) {
                $lines[] = implode(' | ', array_map(static function ($header) {
                    return ucwords(str_replace('_', ' ', (string)$header));
                }, $headers));
                $lines[] = str_repeat('-', 100);
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($headers as $header) {
                        $values[] = (string)($row[$header] ?? '');
                    }
                    $lines[] = implode(' | ', $values);
                }
            }

            $wrappedLines = [];
            foreach ($lines as $line) {
                foreach (explode("\n", wordwrap($toAscii($line), 105, "\n", true)) as $wrappedLine) {
                    $wrappedLines[] = $wrappedLine;
                }
            }
            $pages = array_chunk($wrappedLines, 46);
            if (!$pages) $pages = [[]];

            $objects = [
                1 => '<< /Type /Catalog /Pages 2 0 R >>',
                2 => ''
            ];
            $fontId = 3 + (2 * count($pages));
            $pageReferences = [];
            foreach ($pages as $pageIndex => $pageLines) {
                $pageId = 3 + (2 * $pageIndex);
                $streamId = $pageId + 1;
                $pageReferences[] = $pageId . ' 0 R';
                $content = "BT\n/F1 8 Tf\n40 760 Td\n";
                foreach ($pageLines as $lineIndex => $line) {
                    if ($lineIndex > 0) $content .= "0 -15 Td\n";
                    $content .= '(' . $escapePdf($line) . ") Tj\n";
                }
                $content .= 'ET';
                $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 ' . $fontId . ' 0 R >> >> /Contents ' . $streamId . ' 0 R >>';
                $objects[$streamId] = '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . "\nendstream";
            }
            $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageReferences) . '] /Count ' . count($pages) . ' >>';
            $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';

            ksort($objects);
            $fileContents = "%PDF-1.4\n";
            $offsets = [0];
            foreach ($objects as $objectId => $object) {
                $offsets[$objectId] = strlen($fileContents);
                $fileContents .= $objectId . " 0 obj\n" . $object . "\nendobj\n";
            }
            $xrefOffset = strlen($fileContents);
            $objectCount = count($objects) + 1;
            $fileContents .= "xref\n0 " . $objectCount . "\n0000000000 65535 f \n";
            for ($objectId = 1; $objectId < $objectCount; $objectId++) {
                $fileContents .= sprintf('%010d 00000 n ', $offsets[$objectId]) . "\n";
            }
            $fileContents .= 'trailer' . "\n<< /Size " . $objectCount . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";
            $contentType = 'application/pdf';
        }

        $absolutePath = $outputDirectory . '/' . $filename;
        if (file_put_contents($absolutePath, $fileContents) === false) {
            throw new RuntimeException('Could not save the generated report.');
        }

        try {
            $description = 'Generated ' . strtoupper($format) . ' report with ' . count($rows) . ' data rows.';
            $stmt = $pdo->prepare("INSERT INTO documents (document_number, document_type, title, description, related_module, related_id, status, file_path, created_by) VALUES (?, 'report', ?, ?, ?, NULL, 'approved', ?, ?)");
            $stmt->execute([$documentNumber, $title, $description, $module, $relativePath, $userId]);
            if (function_exists('logAudit')) {
                logAudit($userId, 'generate_report_document', 'reports', 'Generated ' . $documentNumber . ' for ' . $module);
            }
        } catch (Throwable $error) {
            @unlink($absolutePath);
            throw $error;
        }

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($fileContents));
        header('Cache-Control: private, no-store');
        echo $fileContents;
        exit();
    }
}