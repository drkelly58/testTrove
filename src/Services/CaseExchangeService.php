<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class CaseExchangeService
{
    private const PRIORITIES = ['low', 'medium', 'high', 'critical'];
    private const STATUSES = ['draft', 'ready', 'deprecated'];

    /**
     * @return list<string>
     */
    public static function allowedCaseStatuses(): array
    {
        return self::STATUSES;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function validateCaseStatus(string $raw): string
    {
        $s = strtolower(trim($raw));
        if ($s === '') {
            throw new \InvalidArgumentException('status is required');
        }
        if (!in_array($s, self::STATUSES, true)) {
            throw new \InvalidArgumentException('status must be one of: draft, ready, deprecated');
        }

        return $s;
    }

    /**
     * Normalize steps (and nested variants) for API create/update/import.
     *
     * @return list<array{action: string, expected: string, variants?: list<array{label?: string, criteria: string}>}>
     */
    public static function normalizeStepsList(mixed $raw, string $contextLabel): array
    {
        return self::normalizeSteps($raw, $contextLabel);
    }

    /**
     * @param list<array<string, mixed>> $cases rows like list output (with steps as list)
     */
    public static function exportJsonString(int $suiteId, array $cases): string
    {
        $out = [
            'version' => 1,
            'suite_id' => $suiteId,
            'exported_at' => gmdate('c'),
            'cases' => array_map(self::stripCaseRowForExport(...), $cases),
        ];
        return json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * @param list<array<string, mixed>> $cases
     */
    public static function exportCsvString(array $cases): string
    {
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open memory stream');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['section_name', 'section_precondition', 'title', 'precondition', 'priority', 'status', 'steps'], ',', '"', '\\');
        foreach ($cases as $row) {
            $clean = self::stripCaseRowForExport($row);
            $stepsText = self::stepsToText(is_array($clean['steps'] ?? null) ? $clean['steps'] : []);
            fputcsv($handle, [
                $clean['section_name'] ?? 'Default',
                $clean['section_precondition'] ?? '',
                $clean['title'] ?? '',
                $clean['precondition'] ?? '',
                $clean['priority'] ?? 'medium',
                $clean['status'] ?? 'draft',
                $stepsText,
            ], ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);
        return $csv;
    }

    /** Canonical workspace CSV headers (project + suite + case per row). */
    public const WORKSPACE_CSV_HEADERS = [
        'project_name',
        'project_description',
        'suite_name',
        'section_name',
        'section_precondition',
        'title',
        'precondition',
        'priority',
        'status',
        'steps',
    ];

    /** Canonical CSV column names for optional import `options.column_map`. */
    public const CSV_CANONICAL_FIELDS = [
        'project_name',
        'project_description',
        'suite_name',
        'section_name',
        'section_precondition',
        'title',
        'precondition',
        'priority',
        'status',
        'steps',
        'expected',
    ];

    /**
     * One row per test case; repeats project/suite columns. Suitable for multi-suite / whole-project export.
     *
     * @param list<array{suite_name: string, sections?: list<mixed>, cases?: list<mixed>}> $blocks
     */
    public static function exportProjectWorkspaceCsvString(string $projectName, ?string $projectDescription, array $blocks): string
    {
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open memory stream');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::WORKSPACE_CSV_HEADERS, ',', '"', '\\');
        $pDesc = $projectDescription ?? '';
        foreach ($blocks as $block) {
            $sName = (string) ($block['suite_name'] ?? '');
            $sections = self::sectionsFromWorkspaceBlock($block);
            $cases = [];
            foreach ($sections as $section) {
                foreach ($section['cases'] as $case) {
                    $case['section_name'] = $section['name'];
                    $case['section_precondition'] = $section['precondition'];
                    $cases[] = $case;
                }
            }
            foreach ($cases as $row) {
                $clean = self::stripCaseRowForExport(is_array($row) ? $row : []);
                $stepsText = self::stepsToText(is_array($clean['steps'] ?? null) ? $clean['steps'] : []);
                fputcsv($handle, [
                    $projectName,
                    $pDesc,
                    $sName,
                    $clean['section_name'] ?? 'Default',
                    $clean['section_precondition'] ?? '',
                    $clean['title'] ?? '',
                    $clean['precondition'] ?? '',
                    $clean['priority'] ?? 'medium',
                    $clean['status'] ?? 'draft',
                    $stepsText,
                ], ',', '"', '\\');
            }
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    /**
     * @param array<string, mixed> $block
     * @return list<array{name: string, precondition: ?string, sort_order: int, cases: list<array<string, mixed>>}>
     */
    private static function sectionsFromWorkspaceBlock(array $block): array
    {
        if (isset($block['sections']) && is_array($block['sections'])) {
            $out = [];
            foreach ($block['sections'] as $i => $sectionRaw) {
                if (!is_array($sectionRaw)) {
                    continue;
                }
                $name = trim((string) ($sectionRaw['name'] ?? ''));
                if ($name === '') {
                    $name = 'Default';
                }
                $pre = $sectionRaw['precondition'] ?? null;
                $cases = is_array($sectionRaw['cases'] ?? null) ? $sectionRaw['cases'] : [];
                $out[] = [
                    'name' => $name,
                    'precondition' => $pre === null || trim((string) $pre) === '' ? null : (string) $pre,
                    'sort_order' => isset($sectionRaw['sort_order']) ? (int) $sectionRaw['sort_order'] : $i,
                    'cases' => $cases,
                ];
            }

            return $out;
        }

        $cases = is_array($block['cases'] ?? null) ? $block['cases'] : [];
        return [[
            'name' => 'Default',
            'precondition' => null,
            'sort_order' => 0,
            'cases' => $cases,
        ]];
    }

    /**
     * Read CSV: header row + data rows (trimmed cells).
     *
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public static function readCsvRaw(string $csv, ?int $maxRows = null): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open memory stream');
        }
        fwrite($handle, $csv);
        rewind($handle);
        $headerRow = fgetcsv($handle, 0, ',', '"', '\\');
        if ($headerRow === false || $headerRow === [null] || $headerRow === []) {
            throw new \InvalidArgumentException('CSV is empty');
        }
        $headers = array_map(static fn ($h) => trim((string) ($h ?? '')), $headerRow);
        $rows = [];
        $n = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($row === [null] || self::csvRowIsBlank($row)) {
                continue;
            }
            $rows[] = array_map(static fn ($c) => trim((string) ($c ?? '')), $row);
            ++$n;
            if ($maxRows !== null && $n >= $maxRows) {
                break;
            }
        }
        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Map canonical import fields to source header labels (exact match to a header cell, case-sensitive after trim).
     * Keys: project_name, project_description, suite_name, title, precondition, priority, status, steps.
     *
     * @param list<string> $headers
     * @param array<string, string> $userMap canonical => source header from file
     * @return array<string, int> canonical => column index
     */
    public static function resolveCsvColumnIndices(array $headers, array $userMap = []): array
    {
        $headerToIndex = [];
        foreach ($headers as $i => $h) {
            if ($h !== '') {
                $headerToIndex[$h] = $i;
            }
        }

        $synonyms = [
            'project_name' => ['project_name', 'project', 'projekt'],
            'project_description' => ['project_description', 'project_desc'],
            'suite_name' => ['suite_name', 'suite', 'test_suite', 'testsuite'],
            'section_name' => ['section_name', 'section', 'unit'],
            'section_precondition' => ['section_precondition', 'section_precond'],
            'title' => ['title', 'case_title', 'test_case', 'testcase', 'name', 'summary'],
            'precondition' => ['precondition', 'preconditions', 'given'],
            'priority' => ['priority', 'prio'],
            'status' => ['status', 'state'],
            'steps' => ['steps', 'steps_json', 'step_json'],
            'expected' => ['expected', 'expected_result', 'expected_results', 'result', 'results', 'outcome'],
        ];

        $out = [];
        foreach ($synonyms as $canonical => $aliases) {
            if (isset($userMap[$canonical]) && trim($userMap[$canonical]) !== '') {
                $want = trim($userMap[$canonical]);
                if (isset($headerToIndex[$want])) {
                    $out[$canonical] = $headerToIndex[$want];
                    continue;
                }
                foreach ($headers as $i => $h) {
                    if (strcasecmp($h, $want) === 0) {
                        $out[$canonical] = $i;
                        continue 2;
                    }
                }
                throw new \InvalidArgumentException('CSV column map: header "' . $want . '" not found for field "' . $canonical . '"');
            }
            foreach ($aliases as $alias) {
                $al = strtolower($alias);
                foreach ($headers as $i => $h) {
                    if (strtolower($h) === $al) {
                        $out[$canonical] = $i;
                        continue 3;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Suggest column_map (canonical => exact header string from file) from headers alone.
     *
     * @param list<string> $headers
     * @return array<string, string>
     */
    public static function suggestCsvColumnMap(array $headers): array
    {
        $idx = self::resolveCsvColumnIndices($headers, []);
        $suggest = [];
        foreach ($idx as $canonical => $i) {
            $suggest[$canonical] = $headers[$i] ?? '';
        }

        return $suggest;
    }

    /**
     * @param array<string, int> $indices canonical => column index
     * @return 'project'|'flat'
     */
    public static function detectCsvImportMode(array $indices, string $csvMode, int $forcedProjectId = 0): string
    {
        $m = strtolower(trim($csvMode));
        if ($m === 'flat') {
            return 'flat';
        }
        if ($m === 'project') {
            return 'project';
        }
        if ($forcedProjectId > 0 && isset($indices['suite_name'], $indices['title'])) {
            return 'project';
        }
        $hasProject = isset($indices['project_name']);
        $hasSuite = isset($indices['suite_name']);
        if ($hasProject && $hasSuite && isset($indices['title'])) {
            return 'project';
        }

        return 'flat';
    }

    /**
     * @param array<mixed, mixed> $raw
     * @return array<string, string>
     */
    public static function sanitizeColumnMapInput(array $raw): array
    {
        $out = [];
        foreach (self::CSV_CANONICAL_FIELDS as $k) {
            if (!array_key_exists($k, $raw)) {
                continue;
            }
            $v = $raw[$k];
            if ($v === null) {
                continue;
            }
            $s = trim((string) $v);
            if ($s !== '') {
                $out[$k] = $s;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $columnMap canonical => source header label
     * @return array{kind: 'workspace_v2', suites: list<array<string, mixed>>}|array{kind: 'cases', cases: list<array<string, mixed>>}
     */
    public static function classifyCsvImport(
        string $csv,
        array $columnMap,
        string $csvMode,
        int $forcedProjectId,
        ?string $forcedProjectName,
        ?string $forcedProjectDescription
    ): array {
        $columnMap = self::sanitizeColumnMapInput($columnMap);
        $parsed = self::readCsvRaw($csv);
        $headers = $parsed['headers'];
        $rows = $parsed['rows'];
        $indices = self::resolveCsvColumnIndices($headers, $columnMap);
        $mode = self::detectCsvImportMode($indices, $csvMode, $forcedProjectId);

        if ($mode === 'project') {
            if (!isset($indices['suite_name'], $indices['title'])) {
                throw new \InvalidArgumentException(
                    'Project-style CSV import requires suite_name and title columns (set options.csv_mode=flat for a single-suite file, or map those fields in options.column_map).'
                );
            }
            if ($forcedProjectId <= 0 && !isset($indices['project_name'])) {
                throw new \InvalidArgumentException(
                    'CSV has no project column: set options.target_project_id to import into an existing project, or add/map project_name.'
                );
            }
            if ($csvMode === 'project' && !isset($indices['suite_name'])) {
                throw new \InvalidArgumentException('options.csv_mode=project requires a mapped suite_name column.');
            }
            $assoc = self::csvRowsToAssoc($rows, $indices);
            $fp = ($forcedProjectName !== null && $forcedProjectName !== '') ? $forcedProjectName : null;
            $blocks = self::groupCsvRowsToWorkspaceBlocks($assoc, $fp, $forcedProjectDescription);

            return ['kind' => 'workspace_v2', 'suites' => $blocks];
        }

        $cases = self::parseFlatCsvWithIndices($headers, $rows, $indices);

        return ['kind' => 'cases', 'cases' => $cases];
    }

    /**
     * @param list<list<string>> $dataRows full file rows (same width as logical columns allow)
     * @param array<string, int> $indices
     * @return list<array<string, string>> each row keyed by canonical field (present keys only)
     */
    public static function csvRowsToAssoc(array $dataRows, array $indices): array
    {
        $out = [];
        $rowNum = 1;
        foreach ($dataRows as $row) {
            ++$rowNum;
            if (self::csvRowIsBlank($row)) {
                continue;
            }
            $item = [];
            foreach ($indices as $canonical => $idx) {
                $item[$canonical] = $row[$idx] ?? '';
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param list<array<string, string>> $assocRows
     * @return list<array{project_name: string, project_description: ?string, suite_name: string, sections: list<array<string, mixed>>, cases: list<array<string, mixed>>}>
     */
    public static function groupCsvRowsToWorkspaceBlocks(array $assocRows, ?string $forcedProjectName, ?string $forcedProjectDescription): array
    {
        $groups = [];
        foreach ($assocRows as $i => $r) {
            $suiteName = trim((string) ($r['suite_name'] ?? ''));
            if ($suiteName === '') {
                throw new \InvalidArgumentException('CSV row ' . ($i + 2) . ': suite_name is empty');
            }
            if ($forcedProjectName !== null && $forcedProjectName !== '') {
                $pName = $forcedProjectName;
                $pDesc = $forcedProjectDescription;
                $gKey = $suiteName;
            } else {
                $pName = trim((string) ($r['project_name'] ?? ''));
                if ($pName === '') {
                    throw new \InvalidArgumentException('CSV row ' . ($i + 2) . ': project_name is empty (pick a target project or add a project column)');
                }
                $pd = $r['project_description'] ?? '';
                $pDesc = trim((string) $pd) === '' ? null : (string) $pd;
                $gKey = $pName . "\0" . $suiteName;
            }
            if (!isset($groups[$gKey])) {
                $groups[$gKey] = [
                    'project_name' => $pName,
                    'project_description' => $pDesc,
                    'suite_name' => $suiteName,
                    'sections' => [],
                    'cases' => [],
                ];
            }
            $sectionName = trim((string) ($r['section_name'] ?? ''));
            if ($sectionName === '') {
                $sectionName = 'Default';
            }
            $sectionPre = trim((string) ($r['section_precondition'] ?? ''));
            if (!isset($groups[$gKey]['sections'][$sectionName])) {
                $groups[$gKey]['sections'][$sectionName] = [
                    'name' => $sectionName,
                    'precondition' => $sectionPre === '' ? null : $sectionPre,
                    'sort_order' => count($groups[$gKey]['sections']),
                    'cases' => [],
                ];
            } elseif ($sectionPre !== '' && ($groups[$gKey]['sections'][$sectionName]['precondition'] ?? null) === null) {
                $groups[$gKey]['sections'][$sectionName]['precondition'] = $sectionPre;
            }
            $steps = self::parseStepsText((string) ($r['steps'] ?? ''));
            if (array_key_exists('expected', $r)) {
                $steps = self::applyExpectedColumn($steps, (string) $r['expected']);
            }
            $item = [
                'title' => $r['title'] ?? '',
                'precondition' => $r['precondition'] ?? null,
                'priority' => $r['priority'] ?? 'medium',
                'status' => $r['status'] ?? 'draft',
                'steps' => $steps,
            ];
            $case = self::normalizeCasePayload($item, 'CSV row ' . ($i + 2));
            $case['section_name'] = $sectionName;
            $case['section_precondition'] = $sectionPre === '' ? null : $sectionPre;
            $groups[$gKey]['cases'][] = $case;
            $groups[$gKey]['sections'][$sectionName]['cases'][] = $case;
        }

        return array_map(static function (array $block): array {
            $block['sections'] = array_values($block['sections']);
            return $block;
        }, array_values($groups));
    }

    /**
     * Flat CSV (suite-level): same as parseImportCsv but uses resolved column indices.
     *
     * @param list<list<string>> $dataRows
     * @return list<array{title: string, precondition: ?string, priority: string, status: string, steps: list<array<string, mixed>>}>
     */
    public static function parseFlatCsvWithIndices(array $headers, array $dataRows, array $indices): array
    {
        if (!isset($indices['title'])) {
            throw new \InvalidArgumentException('CSV import requires a title column (map "title" to your header)');
        }
        $assoc = self::csvRowsToAssoc($dataRows, $indices);
        $out = [];
        foreach ($assoc as $i => $r) {
            $steps = self::parseStepsText((string) ($r['steps'] ?? ''));
            if (array_key_exists('expected', $r)) {
                $steps = self::applyExpectedColumn($steps, (string) $r['expected']);
            }
            $out[] = self::normalizeCasePayload(
                [
                    'section_name' => $r['section_name'] ?? null,
                    'section_precondition' => $r['section_precondition'] ?? null,
                    'title' => $r['title'] ?? '',
                    'precondition' => $r['precondition'] ?? null,
                    'priority' => $r['priority'] ?? 'medium',
                    'status' => $r['status'] ?? 'draft',
                    'steps' => $steps,
                ],
                'CSV data row ' . ($i + 2)
            );
        }

        return $out;
    }

    /**
     * Merge a separate `expected` CSV column into already-parsed steps.
     *
     * Pairing rules:
     *  - Split the raw expected cell on `\r\n|\r|\n` and trim each line. Lines (incl. blanks)
     *    preserve their positional index relative to steps.
     *  - For each step at index i, if the step has no expected (empty string after parsing)
     *    AND `lines[i]` is a non-empty trimmed string, set the step's expected to `lines[i]`.
     *  - Never overwrite an expected that the in-cell parse already populated.
     *  - Extra lines beyond the step count are ignored (no new steps invented).
     *  - Fewer lines than steps leaves the remaining steps' expected untouched.
     *  - Single non-empty line + exactly one step with no expected → apply to that step.
     *
     * @param list<array<string, mixed>> $steps
     * @return list<array<string, mixed>>
     */
    private static function applyExpectedColumn(array $steps, string $rawExpected): array
    {
        if ($steps === []) {
            return $steps;
        }
        $raw = trim($rawExpected);
        if ($raw === '') {
            return $steps;
        }
        $split = preg_split('/\r\n|\r|\n/', $rawExpected);
        if ($split === false) {
            return $steps;
        }
        $lines = array_map(static fn ($l): string => trim((string) $l), $split);
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        if ($lines === []) {
            return $steps;
        }

        $nonEmpty = array_values(array_filter($lines, static fn (string $l): bool => $l !== ''));
        if (count($lines) === 1 && count($nonEmpty) === 1 && count($steps) === 1) {
            if (trim((string) ($steps[0]['expected'] ?? '')) === '') {
                $steps[0]['expected'] = $nonEmpty[0];
            }

            return $steps;
        }

        $count = min(count($lines), count($steps));
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if ($line === '') {
                continue;
            }
            $existing = trim((string) ($steps[$i]['expected'] ?? ''));
            if ($existing !== '') {
                continue;
            }
            $steps[$i]['expected'] = $line;
        }

        return $steps;
    }

    public static function parseImportJson(string $json): array
    {
        return self::parseImportData(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array{title: string, precondition: ?string, priority: string, status: string, steps: list<array<string, mixed>>}>
     */
    public static function parseImportData(mixed $data): array
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('JSON import must be an array or object');
        }
        if (isset($data['cases']) && is_array($data['cases'])) {
            $list = $data['cases'];
        } elseif (array_is_list($data)) {
            $list = $data;
        } else {
            throw new \InvalidArgumentException('JSON must be an array of cases or an object with a "cases" array');
        }
        $out = [];
        foreach ($list as $i => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Case at index ' . $i . ' must be an object');
            }
            $out[] = self::normalizeCasePayload($item, 'JSON case #' . ($i + 1));
        }
        return $out;
    }

    /**
     * @return list<array{title: string, precondition: ?string, priority: string, status: string, steps: list<array<string, mixed>>}>
     */
    public static function parseImportCsv(string $csv): array
    {
        $parsed = self::readCsvRaw($csv);
        $idx = self::resolveCsvColumnIndices($parsed['headers'], []);

        return self::parseFlatCsvWithIndices($parsed['headers'], $parsed['rows'], $idx);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function stripCaseRowForExport(array $row): array
    {
        return [
            'section_id' => $row['section_id'] ?? null,
            'section_name' => $row['section_name'] ?? 'Default',
            'section_precondition' => $row['section_precondition'] ?? null,
            'title' => $row['title'] ?? '',
            'precondition' => $row['precondition'] ?? null,
            'priority' => $row['priority'] ?? 'medium',
            'status' => $row['status'] ?? 'draft',
            'steps' => $row['steps'] ?? [],
        ];
    }

    /**
     * @return array{kind: 'workspace_v2', suites: list<array<string, mixed>>}|array{kind: 'cases', cases: list<array<string, mixed>>}
     */
    public static function classifyJsonImport(mixed $data): array
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('JSON import must be an object or array');
        }
        $ver = isset($data['version']) ? (int) $data['version'] : 0;
        if ($ver === 2 && isset($data['suites']) && is_array($data['suites'])) {
            return ['kind' => 'workspace_v2', 'suites' => self::normalizeWorkspaceV2ImportBlocks($data['suites'])];
        }

        return ['kind' => 'cases', 'cases' => self::parseImportData($data)];
    }

    /**
     * @param list<mixed> $blocks
     * @return list<array{project_name: string, project_description: ?string, suite_name: string, sections: list<array<string, mixed>>, cases: list<array<string, mixed>>}>
     */
    private static function normalizeWorkspaceV2ImportBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $i => $b) {
            if (!is_array($b)) {
                throw new \InvalidArgumentException('suites[' . $i . '] must be an object');
            }
            $pn = trim((string) ($b['project_name'] ?? ''));
            $sn = trim((string) ($b['suite_name'] ?? ''));
            if ($pn === '' || $sn === '') {
                throw new \InvalidArgumentException('suites[' . $i . ']: project_name and suite_name are required');
            }
            $sections = [];
            $cases = [];
            if (isset($b['sections']) && is_array($b['sections'])) {
                foreach ($b['sections'] as $si => $sectionRaw) {
                    if (!is_array($sectionRaw)) {
                        throw new \InvalidArgumentException('suite block #' . ($i + 1) . ', section #' . ($si + 1) . ' must be an object');
                    }
                    $sectionName = trim((string) ($sectionRaw['name'] ?? ''));
                    if ($sectionName === '') {
                        $sectionName = 'Default';
                    }
                    $sectionPre = $sectionRaw['precondition'] ?? null;
                    $sectionPrecondition = $sectionPre === null || trim((string) $sectionPre) === '' ? null : (string) $sectionPre;
                    $casesRaw = $sectionRaw['cases'] ?? [];
                    if (!is_array($casesRaw)) {
                        throw new \InvalidArgumentException('suite block #' . ($i + 1) . ', section #' . ($si + 1) . ': cases must be an array');
                    }
                    $sectionCases = [];
                    foreach ($casesRaw as $j => $item) {
                        if (!is_array($item)) {
                            throw new \InvalidArgumentException('suite block #' . ($i + 1) . ', section #' . ($si + 1) . ', case #' . ($j + 1) . ' must be an object');
                        }
                        $item['section_name'] = $sectionName;
                        $item['section_precondition'] = $sectionPrecondition;
                        $case = self::normalizeCasePayload($item, 'suite "' . $sn . '" section "' . $sectionName . '" case #' . ($j + 1));
                        $sectionCases[] = $case;
                        $cases[] = $case;
                    }
                    $sections[] = [
                        'name' => $sectionName,
                        'precondition' => $sectionPrecondition,
                        'sort_order' => isset($sectionRaw['sort_order']) ? (int) $sectionRaw['sort_order'] : $si,
                        'cases' => $sectionCases,
                    ];
                }
            } else {
                $casesRaw = $b['cases'] ?? [];
                if (!is_array($casesRaw)) {
                    throw new \InvalidArgumentException('suites[' . $i . ']: cases must be an array');
                }
                foreach ($casesRaw as $j => $item) {
                    if (!is_array($item)) {
                        throw new \InvalidArgumentException('suite block #' . ($i + 1) . ', case #' . ($j + 1) . ' must be an object');
                    }
                    $item['section_name'] = 'Default';
                    $item['section_precondition'] = null;
                    $case = self::normalizeCasePayload($item, 'suite "' . $sn . '" case #' . ($j + 1));
                    $cases[] = $case;
                }
                $sections[] = [
                    'name' => 'Default',
                    'precondition' => null,
                    'sort_order' => 0,
                    'cases' => $cases,
                ];
            }
            $pd = $b['project_description'] ?? null;
            $out[] = [
                'project_name' => $pn,
                'project_description' => $pd === null || $pd === '' ? null : (string) $pd,
                'suite_name' => $sn,
                'sections' => $sections,
                'cases' => $cases,
            ];
        }
        if ($out === []) {
            throw new \InvalidArgumentException('No suites in workspace JSON');
        }

        return $out;
    }

    /**
     * Insert normalized import rows into one suite; enforces duplicate policy by title (exact match).
     *
     * @param list<array{title: string, precondition: ?string, priority: string, status: string, steps: list<array<string, mixed>>}> $cases
     * @return array{imported: int, skipped: int}
     */
    public static function insertImportedCases(PDO $pdo, int $suiteId, array $cases, string $onDuplicate, bool $createMissingSections = true): array
    {
        $mode = strtolower(trim($onDuplicate));
        if (!in_array($mode, ['skip', 'error', 'allow'], true)) {
            $mode = 'allow';
        }

        $check = $pdo->prepare('SELECT 1 FROM test_cases WHERE suite_id = :sid AND title = :t LIMIT 1');
        $ins = $pdo->prepare(
            'INSERT INTO test_cases (suite_id, section_id, title, precondition, priority, status)
             VALUES (:suite_id, :section_id, :title, :precondition, :priority, :status)'
        );

        $imported = 0;
        $skipped = 0;
        foreach ($cases as $c) {
            $title = $c['title'];
            if ($mode !== 'allow') {
                $check->execute(['sid' => $suiteId, 't' => $title]);
                if ((bool) $check->fetchColumn()) {
                    if ($mode === 'skip') {
                        ++$skipped;
                        continue;
                    }
                    throw new \InvalidArgumentException('Duplicate case title in target suite: ' . $title);
                }
            }
            $sectionName = trim((string) ($c['section_name'] ?? ''));
            if ($sectionName === '') {
                $sectionName = 'Default';
            }
            $sectionId = self::findOrCreateSection(
                $pdo,
                $suiteId,
                $sectionName,
                $c['section_precondition'] ?? null,
                $createMissingSections
            );
            $ins->execute([
                'suite_id' => $suiteId,
                'section_id' => $sectionId,
                'title' => $title,
                'precondition' => $c['precondition'],
                'priority' => $c['priority'],
                'status' => $c['status'],
            ]);
            $caseId = (int) $pdo->lastInsertId();
            TestCaseStepsService::replaceCaseSteps($pdo, $caseId, $c['steps']);
            ++$imported;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private static function findOrCreateSection(PDO $pdo, int $suiteId, string $name, mixed $precondition, bool $createMissing): int
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM test_sections WHERE suite_id = :sid AND name = :name ORDER BY sort_order, id LIMIT 1'
        );
        $stmt->execute(['sid' => $suiteId, 'name' => $name]);
        $v = $stmt->fetchColumn();
        if ($v !== false) {
            return (int) $v;
        }
        if (!$createMissing) {
            throw new \InvalidArgumentException('Section not found: ' . $name . ' (enable create missing entities or create it first)');
        }

        $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM test_sections WHERE suite_id = :sid');
        $orderStmt->execute(['sid' => $suiteId]);
        $sortOrder = $name === 'Default' ? 0 : (int) $orderStmt->fetchColumn();
        $pre = $precondition === null || trim((string) $precondition) === '' ? null : (string) $precondition;
        $ins = $pdo->prepare(
            'INSERT INTO test_sections (suite_id, name, precondition, sort_order)
             VALUES (:suite_id, :name, :precondition, :sort_order)'
        );
        $ins->execute([
            'suite_id' => $suiteId,
            'name' => $name,
            'precondition' => $pre,
            'sort_order' => $sortOrder,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $item
     * @return array{title: string, precondition: ?string, priority: string, status: string, steps: list<array<string, mixed>>}
     */
    private static function normalizeCasePayload(array $item, string $contextLabel): array
    {
        $title = trim((string) ($item['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Each case needs a non-empty title (' . $contextLabel . ')');
        }
        $pre = $item['precondition'] ?? null;
        $precondition = $pre === null || $pre === '' ? null : (string) $pre;
        $sectionRaw = trim((string) ($item['section_name'] ?? ''));
        $sectionName = $sectionRaw === '' ? 'Default' : $sectionRaw;
        $sectionPreRaw = $item['section_precondition'] ?? null;
        $sectionPrecondition = $sectionPreRaw === null || trim((string) $sectionPreRaw) === ''
            ? null
            : (string) $sectionPreRaw;

        $priority = strtolower(trim((string) ($item['priority'] ?? 'medium')));
        if (!in_array($priority, self::PRIORITIES, true)) {
            $priority = 'medium';
        }
        $status = strtolower(trim((string) ($item['status'] ?? 'draft')));
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'draft';
        }

        $steps = self::normalizeSteps($item['steps'] ?? ($item['steps_json'] ?? []), $contextLabel);

        return [
            'section_name' => $sectionName,
            'section_precondition' => $sectionPrecondition,
            'title' => $title,
            'precondition' => $precondition,
            'priority' => $priority,
            'status' => $status,
            'steps' => $steps,
        ];
    }

    /**
     * Parse a plain-text steps cell (as produced by `stepsToText`) into the normalized step list.
     *
     * Format:
     *  - One step per non-empty line.
     *  - Optional leading `1.`, `1)`, or `- ` is stripped from each step line.
     *  - Expected result (pick one per step):
     *    - Preferred: a continuation line `Expected: …` or `Result: …` (case-insensitive
     *      keyword, optional leading whitespace) directly after the step. This overrides
     *      any inline separator on that step.
     *    - Shortcut: inline, action and expected separated by ` -> ` (preferred), ` => `,
     *      or ` | ` on the step line itself.
     *  - Lines starting with `*` are variants of the most recent step. Content may include
     *    a leading `[label] ` to set the variant label; otherwise the whole content is the
     *    criteria. Variant lines are always treated as variants, even when their content
     *    begins with `Expected:`.
     *
     * @return list<array{action: string, expected: string, variants?: list<array{label?: string, criteria: string}>}>
     */
    public static function parseStepsText(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $steps = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            if (preg_match('/^\*\s*(.*)$/u', $trim, $m)) {
                $rest = trim($m[1]);
                if ($rest === '' || $steps === []) {
                    continue;
                }
                $variant = [];
                if (preg_match('/^\[([^\]]+)\]\s*(.*)$/u', $rest, $vm)) {
                    $label = trim($vm[1]);
                    $criteria = trim($vm[2]);
                    if ($criteria === '') {
                        $criteria = $label;
                        $label = '';
                    }
                    if ($label !== '') {
                        $variant['label'] = $label;
                    }
                    $variant['criteria'] = $criteria;
                } else {
                    $variant['criteria'] = $rest;
                }
                if (($variant['criteria'] ?? '') === '') {
                    continue;
                }
                $last = count($steps) - 1;
                $steps[$last]['variants'][] = $variant;
                continue;
            }
            if ($steps !== [] && preg_match('/^(?:expected|result)\s*:\s*(.*)$/iu', $trim, $em)) {
                $val = trim((string) ($em[1] ?? ''));
                if ($val !== '') {
                    $last = count($steps) - 1;
                    $steps[$last]['expected'] = $val;
                }
                continue;
            }
            $stepLine = preg_replace('/^(?:\d+[.)]|-)\s+/u', '', $trim) ?? $trim;
            $action = $stepLine;
            $expected = '';
            foreach ([' -> ', ' => ', ' | '] as $sep) {
                $pos = mb_strpos($stepLine, $sep);
                if ($pos !== false) {
                    $action = trim(mb_substr($stepLine, 0, $pos));
                    $expected = trim(mb_substr($stepLine, $pos + mb_strlen($sep)));
                    break;
                }
            }
            $steps[] = ['action' => $action, 'expected' => $expected, 'variants' => []];
        }
        $out = [];
        foreach ($steps as $s) {
            if ($s['action'] === '' && $s['expected'] === '' && $s['variants'] === []) {
                continue;
            }
            $item = ['action' => $s['action'], 'expected' => $s['expected']];
            if ($s['variants'] !== []) {
                $item['variants'] = $s['variants'];
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Format normalized steps as plain text for a CSV cell (inverse of `parseStepsText`).
     *
     * @param list<array<string, mixed>> $steps
     */
    public static function stepsToText(array $steps): string
    {
        $lines = [];
        $i = 0;
        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }
            $action = trim((string) ($step['action'] ?? ''));
            $expected = trim((string) ($step['expected'] ?? ''));
            $variants = is_array($step['variants'] ?? null) ? $step['variants'] : [];
            if ($action === '' && $expected === '' && $variants === []) {
                continue;
            }
            ++$i;
            $lines[] = $i . '. ' . $action;
            if ($expected !== '') {
                $lines[] = '   Expected: ' . $expected;
            }
            foreach ($variants as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $criteria = trim((string) ($v['criteria'] ?? ''));
                if ($criteria === '') {
                    continue;
                }
                $label = trim((string) ($v['label'] ?? ''));
                $lines[] = '* ' . ($label !== '' ? '[' . $label . '] ' . $criteria : $criteria);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{action: string, expected: string, variants?: list<array{label?: string, criteria: string}>}>
     */
    private static function normalizeSteps(mixed $raw, string $contextLabel): array
    {
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                return self::parseStepsText($trimmed);
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            return [];
        }
        // Single step stored as one JSON object (not wrapped in an array).
        if (!array_is_list($raw) && (array_key_exists('action', $raw) || array_key_exists('expected', $raw))) {
            return self::normalizeSteps([$raw], $contextLabel);
        }
        $out = [];
        foreach ($raw as $j => $step) {
            if (!is_array($step)) {
                continue;
            }
            $action = trim((string) ($step['action'] ?? ''));
            $expected = trim((string) ($step['expected'] ?? ''));
            if ($action === '' && $expected === '') {
                continue;
            }
            $item = ['action' => $action, 'expected' => $expected];
            $variants = self::normalizeVariants($step['variants'] ?? null, $contextLabel . ', step ' . ($j + 1));
            if ($variants !== []) {
                $item['variants'] = $variants;
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Variants add extra criteria for executing the same step (e.g. platform, role, data shape).
     *
     * @return list<array{label?: string, criteria: string}>
     */
    private static function normalizeVariants(mixed $raw, string $contextLabel): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('variants must be an array (' . $contextLabel . ')');
        }
        $out = [];
        foreach ($raw as $k => $v) {
            if (!is_array($v)) {
                continue;
            }
            $criteria = trim((string) ($v['criteria'] ?? ''));
            if ($criteria === '') {
                continue;
            }
            $label = trim((string) ($v['label'] ?? ''));
            $row = ['criteria' => $criteria];
            if ($label !== '') {
                $row['label'] = $label;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param list<string|null> $row
     */
    private static function csvRowIsBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) ($cell ?? '')) !== '') {
                return false;
            }
        }
        return true;
    }
}
