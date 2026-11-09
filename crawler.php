<?php
// crawler.php - Multi-source remote job crawler

require_once __DIR__ . '/db.php';

// ─────────────────────────────────────────────
// Dynamic Keyword Filter Configuration
// Keywords can be passed via:
// 1. CLI options: php crawler.php --keywords="php,laravel" or -k "react,node"
// 2. CLI positional args: php crawler.php php laravel backend
// 3. Environment variable or .env: KEYWORD_FILTER=php,laravel,backend
// ─────────────────────────────────────────────

/**
 * Load key-value pairs from .env if present.
 */
function loadDotEnv(string $path = __DIR__ . '/.env'): void
{
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            if (getenv($key) === false && !isset($_ENV[$key])) {
                putenv("$key=$val");
                $_ENV[$key] = $val;
            }
        }
    }
}

/**
 * Dynamically resolve keyword filter.
 */
function resolveKeywordFilter(array $argv): array
{
    $keywords = [];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (str_starts_with($arg, '--keywords=')) {
            $raw = substr($arg, 11);
            $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
            $keywords = array_merge($keywords, $parts);
        } elseif (($arg === '--keywords' || $arg === '-k') && isset($argv[$i + 1])) {
            $raw = $argv[++$i];
            $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
            $keywords = array_merge($keywords, $parts);
        } elseif (!str_starts_with($arg, '-')) {
            $parts = preg_split('/[\s,]+/', $arg, -1, PREG_SPLIT_NO_EMPTY);
            $keywords = array_merge($keywords, $parts);
        }
    }

    if (empty($keywords)) {
        loadDotEnv();
        $envVal = getenv('KEYWORD_FILTER') ?: ($_ENV['KEYWORD_FILTER'] ?? getenv('KEYWORDS') ?: ($_ENV['KEYWORDS'] ?? ''));
        if (!empty($envVal)) {
            $parts = preg_split('/[\s,]+/', $envVal, -1, PREG_SPLIT_NO_EMPTY);
            $keywords = array_merge($keywords, $parts);
        }
    }

    return array_values(array_unique(array_filter(array_map(fn($k) => strtolower(trim($k)), $keywords))));
}

$KEYWORD_FILTER = resolveKeywordFilter($argv ?? []);

$startTime = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Iniciando crawler de vagas (multi-source)...\n";
if (!empty($KEYWORD_FILTER)) {
    echo "[" . date('Y-m-d H:i:s') . "] Filtro por palavra-chave ativo: [" . implode(', ', $KEYWORD_FILTER) . "]\n";
} else {
    echo "[" . date('Y-m-d H:i:s') . "] Filtro por palavra-chave: nenhum (todas as vagas serão aceitas)\n";
}

// ─────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────

/**
 * Fetch JSON from a URL with a proper User-Agent.
 */
function fetchJson(string $url): array
{
    $opts = [
        "http" => [
            "header"        => "User-Agent: JobCrawler/2.0 (personal jobs tracker)\r\n",
            "timeout"       => 15,
            "ignore_errors" => true,
        ]
    ];
    $ctx      = stream_context_create($opts);
    $response = @file_get_contents($url, false, $ctx);

    if ($response === false) {
        throw new RuntimeException("Falha ao buscar: $url");
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("JSON inválido de: $url");
    }

    return $data;
}

/**
 * Detect B2B/contract nature from text.
 */
function detectB2b(string $text): int
{
    $lower    = strtolower($text);
    $keywords = [
        'b2b', 'contract', 'contractor', 'freelance', 'pj',
        'prestador', 'pessoa jurídica', 'independent contractor',
        'consulting', 'consultancy', '1099', 'c2c', 'corp-to-corp',
    ];

    foreach ($keywords as $kw) {
        if (str_contains($lower, $kw)) return 1;
    }

    return 0;
}

/**
 * Check if job passes dynamic keyword filter across title, tags, and description.
 */
function passesKeywordFilter(string $title, array $tags, string $description, array $filter): bool
{
    if (empty($filter)) return true;
    $haystack = strtolower($title . ' ' . implode(' ', $tags) . ' ' . strip_tags($description));
    foreach ($filter as $kw) {
        if (str_contains($haystack, strtolower($kw))) return true;
    }
    return false;
}

/**
 * Clean HTML from description — decode entities, strip tags, trim.
 */
function cleanDescription(string $html): string
{
    return substr(trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)), 0, 600);
}

/**
 * Insert one job; returns true if it was a NEW row.
 */
function insertJob(
    PDO     $pdo,
    string  $source,
    string  $externalId,
    string  $title,
    string  $company,
    string  $link,
    string  $description,
    string  $category,
    int     $isB2b,
    string  $postedAt,
    ?int    $salaryMin = null,
    ?int    $salaryMax = null,
    ?string $location  = null,
    ?string $jobType   = null
): bool {
    $stmt = $pdo->prepare("
        INSERT OR IGNORE INTO jobs
            (source, external_id, title, company, link, description, category,
             is_b2b, posted_at, salary_min, salary_max, location, job_type)
        VALUES
            (:source, :external_id, :title, :company, :link, :description, :category,
             :is_b2b, :posted_at, :salary_min, :salary_max, :location, :job_type)
    ");

    $stmt->execute([
        ':source'      => $source,
        ':external_id' => $externalId,
        ':title'       => $title,
        ':company'     => $company,
        ':link'        => $link,
        ':description' => cleanDescription($description),
        ':category'    => $category,
        ':is_b2b'      => $isB2b,
        ':posted_at'   => $postedAt,
        ':salary_min'  => $salaryMin,
        ':salary_max'  => $salaryMax,
        ':location'    => $location,
        ':job_type'    => $jobType,
    ]);

    return $stmt->rowCount() > 0;
}

// ─────────────────────────────────────────────
// Source 1 – Remotive (tech remote jobs)
// ─────────────────────────────────────────────
function crawlRemotive(PDO $pdo, array $filter): array
{
    $total = $new = 0;
    $data  = fetchJson("https://remotive.com/api/remote-jobs?limit=100");

    if (!isset($data['jobs'])) throw new RuntimeException("Remotive: formato inesperado.");

    foreach ($data['jobs'] as $job) {
        $title = $job['title'] ?? '';
        $tags  = $job['tags'] ?? [];

        if (!passesKeywordFilter($title, $tags, $job['description'] ?? '', $filter)) continue;

        $postedAt = isset($job['publication_date'])
            ? date('Y-m-d H:i:s', strtotime($job['publication_date']))
            : date('Y-m-d H:i:s');

        $isNew = insertJob(
            $pdo,
            'remotive',
            "remotive_" . $job['id'],
            $title,
            $job['company_name'] ?? 'Unknown',
            $job['url'] ?? '',
            $job['description'] ?? '',
            $job['category'] ?? 'Remote',
            detectB2b($title . ' ' . ($job['description'] ?? '')),
            $postedAt,
            null,
            null,
            $job['candidate_required_location'] ?? null,
            $job['job_type'] ?? null
        );
        $total++;
        if ($isNew) $new++;
    }

    return [$total, $new];
}

// ─────────────────────────────────────────────
// Source 2 – Jobicy (remote-first, global)
// ─────────────────────────────────────────────
function crawlJobicy(PDO $pdo, array $filter): array
{
    $total = $new = 0;
    $data  = fetchJson("https://jobicy.com/api/v2/remote-jobs?count=50&geo=worldwide");

    if (!isset($data['jobs'])) throw new RuntimeException("Jobicy: formato inesperado.");

    foreach ($data['jobs'] as $job) {
        $title = $job['jobTitle'] ?? '';

        if (!passesKeywordFilter($title, [], $job['jobDescription'] ?? '', $filter)) continue;

        $isNew = insertJob(
            $pdo,
            'jobicy',
            "jobicy_" . $job['id'],
            $title,
            $job['companyName'] ?? 'Unknown',
            $job['url'] ?? '',
            $job['jobDescription'] ?? '',
            $job['jobIndustry'][0] ?? $job['jobType'][0] ?? 'Remote',
            detectB2b($title . ' ' . ($job['jobDescription'] ?? '')),
            date('Y-m-d H:i:s', strtotime($job['pubDate'] ?? 'now'))
        );
        $total++;
        if ($isNew) $new++;
    }

    return [$total, $new];
}

// ─────────────────────────────────────────────
// Source 3 – RemoteOK
// ─────────────────────────────────────────────
function crawlRemoteOK(PDO $pdo, array $filter): array
{
    $total = $new = 0;
    $data  = fetchJson("https://remoteok.com/api");

    foreach ($data as $job) {
        if (!is_array($job) || !isset($job['id'])) continue; // skip legal notice

        $title = $job['position'] ?? '';
        $tags  = (array)($job['tags'] ?? []);

        if (!passesKeywordFilter($title, $tags, $job['description'] ?? '', $filter)) continue;

        $postedAt = isset($job['epoch'])
            ? date('Y-m-d H:i:s', $job['epoch'])
            : date('Y-m-d H:i:s', strtotime($job['date'] ?? 'now'));

        // Use only first 3 tags as category (not all 20+)
        $category = implode(', ', array_slice($tags, 0, 3)) ?: 'Remote';

        // Salary (structured integers from RemoteOK)
        $salaryMin = isset($job['salary_min']) && $job['salary_min'] > 0 ? (int)$job['salary_min'] : null;
        $salaryMax = isset($job['salary_max']) && $job['salary_max'] > 0 ? (int)$job['salary_max'] : null;

        $isNew = insertJob(
            $pdo,
            'remoteok',
            "remoteok_" . $job['id'],
            $title,
            $job['company'] ?? 'Unknown',
            $job['url'] ?? "https://remoteok.com/remote-jobs/" . $job['id'],
            $job['description'] ?? '',
            $category,
            detectB2b($title . ' ' . ($job['description'] ?? '')),
            $postedAt,
            $salaryMin,
            $salaryMax,
            $job['location'] ?? null,
            null
        );
        $total++;
        if ($isNew) $new++;
    }

    return [$total, $new];
}

// ─────────────────────────────────────────────
// Source 4 – Arbeitnow (EU + remote filter)
// ─────────────────────────────────────────────
function crawlArbeitnow(PDO $pdo, array $filter): array
{
    $total = $new = 0;
    $data  = fetchJson("https://www.arbeitnow.com/api/job-board-api?page=1");

    if (!isset($data['data'])) throw new RuntimeException("Arbeitnow: formato inesperado.");

    foreach ($data['data'] as $job) {
        if (!($job['remote'] ?? false)) continue; // remote-only

        $title = $job['title'] ?? '';
        $tags  = (array)($job['tags'] ?? []);

        if (!passesKeywordFilter($title, $tags, $job['description'] ?? '', $filter)) continue;

        $postedAt = isset($job['created_at'])
            ? date('Y-m-d H:i:s', $job['created_at'])
            : date('Y-m-d H:i:s');

        $jobType  = implode(', ', (array)($job['job_types'] ?? [])) ?: null;
        $category = implode(', ', array_slice($tags, 0, 3)) ?: 'Remote / EU';

        $isNew = insertJob(
            $pdo,
            'arbeitnow',
            "arbeitnow_" . ($job['slug'] ?? md5($job['url'] ?? uniqid())),
            $title,
            $job['company_name'] ?? 'Unknown',
            $job['url'] ?? '',
            $job['description'] ?? '',
            $category,
            detectB2b($title . ' ' . ($job['description'] ?? '')),
            $postedAt,
            null,
            null,
            $job['location'] ?? null,
            $jobType
        );
        $total++;
        if ($isNew) $new++;
    }

    return [$total, $new];
}

// ─────────────────────────────────────────────
// Run all sources
// ─────────────────────────────────────────────
$sources = [
    'Remotive'  => fn($pdo) => crawlRemotive($pdo, $KEYWORD_FILTER),
    'Jobicy'    => fn($pdo) => crawlJobicy($pdo, $KEYWORD_FILTER),
    'RemoteOK'  => fn($pdo) => crawlRemoteOK($pdo, $KEYWORD_FILTER),
    'Arbeitnow' => fn($pdo) => crawlArbeitnow($pdo, $KEYWORD_FILTER),
];

$grandTotal = 0;
$grandNew   = 0;

foreach ($sources as $name => $fn) {
    try {
        [$total, $new] = $fn($pdo);
        echo "[" . date('Y-m-d H:i:s') . "] [$name] Processadas: $total | Novas: $new\n";
        $grandTotal += $total;
        $grandNew   += $new;
    } catch (Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] [$name] ERRO: " . $e->getMessage() . "\n";
    }
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "[" . date('Y-m-d H:i:s') . "] Concluído em {$elapsed}s — Total processadas: $grandTotal | Total novas: $grandNew\n";
