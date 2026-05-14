<?php

declare(strict_types=1);

namespace App\IO;

/**
 * Placeholder for future Excel (.xlsx) import/export.
 *
 * Planned approach: require phpoffice/phpspreadsheet (Composer), stream-read rows in a job
 * for large files, and map sheets to the same normalized case shape as
 * {@see \App\Services\CaseExchangeService::parseImportCsv} / export columns.
 *
 * Until then, API returns HTTP 501 for format=xlsx on import and export.
 */
final class XlsxImportExportStub
{
    public const MESSAGE = 'XLSX import/export is not implemented yet. Use CSV or JSON.';

    public const HINT = 'Future: add phpoffice/phpspreadsheet and implement row mapping in this namespace.';
}
