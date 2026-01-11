<?php
declare(strict_types=1);

namespace App;

final class XlsxExport
{
    public static function requireSpreadsheet(): void
    {
        $root = dirname(__DIR__);
        $autoload = $root . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            Http::text(500, "Excel çıktısı için Composer kurulumu gerekli.\n\nKomut:\n  composer install\n\nNot: CyberPanel sunucunuzda composer yoksa kurmanız gerekir.\n");
            exit;
        }
        require_once $autoload;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public static function downloadFromTemplate(string $templateAbsPath, string $downloadName, array $reportRows, array $distRows): void
    {
        self::requireSpreadsheet();

        if (!is_file($templateAbsPath)) {
            Http::text(500, "Şablon dosya bulunamadı: {$templateAbsPath}\n");
            exit;
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($templateAbsPath);

        // report sheet
        $report = $spreadsheet->getSheetByName('report');
        if ($report === null) {
            Http::text(500, "Şablonda report sayfası yok.\n");
            exit;
        }
        self::fillSheet($report, $reportRows);

        // distribution sheet
        $dist = $spreadsheet->getSheetByName('distribution');
        if ($dist === null) {
            Http::text(500, "Şablonda distribution sayfası yok.\n");
            exit;
        }
        self::fillSheet($dist, $distRows);

        // output
        http_response_code(200);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /**
     * Başlık satırı 1 varsayılır. Veriler 2. satırdan itibaren yazılır.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param array<int,array<string,mixed>> $rows
     */
    private static function fillSheet($sheet, array $rows): void
    {
        // headers: row1 until empty
        $headers = [];
        $col = 1;
        while (true) {
            $v = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($v === null || $v === '') {
                break;
            }
            $headers[] = (string)$v;
            $col++;
            if ($col > 100) {
                break;
            }
        }

        // clear old content (from row2 down)
        $maxRow = max(2, $sheet->getHighestRow());
        if ($maxRow > 1) {
            $sheet->removeRow(2, $maxRow - 1);
        }

        // write rows
        $r = 2;
        foreach ($rows as $row) {
            $c = 1;
            foreach ($headers as $h) {
                $val = $row[$h] ?? null;
                $sheet->setCellValueByColumnAndRow($c, $r, $val);
                $c++;
            }
            $r++;
        }
    }
}

