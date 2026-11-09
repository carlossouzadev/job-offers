<?php
// db.php - SQLite database connection and migration handler

$dbPath = __DIR__ . '/database.sqlite';
try {
    $pdo = new PDO("sqlite:$dbPath");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Criação da tabela se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS jobs (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        external_id TEXT UNIQUE,
        title       TEXT,
        company     TEXT,
        link        TEXT UNIQUE,
        description TEXT,
        category    TEXT,
        is_b2b      INTEGER DEFAULT 0,
        posted_at   DATETIME,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        applied     INTEGER DEFAULT 0,
        source      TEXT DEFAULT 'unknown',
        salary_min  INTEGER,
        salary_max  INTEGER,
        location    TEXT,
        job_type    TEXT
    )");

    // Migrations for existing installations (safe – no-op if column exists)
    $migrations = [
        "ALTER TABLE jobs ADD COLUMN source TEXT DEFAULT 'unknown'",
        "ALTER TABLE jobs ADD COLUMN salary_min INTEGER",
        "ALTER TABLE jobs ADD COLUMN salary_max INTEGER",
        "ALTER TABLE jobs ADD COLUMN location TEXT",
        "ALTER TABLE jobs ADD COLUMN job_type TEXT",
    ];
    foreach ($migrations as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* column already exists */ }
    }

} catch (PDOException $e) {
    die("Erro ao conectar com o banco de dados: " . $e->getMessage());
}