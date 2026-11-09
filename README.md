# 🚀 Remote & B2B Job Offers Tracker

A lightweight, self-hosted, zero-dependency remote job aggregator and dashboard. Automatically crawls multiple job boards, detects B2B/contract opportunities, filters duplicates, and provides a modern dark-theme dashboard to organize your job search.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-777bb4.svg?style=flat-square&logo=php)](https://www.php.net/)
[![Database](https://img.shields.io/badge/database-SQLite_3-003b57.svg?style=flat-square&logo=sqlite)](https://www.sqlite.org/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)
[![Zero Dependencies](https://img.shields.io/badge/dependencies-0-success.svg?style=flat-square)]()

---

## ✨ Features

- **🌐 Multi-Source Crawler:** Ingests remote job opportunities across multiple public job APIs.
- **💼 B2B / Contract Heuristic:** Automatically identifies contract, freelance, and B2B/PJ opportunities (`b2b`, `c2c`, `1099`, `contractor`, `freelance`, `pj`, etc.).
- **⚡ Zero External Dependencies:** Pure PHP + SQLite PDO. No Composer packages, Node.js, or complex build tools needed.
- **📊 Real-time Dashboard:**
  - Modern dark-mode interface designed with responsive CSS.
  - Source-specific pills and color badges.
  - Metrics cards (Total Jobs, B2B Count, Applied Count, Last Sync time).
  - Search by title, company, description, or category.
  - Filter by date range (7, 14, 30, or 60 days), B2B-only toggle, and job type.
- **✅ Application Tracker:** Mark jobs as "Applied" directly from the UI with real-time status updates via AJAX.
- **🛡️ Deduplication:** Prevents duplicate entries via unique constraints on `external_id` and job URL.
- **⏱️ Automation-Ready:** Clean CLI script designed for crontab or scheduled task runners.

---

## 📡 Supported Job Sources

| Source | Endpoint / API | Characteristics |
| :--- | :--- | :--- |
| **Remotive** | `https://remotive.com/api/remote-jobs` | Worldwide tech & software roles |
| **RemoteOK** | `https://remoteok.com/api` | Global remote roles with structured salary data |
| **Arbeitnow** | `https://www.arbeitnow.com/api/job-board-api` | European & international remote listings |
| **Jobicy** | `https://jobicy.com/api/v2/remote-jobs` | Global remote positions |

---

## 📁 Project Structure

```text
.
├── crawler.php        # CLI crawler fetching and parsing listings from APIs
├── db.php             # SQLite PDO connection and automatic schema migrations
├── index.php          # Main dashboard UI with filters, search, and metrics
├── apply.php          # AJAX endpoint to mark offers as "Applied"
├── .env.example       # Example environment configuration
├── .gitignore         # Ignores database, logs, and local environment files
├── LICENSE            # MIT License
└── README.md          # Project documentation
```

---

## 🛠️ Prerequisites

- **PHP 8.1+** with the following extensions enabled:
  - `pdo_sqlite`
  - `json`
  - `curl` or `allow_url_fopen` enabled in `php.ini`
- **SQLite 3**

---

## 🚀 Quick Start

### 1. Clone the repository
```bash
git clone https://github.com/carlossouzadev/job-offers.git
cd job-offers
```

### 2. Configure Environment (Optional)
```bash
cp .env.example .env
```

### 3. Run the Crawler
Fetch initial job offers into your local SQLite database:
```bash
php crawler.php
```
*The script will automatically create `database.sqlite` with the necessary schema if it does not already exist.*

### 4. Start the Local Server
```bash
php -S localhost:8000
```

### 5. Access the Dashboard
Open your browser and navigate to:
```
http://localhost:8000
```

---

## ⏰ Automated Crawling (Crontab)

To keep your job database automatically updated, you can configure a cron job to run the crawler periodically (e.g., every 30 minutes).

### Setting up on macOS / Linux:

1. Open your crontab editor:
   ```bash
   crontab -e
   ```

2. Add the following entry (adjust the path to your PHP binary and project directory):
   ```cron
   */30 * * * * /usr/bin/php /path/to/job-offers/crawler.php >> /path/to/job-offers/crawler_log.txt 2>&1
   ```

   > 💡 **Tip:** Run `which php` in your terminal to find the exact path to your PHP binary (especially if using Homebrew, Herd, or Docker).

---

## ⚙️ Customization

## ⚙️ Dynamic Keyword Search & Filtering

The project supports dynamic keyword filtering both in the **CLI crawler** and in the **Web Dashboard**:

### 1. In the CLI Crawler (`crawler.php`)
You no longer need to edit source code to filter jobs. You can pass keywords dynamically in three ways:

* **Via CLI arguments (flags or direct keywords):**
  ```bash
  # Using --keywords or -k flag
  php crawler.php --keywords="php,laravel,react"
  php crawler.php -k "python,fastapi,ai"

  # Or using positional arguments directly
  php crawler.php php laravel vue
  ```

* **Via `.env` file:**
  ```env
  KEYWORD_FILTER=php,laravel,backend,typescript,react
  ```

* **Via Environment Variable:**
  ```bash
  KEYWORD_FILTER="golang,kubernetes" php crawler.php
  ```

*Leaving keywords empty will crawl and save all jobs without filtering.*

---

### 2. In the Web Dashboard (`index.php`)
The dashboard search bar now features smart multi-keyword queries:
* **`OR` queries (Comma-separated):** Searching for `php, laravel, python` will find any job matching **at least one** of these technologies.
* **`AND` queries (Space-separated):** Searching for `senior php remote` will match jobs that contain **all** of these terms.
* **⚡ Quick Keyword Chips:** One-click quick filter buttons for popular tech stacks (`PHP`, `Laravel`, `Python`, `Node.js`, `React`, `TypeScript`, `Go`, `DevOps`, etc.).

### Adding New Sources
To add another job board, create a new crawl function in `crawler.php` following the signature:
```php
function crawlCustomSource(PDO $pdo, array $filter): array {
    // 1. Fetch JSON or XML
    // 2. Filter & format
    // 3. Call insertJob(...)
    // 4. Return [$totalProcessed, $newInserted]
}
```
Then register it in the `$sources` array at the bottom of `crawler.php`.

---

## 📄 License

This project is licensed under the [MIT License](LICENSE) - see the LICENSE file for details.

---

## 👤 Author

**Carlos Souza**
- GitHub: [@carlossouzadev](https://github.com/carlossouzadev)
