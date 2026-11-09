<?php
// index.php - Job offers dashboard and filtering interface

require_once __DIR__ . '/db.php';

// ── Query parameters for filtering ──────────────────────────────────────────
$search    = trim($_GET['q'] ?? '');
$filterSrc = $_GET['source'] ?? '';
$filterB2b = $_GET['b2b'] ?? '';
$filterType= $_GET['type'] ?? '';
$days      = max(1, (int)($_GET['days'] ?? 30));

// ── Build dynamic query ──────────────────────────────────────────────────────
$where  = ["datetime(posted_at) >= datetime('now', '-{$days} days')"];
$params = [];

if ($search !== '') {
    $where[]          = "(title LIKE :search OR company LIKE :search OR description LIKE :search OR category LIKE :search)";
    $params[':search'] = "%{$search}%";
}
if ($filterSrc !== '') {
    $where[]        = "source = :source";
    $params[':source'] = $filterSrc;
}
if ($filterB2b === '1') {
    $where[] = "is_b2b = 1";
}
if ($filterType !== '') {
    $where[]         = "job_type LIKE :type";
    $params[':type'] = "%{$filterType}%";
}

$sql = "SELECT * FROM jobs WHERE " . implode(' AND ', $where) . " ORDER BY posted_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $jobs = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Erro ao buscar vagas: " . $e->getMessage());
}

// ── Stats ────────────────────────────────────────────────────────────────────
try {
    $totalAll     = $pdo->query("SELECT COUNT(*) FROM jobs")->fetchColumn();
    $totalB2b     = $pdo->query("SELECT COUNT(*) FROM jobs WHERE is_b2b = 1")->fetchColumn();
    $totalApplied = $pdo->query("SELECT COUNT(*) FROM jobs WHERE applied = 1")->fetchColumn();
    $sources      = $pdo->query("SELECT source, COUNT(*) as cnt FROM jobs GROUP BY source ORDER BY cnt DESC")->fetchAll();
    $lastCrawl    = $pdo->query("SELECT MAX(created_at) FROM jobs")->fetchColumn();
} catch (PDOException $e) {
    $totalAll = $totalB2b = $totalApplied = 0;
    $sources = [];
    $lastCrawl = null;
}

$sourceColors = [
    'remotive'  => ['bg' => '#6366f1', 'label' => 'Remotive'],
    'remoteok'  => ['bg' => '#0ea5e9', 'label' => 'RemoteOK'],
    'arbeitnow' => ['bg' => '#10b981', 'label' => 'Arbeitnow'],
    'jobicy'    => ['bg' => '#f59e0b', 'label' => 'Jobicy'],
    'unknown'   => ['bg' => '#6b7280', 'label' => 'Unknown'],
];

function formatSalary(?int $min, ?int $max): string {
    if (!$min && !$max) return '';
    $fmt = fn($n) => '$' . number_format($n / 1000, 0) . 'k';
    if ($min && $max) return $fmt($min) . '–' . $fmt($max);
    if ($min) return $fmt($min) . '+';
    return 'up to ' . $fmt($max);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Offers Board — Remote & B2B</title>
    <meta name="description" content="Personal remote job tracker aggregating listings from Remotive, RemoteOK, Arbeitnow and Jobicy.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:        #0f1117;
            --surface:   #1a1d27;
            --surface2:  #22263a;
            --border:    rgba(255,255,255,0.07);
            --text:      #e2e8f0;
            --muted:     #64748b;
            --accent:    #6366f1;
            --accent2:   #818cf8;
            --green:     #10b981;
            --amber:     #f59e0b;
            --red:       #ef4444;
            --radius:    14px;
            --radius-sm: 8px;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.6;
        }

        /* ── Layout ── */
        .wrapper { max-width: 1100px; margin: 0 auto; padding: 32px 20px 60px; }

        /* ── Header ── */
        .header { margin-bottom: 32px; }
        .header-top { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 8px; }
        h1 { font-size: 1.75rem; font-weight: 700; letter-spacing: -.03em; }
        h1 span { color: var(--accent2); }
        .last-crawl { font-size: .75rem; color: var(--muted); }

        /* ── Stats row ── */
        .stats { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 28px; }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 20px;
            min-width: 140px;
            flex: 1;
        }
        .stat-card .val { font-size: 1.6rem; font-weight: 700; color: var(--accent2); }
        .stat-card .lbl { font-size: .72rem; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-top: 2px; }

        /* ── Source pills ── */
        .source-pills { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px; }
        .pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 999px; font-size: .72rem;
            font-weight: 600; letter-spacing: .04em; text-transform: uppercase;
            border: 1px solid transparent; cursor: pointer; transition: opacity .15s;
            text-decoration: none; color: #fff;
        }
        .pill:hover { opacity: .8; }
        .pill .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; opacity: .7; }
        .pill-outline { background: transparent !important; color: var(--muted) !important; border-color: var(--border) !important; }

        /* ── Search / filters ── */
        .filters {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;
        }
        .filter-group { display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 160px; }
        .filter-group label { font-size: .72rem; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
        .filter-group input,
        .filter-group select {
            background: var(--bg); border: 1px solid var(--border);
            color: var(--text); border-radius: var(--radius-sm);
            padding: 9px 12px; font-size: .85rem; font-family: inherit;
            transition: border-color .15s;
        }
        .filter-group input:focus,
        .filter-group select:focus { outline: none; border-color: var(--accent); }
        .filter-group select option { background: var(--surface); }
        .btn-filter {
            background: var(--accent); color: #fff; border: none;
            border-radius: var(--radius-sm); padding: 9px 20px;
            font-size: .85rem; font-weight: 600; font-family: inherit;
            cursor: pointer; transition: background .15s; white-space: nowrap;
        }
        .btn-filter:hover { background: #4f46e5; }
        .btn-reset {
            background: transparent; color: var(--muted); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 9px 16px;
            font-size: .85rem; font-family: inherit; cursor: pointer; transition: color .15s;
            white-space: nowrap; text-decoration: none;
        }
        .btn-reset:hover { color: var(--text); }

        /* ── Results meta ── */
        .results-meta { font-size: .8rem; color: var(--muted); margin-bottom: 16px; }
        .results-meta strong { color: var(--text); }

        /* ── Job cards ── */
        .jobs-list { display: flex; flex-direction: column; gap: 12px; }
        .job-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px 24px;
            display: flex; gap: 20px; align-items: flex-start;
            transition: border-color .2s, transform .15s, box-shadow .15s;
        }
        .job-card:hover {
            border-color: rgba(99,102,241,.35);
            box-shadow: 0 4px 24px rgba(99,102,241,.08);
            transform: translateY(-1px);
        }
        .job-card.applied { opacity: .45; }

        .job-body { flex: 1; min-width: 0; }
        .job-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 8px; }

        .badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 10px; border-radius: 6px;
            font-size: .68rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
        }
        .badge-source  { color: #fff; }
        .badge-b2b     { background: rgba(16,185,129,.15); color: #34d399; border: 1px solid rgba(16,185,129,.25); }
        .badge-applied { background: rgba(99,102,241,.15); color: var(--accent2); border: 1px solid rgba(99,102,241,.25); }
        .badge-type    { background: rgba(245,158,11,.12); color: #fbbf24; border: 1px solid rgba(245,158,11,.25); }
        .badge-cat     { background: var(--surface2); color: var(--muted); border: 1px solid var(--border); }

        .date-label { font-size: .72rem; color: var(--muted); margin-left: auto; white-space: nowrap; }

        .job-title {
            font-size: 1.05rem; font-weight: 600; color: #f1f5f9;
            margin-bottom: 4px; line-height: 1.3;
        }
        .job-company { font-size: .85rem; color: var(--muted); margin-bottom: 10px; }
        .job-company span { color: var(--text); font-weight: 500; }

        .job-desc { font-size: .82rem; color: var(--muted); line-height: 1.55;
                    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

        .job-footer { display: flex; align-items: center; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
        .salary-badge {
            font-size: .75rem; font-weight: 600; color: var(--green);
            background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.2);
            padding: 3px 10px; border-radius: 6px;
        }
        .location-badge {
            font-size: .75rem; color: var(--muted);
        }

        .job-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; flex-shrink: 0; }
        .btn-apply {
            background: var(--accent); color: #fff; border: none;
            border-radius: var(--radius-sm); padding: 9px 18px;
            font-size: .82rem; font-weight: 600; font-family: inherit;
            cursor: pointer; text-decoration: none; transition: background .15s;
            white-space: nowrap; display: inline-block;
        }
        .btn-apply:hover { background: #4f46e5; }

        /* ── Empty state ── */
        .empty {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 60px 32px; text-align: center;
        }
        .empty .icon { font-size: 2.5rem; margin-bottom: 12px; }
        .empty p { color: var(--muted); }

        /* ── Responsive ── */
        @media (max-width: 600px) {
            .job-card { flex-direction: column; }
            .job-actions { flex-direction: row; width: 100%; justify-content: flex-end; }
            .date-label { margin-left: 0; }
        }
    </style>
</head>
<body>
<div class="wrapper">

    <!-- Header -->
    <header class="header">
        <div class="header-top">
            <h1>💼 Job Offers <span>Board</span></h1>
            <?php if ($lastCrawl): ?>
            <span class="last-crawl">Last sync: <?= date('d M Y, H:i', strtotime($lastCrawl)) ?></span>
            <?php endif; ?>
        </div>
        <p style="color: var(--muted); font-size:.85rem;">Remote &amp; B2B opportunities aggregated from <?= count($sources) ?> sources.</p>
    </header>

    <!-- Stats -->
    <div class="stats">
        <div class="stat-card">
            <div class="val"><?= $totalAll ?></div>
            <div class="lbl">Total Jobs</div>
        </div>
        <div class="stat-card">
            <div class="val"><?= count($jobs) ?></div>
            <div class="lbl">Showing Now</div>
        </div>
        <div class="stat-card">
            <div class="val"><?= $totalB2b ?></div>
            <div class="lbl">B2B / Contract</div>
        </div>
        <div class="stat-card">
            <div class="val"><?= $totalApplied ?></div>
            <div class="lbl">Applied</div>
        </div>
    </div>

    <!-- Source pills -->
    <div class="source-pills">
        <a href="?" class="pill pill-outline" title="All sources">All sources</a>
        <?php foreach ($sources as $src):
            $color = $sourceColors[$src['source']] ?? $sourceColors['unknown'];
        ?>
        <a href="?source=<?= urlencode($src['source']) ?>&days=<?= $days ?>"
           class="pill"
           style="background: <?= $color['bg'] ?>22; border-color: <?= $color['bg'] ?>55; color: <?= $color['bg'] ?>;">
            <span class="dot" style="background:<?= $color['bg'] ?>;"></span>
            <?= htmlspecialchars($color['label']) ?> <strong><?= $src['cnt'] ?></strong>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <form method="GET" action="" class="filters" id="filterForm">
        <div class="filter-group" style="flex:2; min-width:220px;">
            <label for="q">🔍 Search</label>
            <input type="text" id="q" name="q" placeholder="Title, company, skill…" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="filter-group">
            <label for="source">Source</label>
            <select id="source" name="source">
                <option value="">All sources</option>
                <?php foreach ($sources as $src):
                    $color = $sourceColors[$src['source']] ?? $sourceColors['unknown'];
                ?>
                <option value="<?= htmlspecialchars($src['source']) ?>" <?= $filterSrc === $src['source'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($color['label']) ?> (<?= $src['cnt'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="b2b">Contract</label>
            <select id="b2b" name="b2b">
                <option value="">All types</option>
                <option value="1" <?= $filterB2b === '1' ? 'selected' : '' ?>>B2B / Contract only</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="days">Window</label>
            <select id="days" name="days">
                <?php foreach ([7, 14, 30, 60, 90] as $d): ?>
                <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>>Last <?= $d ?> days</option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="source" value="<?= htmlspecialchars($filterSrc) ?>" id="sourceHidden">
        <button type="submit" class="btn-filter">Search</button>
        <a href="?" class="btn-reset">Reset</a>
    </form>

    <!-- Results meta -->
    <p class="results-meta">
        Showing <strong><?= count($jobs) ?></strong> job<?= count($jobs) !== 1 ? 's' : '' ?>
        from the last <strong><?= $days ?> days</strong>
        <?php if ($search): ?> matching "<strong><?= htmlspecialchars($search) ?></strong>"<?php endif; ?>
        <?php if ($filterSrc): ?> from <strong><?= htmlspecialchars($sourceColors[$filterSrc]['label'] ?? $filterSrc) ?></strong><?php endif; ?>
        <?php if ($filterB2b): ?> · <strong>B2B only</strong><?php endif; ?>
    </p>

    <!-- Job List -->
    <?php if (empty($jobs)): ?>
    <div class="empty">
        <div class="icon">🔎</div>
        <p>No jobs found with the current filters.</p>
        <p style="margin-top:8px; font-size:.8rem;">Try expanding the date window or clearing the search.</p>
    </div>
    <?php else: ?>
    <div class="jobs-list">
        <?php foreach ($jobs as $job):
            $srcMeta = $sourceColors[$job['source'] ?? 'unknown'] ?? $sourceColors['unknown'];
            $salary  = formatSalary($job['salary_min'] ?? null, $job['salary_max'] ?? null);
        ?>
        <div id="card-<?= $job['id'] ?>" class="job-card <?= $job['applied'] ? 'applied' : '' ?>">

            <div class="job-body">
                <!-- Meta row -->
                <div class="job-meta">
                    <!-- Source badge -->
                    <span class="badge badge-source"
                          style="background:<?= $srcMeta['bg'] ?>22; border:1px solid <?= $srcMeta['bg'] ?>44; color:<?= $srcMeta['bg'] ?>;">
                        <?= htmlspecialchars($srcMeta['label']) ?>
                    </span>

                    <?php if ($job['is_b2b']): ?>
                    <span class="badge badge-b2b">B2B / PJ</span>
                    <?php endif; ?>

                    <?php if ($job['applied']): ?>
                    <span class="badge badge-applied" id="badge-<?= $job['id'] ?>">✓ Applied</span>
                    <?php else: ?>
                    <span class="badge badge-applied" id="badge-<?= $job['id'] ?>" style="display:none;">✓ Applied</span>
                    <?php endif; ?>

                    <?php if (!empty($job['job_type'])): ?>
                    <span class="badge badge-type"><?= htmlspecialchars(str_replace('_', ' ', $job['job_type'])) ?></span>
                    <?php endif; ?>

                    <?php if (!empty($job['category'])): ?>
                    <span class="badge badge-cat"><?= htmlspecialchars($job['category']) ?></span>
                    <?php endif; ?>

                    <span class="date-label">📅 <?= date('d M Y', strtotime($job['posted_at'])) ?></span>
                </div>

                <!-- Title + company -->
                <div class="job-title"><?= htmlspecialchars($job['title']) ?></div>
                <div class="job-company">🏢 <span><?= htmlspecialchars($job['company']) ?></span></div>

                <!-- Description -->
                <div class="job-desc"><?= htmlspecialchars($job['description']) ?></div>

                <!-- Footer: salary + location -->
                <div class="job-footer">
                    <?php if ($salary): ?>
                    <span class="salary-badge">💰 <?= htmlspecialchars($salary) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($job['location'])): ?>
                    <span class="location-badge">📍 <?= htmlspecialchars($job['location']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Actions -->
            <div class="job-actions">
                <a href="<?= htmlspecialchars($job['link']) ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   onclick="markApplied(<?= $job['id'] ?>)"
                   class="btn-apply"
                   id="btn-<?= $job['id'] ?>">
                    Apply →
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div><!-- /wrapper -->

<script>
    function markApplied(id) {
        const fd = new FormData();
        fd.append('id', id);
        fetch('apply.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const card  = document.getElementById('card-' + id);
                    const badge = document.getElementById('badge-' + id);
                    if (card)  card.classList.add('applied');
                    if (badge) badge.style.display = '';
                }
            })
            .catch(console.error);
    }

    // Auto-submit on select change for snappy UX
    document.querySelectorAll('#filterForm select').forEach(el => {
        el.addEventListener('change', () => document.getElementById('filterForm').submit());
    });
</script>
</body>
</html>
