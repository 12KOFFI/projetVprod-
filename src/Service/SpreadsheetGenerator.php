<?php

namespace App\Service;

use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpreadsheetGenerator
{
    public function generate($name, $headers, $datas)
    {
        $response = new StreamedResponse();
        $response->setCallback(function () use ($headers, $datas) {
            $spreadsheet = new Spreadsheet();
            $hc = count($headers) - 1;
            $dc = count($datas) + 1;
            $sheet = $spreadsheet->getActiveSheet();
            $indexes = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB', 'AC'];
            $sheet->getStyle('A1' . ':' . $indexes[$hc] . 1)->getFont()->setBold(true);
            $sheet->getStyle('A1' . ':' . $indexes[$hc] . $dc)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            foreach ($headers as $key => $cel) {
                $sheet->setCellValue($indexes[$key] . '1', $cel);
                $sheet->getColumnDimension($indexes[$key])->setAutoSize(true);
            }
            foreach ($datas as $index => $col) foreach ($col as $key => $cel) $sheet->setCellValue($indexes[$key] . ($index + 2), $cel);
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            exit();
        });
        $response->setStatusCode(Response::HTTP_OK);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $name . '"');
        $response->send();
        return;
    }
    
    public function generatesheets($name, $headers, $results)
    {
        $response = new StreamedResponse();
        $response->setCallback(function () use ($headers, $results) {
            $i=0;
            $j=1;
            $spreadsheet = new Spreadsheet();
            $indexes = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];
            foreach($results as $sht) {
                $spreadsheet->createSheet();
                $spreadsheet->setActiveSheetIndex($i);
                $spreadsheet->getActiveSheet()->setTitle($sht[0]);
                $sheet = $spreadsheet->getActiveSheet();
                $datas = $sht[1];
                $hc = count($headers) - 1;
                $dc = count($datas) + 1;
                $sheet->getStyle('A1' . ':' . $indexes[$hc] . 1)->getFont()->setBold(true);
                $sheet->getStyle('A1' . ':' . $indexes[$hc] . $dc)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                foreach ($headers as $key => $cel) {
                    $sheet->setCellValue($indexes[$key] . '1', $cel);
                    $sheet->getColumnDimension($indexes[$key])->setAutoSize(true);
                }
                foreach ($datas as $index => $col) foreach ($col as $key => $cel) $sheet->setCellValue($indexes[$key] . ($index + 2), $cel);
                $i++;
                $j++;
            }
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            exit();
        });
        $response->setStatusCode(Response::HTTP_OK);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $name . '"');
        $response->send();
        return;
    }
}
