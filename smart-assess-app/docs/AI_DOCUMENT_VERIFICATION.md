# AI Document Verification

Status: implemented and tested locally. **Not deployed.** Nothing in this
document has been applied to AWS or production.

The AI check reads each uploaded document with OCR, compares it with the
request and the other documents, and gives Staff one of three results:

| Result | Meaning |
|---|---|
| **Passed** | The required documents appear present and readable, and no inconsistency was detected. This is **not** an approval or proof that a document is genuine. |
| **Needs review** | Possible inconsistency, unreadable or uncertain text, a document that does not look like its type, or the model could not give a valid answer. |
| **Missing information** | A required document was not uploaded, or a clearly readable document lacks a detail it should contain. A document that OCR could not read is never called missing. |

The result is advice for Staff only. It never approves, rejects or changes a
request, never sends messages, and is never shown to clients, Admin or the
Department Head. Staff always open the original documents and decide.

## Architecture

```
Client ──submit──▶ PHP app (web/api.php) ──same DB transaction──▶ requests, request_documents,
                         │                                        ai_verification_jobs (pending)
                         │  files stay in private_uploads/requests (outside the web root)
                         ▼
        bin/ai-verification-dispatch.php   (CLI on the PHP server, systemd timer; no web route)
          0. if a job is due: GET /v1/health on the worker; not ready -> exit, nothing claimed
          1. claims a due job (SELECT ... FOR UPDATE SKIP LOCKED, lease = timeout + 120 s)
          2. reads only that request's files, checks requirements with DocumentRequirements
          3. POSTs one signed job ──HTTPS, private network──▶ private AI worker (worker/index.php)
                                                                 ├─ OcrService (Tesseract + Poppler)
                                                                 ├─ rule checks (surname, ARP number)
                                                                 └─ Ollama on the worker's loopback
          4. verifies the signed answer, validates it strictly (DocumentAnalysis)
          5. decides the outcome itself and stores it in ai_verification_jobs
                         ▼
        Staff request details: "AI Document Check" panel next to the original files
```

* The worker has **no database access and no storage access**. It receives only
  the documents of one job, keeps them in a 0700 folder while it works, and
  deletes them. It returns structured findings, never the OCR text.
* Ollama listens only on the worker's loopback (`127.0.0.1:11434`). Port 11434
  is never opened.
* The worker accepts only requests signed with the shared secret (HMAC-SHA256
  over method, path, timestamp, nonce and body), within 5 minutes, and never the
  same nonce twice. Its answers are signed over that request's nonce, the HTTP
  status and the body.
* The model is told that document text is untrusted content, its output must
  match a strict JSON schema with no field that could approve or change
  anything, and the application recomputes the outcome from validated findings.
* The old Django prototype in `ai_checker/` is **not used** by the PHP
  application and is not part of this feature (it also has a 15 MB limit that
  does not apply here).

### Files

| File | Purpose |
|---|---|
| `database/migrations/20261111_ai_document_verification.sql` | Creates `ai_verification_jobs` (one job per request). |
| `database/migrations/20261114_request_document_views.sql` | Creates `request_document_views`: which Staff member opened which original (one row each). |
| `app/services/DocumentAnalysis.php` | Schema, strict validation, prompts, rule checks, outcome rules. No I/O. |
| `app/services/AiVerification.php` | Queue, job building, storing results, Staff view. |
| `app/services/AiWorkerClient.php` | Signed request to the worker; verifies the signed answer. |
| `app/services/WorkerSignature.php` | HMAC signing shared by both sides. |
| `app/services/AiDocumentWorker.php` | Worker: OCR, rules, model call with retries. |
| `app/services/AiWorkerHttp.php` | Worker HTTP handling: auth, replay, size limit, one job at a time. |
| `app/services/OllamaClient.php` | Ollama `/api/chat` with JSON-schema output, bounded. |
| `app/services/OcrService.php` | Existing OCR service (now with configurable languages). |
| `worker/index.php` | The worker's only web entry point. |
| `bin/ai-verification-dispatch.php` | Dispatcher (CLI). |
| `bin/ai-model-evaluation.php` | Compares local models on synthetic documents. |
| `deploy/systemd/smart-assess-ai-dispatch.service`, `.timer` | Dispatcher service and one-minute timer for the web server (not installed). |
| `deploy/systemd/ai-dispatch.env.example` | The dispatcher's own environment file (KEY=VALUE, root 0600). |
| `deploy/mysql/ai-dispatch-db-user.sql.example` | The dispatcher's least-privilege MySQL account (not applied). |
| `deploy/systemd/smart-assess-ai-dispatch-egress.service`, `deploy/nftables/ai-dispatch-egress.nft` | The dispatcher's egress filter: only 127.0.0.1:8095 (not installed). |
| `deploy/sbin/smart-assess-ai-dispatch-permissions.sh`, `deploy/systemd/smart-assess-ai-dispatch-permissions.service` | Read access for the dispatcher's group `sa-ai-read`, re-applied before every run (not installed). |
| `bin/ai-worker-compat-check.php` | Release-order check: is a worker copy compatible with the deployed application? |
| `deploy/systemd/worker-php-fpm-pool.conf.example` | Example php-fpm pool for the worker server, with its time limits. |

### Database

`ai_verification_jobs`: `status` (pending, processing, completed, failed,
needs_manual_review), `outcome`, `outcome_reason`, `findings_json`,
`document_checks_json`, `model_name`, `model_digest`, `ocr_engine`, attempts,
`transient_failures` (worker outages, which do not use attempts) and retry
time, claim token and lease, start/finish times, `error_code`, and Staff
review (`staff_review_status`, `reviewed_by`, `reviewed_at`). A unique key on
`request_id` prevents duplicate jobs. No OCR text is stored; a finding may quote
at most a 160-character evidence snippet. Rows are deleted with their request
(`ON DELETE CASCADE`). The migration only creates this table and is safe to
run more than once.

### Retries

Job states: a submission queues `pending`. The dispatcher claims it
(`processing`, leased for the HTTP timeout + 120 s) and the worker answers:

* Answer validated: `completed` (outcome stored).
* Model answer invalid after its tries: `needs_manual_review` (rule results kept).

Before claiming anything, each dispatcher run checks the worker's
`GET /v1/health`. The worker builds and validates all of its settings on every
request, and reports:

| Health answer | Meaning | Dispatcher |
|---|---|---|
| `{"ok":true}` | settings valid, state folder writable, OCR tools found, OCR languages installed (`tesseract --list-langs`), Ollama lists the configured model | claims jobs |
| `{"ok":false,"error":"not_ready"}` (or no answer) | temporary: e.g. Ollama not answering yet | exit 75, claims nothing |
| `{"ok":false,"error":"not_configured"}` | a worker setting is out of range or invalid, a tool or language is missing, Ollama lacks the model, or the state folder is not writable (the worker's log names which) | exit 3, claims nothing |
| `{"ok":false,"error":"cleanup_failed"}` | files left by an interrupted job could not be removed | exit 3, claims nothing |
| `{"ok":false,"error":"stuck"}` | a job has held the worker lock longer than `MAX_JOB_SECONDS` + 120 s (a hung php-fpm child) | exit 3, claims nothing |
| TLS error | certificate or `SMART_ASSESS_AI_WORKER_CA_FILE` wrong | exit 3, claims nothing |
| any other answer (a 200 that is not the worker's, 3xx, 4xx) | the URL does not reach the worker (wrong path, proxy page) | exit 3, claims nothing |

Waiting jobs keep all their attempts for as long as that lasts. The health
check is not trusted to guarantee anything: whatever happens after a job is
claimed follows these rules (`AiVerification::retryClass()` and `fail()`).
Unsigned answers can only come from the web server or proxy in front of the
worker, or from the worker before it could check the request, so they never
count against the job; a result is only used with a valid signature.

| Class | Error codes | Uses an attempt? | What happens |
|---|---|---|---|
| transient | `worker_unavailable` (cannot connect; unsigned 503; unsigned 409 replay; worker disk full), `worker_busy` | **no** | back to `pending`; delay 1, 2, 4, 8, then 15 minutes (a busy worker too, so a stuck one cannot keep a job waiting forever); the run stops (exit 75). After 300 such failures (about 3 days) the job fails as `worker_outage_too_long`. |
| crash | `worker_crashed` (connection dropped mid-job; unsigned 500 or 502: the PHP process died), `model_unavailable` (Ollama failed during this job) | **no** | back to `pending` after 2, then 4 minutes; the run stops (exit 75). After 3 crashes on the same request it fails as `worker_crashed_repeatedly`, so one request that kills the worker cannot loop for days. |
| configuration | `worker_auth_failed` (401: secret or clock), `worker_not_configured`, `worker_cleanup_failed`, `worker_tls_failed`, `worker_body_too_large` (unsigned 413: raise nginx `client_max_body_size`), `worker_proxy_error` (other unsigned answers) | **no** | back to `pending`, due again after 1 minute without limit, so **every** dispatcher run reports it (exit code 3, visible in `systemctl status`) until it is fixed, and the job runs within a minute of the fix. |
| counted | `worker_bad_response` (a signature that does not verify, too large, not JSON), `invalid_worker_result`, `worker_error`, `worker_timeout` (connected, no answer in time; unsigned 504), `model_timeout`, `lease_expired` | yes | back to `pending` after 1, 2 minutes; `failed` after `max_attempts` (3). These could be caused by the request itself, so they must not loop forever. |
| permanent | `worker_rejected` (the worker refused the job), `job_build_failed` | — | `failed` at once. |

### Privacy Policy effective date

The AI check runs only for requests submitted **on or after** the date the
reviewed Privacy Policy that describes it took effect. The date is set, never
built in, as `SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT` in the format
`YYYY-MM-DDTHH:MM:SS+08:00` (any offset, or `Z`), compared with
`requests.created_at` at second precision (a request submitted exactly at that
second is checked).

| Where | What it does | Unset or invalid |
|---|---|---|
| Website (`apache.env`) | queues a job at submission only for an eligible request; the Staff action `ai_verification_requeue` refuses an older request (HTTP 409) | nothing is queued; requeue answers HTTP 503 |
| Dispatcher (`ai-dispatch.env`) | claims only jobs of eligible requests (`AiVerification::claim()`/`hasDueJob()` join `requests`) | refuses to run, exit code 2 |
| Staff page | shows "Not checked by AI ... Inspect its documents manually" and no Run button | "not enabled" |

Both servers' values must be identical. The website records on each job the
value it used (`privacy_effective_from`); the dispatcher refuses to run (exit
code 3, both dates in the message) while any waiting job carries a different
value or none, claims nothing and changes nothing, and claims only jobs whose
recorded value equals its own. After the settings are corrected, jobs recorded
under the old value keep blocking the dispatcher: Staff can run an eligible
one again (which records the current value), but a request that is no longer
eligible cannot be run again, so an operator must retire such jobs as below. The Privacy Policy's "Automatic
document check" section prints the website's configured value itself, separate
from the page's "Last updated" date; unset or invalid, it says the check is not
enabled. Jobs of older requests that already exist (for
example queued before the date was set) are **not deleted or changed**: they
are simply never claimed, and their request statuses are untouched. Admin and
Department Head pages receive no eligibility data.

### Recovering from a corrected effective date

> **Warning.** This marks waiting AI checks as failed. Use it only after you have
> **independently verified** `SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT` in **both**
> `/etc/smart-assess/apache.env` (website) and `/etc/smart-assess/ai-dispatch.env`
> (dispatcher), corrected whichever was wrong (with approval), and the dispatcher
> still exits 3 ("waiting jobs were queued with ..."). If the dispatcher's own
> value is the wrong one, fix it instead: running this would stop correctly dated
> checks. The command refuses to run unless the website value you type equals the
> dispatcher's value, but it cannot tell which of two equal values is right.

It is a command-line maintenance option of the dispatcher, run by an operator on
the web server; there is no page or API for it, and the timer never runs it.

1. Read the website's value yourself (it is not secret):

   ```sh
   sudo grep -n 'SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT' /etc/smart-assess/apache.env /etc/smart-assess/ai-dispatch.env
   ```

   Both lines must show the same confirmed value, e.g. in the form
   `YYYY-MM-DDTHH:MM:SS+08:00`. Copy the website's value exactly.
2. Preview (read only; changes nothing):

   ```sh
   sudo systemd-run --uid=www-data --gid=www-data --pipe --wait --collect \
     --property=EnvironmentFile=/etc/smart-assess/ai-dispatch.env \
     /usr/bin/php /var/www/smart-assess/bin/ai-verification-dispatch.php \
     --retire-mismatched --website-date='<value from apache.env>'
   ```

   (`systemd-run` reads the settings file as root and runs the command as
   `www-data`, like the timer, without putting any setting on the command line.)

   It first prints both dates. If they differ it stops: "REFUSED", exit 3,
   nothing shown as retirable, nothing changed. Otherwise it lists the recorded
   dates with counts, each affected job ID with its status and recorded date,
   which jobs are still being processed (left alone), and the **complete**
   confirmation command with its code (e.g. `--confirm=3-1a2b3c4d5e6f`).
3. Check the list. Every job shown (except those being processed) will be marked
   failed; correctly dated jobs are not listed and are never touched.
4. With approval, run the confirmation command exactly as the preview printed it
   (it repeats `--website-date`). Keep the printed summary with the change record.
5. Run the dispatcher normally (or wait for the timer). If jobs were still being
   processed, the preview says "No job can be retired now ...": repeat steps 2-4
   after their leases end (about 17 minutes with the default timeout).

What it does and guarantees:

* `--website-date` is required for the preview and the confirmation, is checked
  with the same strict rules as the application, and must equal the
  dispatcher's configured value; otherwise nothing is retired (exit 3; exit 2 if
  it is missing or invalid). Configuration is never changed by the command.
* Only pending or processing jobs whose recorded date differs from that value,
  or is missing, are changed: `status = 'failed'`, `error_code =
  'effective_date_changed'`, and `outcome_reason` records when, the recorded
  date, the configured date, and systemd's `INVOCATION_ID` of the run. Jobs are
  never deleted. Requests, their status and `request_status_log` are never
  touched; nothing is sent to anyone.
* Who ran it: the reliable record is **sudo's own log** (`journalctl _COMM=sudo`
  or `/var/log/auth.log`), which shows the operator's login name and the full
  `systemd-run` command; the `INVOCATION_ID` in `outcome_reason` matches the
  run in the journal (`journalctl _SYSTEMD_INVOCATION_ID=<id>`). The command does
  not take an operator name, so nothing self-reported is recorded.
* A job a dispatcher is still processing (lease not expired) is never changed,
  including one claimed while the confirmation waits for its row locks. A
  dispatcher that still holds an old claim token afterwards cannot change the job
  any more (all its writes need that token).
* The confirmation code covers exactly the job IDs, their statuses and recorded
  dates, the dispatcher's date and the website date given. Inside one locked
  transaction the dates and the code are checked again; if anything changed
  since the preview, or the code is wrong, nothing changes and it exits 1.
* It refuses to run (exit 2) without a valid configured effective date.
* Staff then see "This check was stopped by an administrator ...". If the
  request is eligible under the configured date, Staff can run the check again.

### Interrupted jobs and leftover files

Each job's files (the documents and the pages rendered from PDFs) live only in
the worker's `job-<32 hex>` folder inside `SMART_ASSESS_WORKER_STATE_DIR`; OCR
temporary folders are created inside it too. A finished or failed job removes
its folder. If the process is killed (php-fpm `request_terminate_timeout`, out
of memory, a reboot), that cleanup never runs, so `WorkerStateCleaner` removes
such folders:

* only while holding the worker lock (`worker.lock`), which a running job
  always holds, so it can never remove a running job's folder; folders changed
  in the last 60 seconds are also left alone;
* at the start of every job and on every health check that finds no job
  running (the dispatcher checks health before each run that has work);
* only direct children named `job-` + 32 hex characters; links are never
  followed (a link named like a job folder is skipped, a link inside one is
  removed as a link only) and every folder must resolve inside the state folder.

If something cannot be removed (for example wrong permissions), the worker
logs the count, reports `cleanup_failed` and takes no jobs until an
administrator fixes it, so leftover documents are not silently kept.

Duplicate protection is unchanged: one row per request (unique key), claims
with `FOR UPDATE SKIP LOCKED`, results and failures written only by the
dispatcher holding the job's claim token, one job at a time on the worker, and
a running check cannot be restarted. Staff can run any finished or failed check
again; that resets attempts and the outage counter.

### Time limits

All limits line up so that a valid job that takes long is neither cut off by
the dispatcher nor claimed a second time while the worker is still on it:

| Limit | Default | Where | Rule |
|---|---|---|---|
| Dispatcher HTTP timeout `T` | 900 s | `SMART_ASSESS_AI_WORKER_TIMEOUT` (60-1800) | |
| Worker budget `B` | 780 s | sent with each job: `T - min(120, T/4)` | the worker answers within `B` |
| Worker maximum | 900 s | `SMART_ASSESS_WORKER_MAX_JOB_SECONDS` | `B` is never more than this |
| Model reserve | 180 s | `min(OLLAMA_TIMEOUT, B/2)` | kept back for the model while OCR runs |
| OCR per document | 90 s | `min(OCR_TOTAL_TIMEOUT, time left before the reserve)` | |
| Model call | 180 s | `min(OLLAMA_TIMEOUT, time left)`; a second try only if 30 s are left | |
| Lease | 1020 s | `T + 120` | longer than `T`, so no second claim |
| php-fpm / nginx on the worker | 1000 s | `request_terminate_timeout`, `fastcgi_read_timeout` | longer than the worker maximum |
| Dispatcher run | 1800 s + one job | `--max-seconds=1800`, `TimeoutStartSec=3000` | |

When OCR runs out of its share, the remaining documents are reported as "could
not be read automatically (time budget exceeded)" and the check ends as Needs
review, so Staff look at them; the worker still answers in time. The old worst
case (10 documents × 90 s OCR + 2 × 180 s model = 1260 s against a 600 s
timeout) can no longer happen. The 120 s margin between `B` and `T` covers
sending up to 70 MB to the worker. Measure real job times on the chosen worker
instance before going live; if typical jobs come close to `B`, raise `T` (and
`SMART_ASSESS_WORKER_MAX_JOB_SECONDS`, php-fpm and nginx) together.

## Local setup (macOS, as tested)

Requirements: PHP 8.4+ with `curl`, `fileinfo`, `gd` (tests only), `json`,
`mbstring`, `pdo_mysql`; MySQL 8; Tesseract 5; Poppler; Ollama.

```sh
brew install tesseract poppler ollama
ollama pull gemma2:9b          # or llama3.2:3b; see "Choosing a model"
mysql -u <user> -p <database> < database/migrations/20261111_ai_document_verification.sql
```

Run the worker (separate terminal; local development only):

```sh
mkdir -m 700 /path/to/worker-state
SMART_ASSESS_WORKER_SECRET='<at least 32 random characters>' \
SMART_ASSESS_WORKER_STATE_DIR=/path/to/worker-state \
SMART_ASSESS_WORKER_OLLAMA_MODEL='gemma2:9b' \
SMART_ASSESS_WORKER_TESSERACT_PATH=/opt/homebrew/bin/tesseract \
SMART_ASSESS_WORKER_PDFINFO_PATH=/opt/homebrew/bin/pdfinfo \
SMART_ASSESS_WORKER_PDFTOTEXT_PATH=/opt/homebrew/bin/pdftotext \
SMART_ASSESS_WORKER_PDFTOPPM_PATH=/opt/homebrew/bin/pdftoppm \
php -S 127.0.0.1:8095 -t worker
```

Process the queue (on the app side, with the app's database settings; DB_HOST,
DB_PORT, DB_NAME and DB_USER must be set explicitly):

```sh
DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=<database> DB_USER=<user> SMART_ASSESS_DB_PASS='<password>' \
SMART_ASSESS_AI_WORKER_URL=http://127.0.0.1:8095 \
SMART_ASSESS_AI_WORKER_SECRET='<the same secret>' \
php bin/ai-verification-dispatch.php --limit=10
```

Plain HTTP is accepted only for loopback; anything else must be HTTPS.

### Configuration

Application (PHP server; dispatcher):

| Variable | Default | Notes |
|---|---|---|
| `SMART_ASSESS_AI_WORKER_URL` | (required) | `https://<worker private address>:<port>` |
| `SMART_ASSESS_AI_WORKER_SECRET` | (required) | ≥ 32 characters; same value on the worker |
| `SMART_ASSESS_AI_WORKER_TIMEOUT` | 900 | seconds per job (60-1800); sets the worker budget and the lease, see "Time limits" |
| `SMART_ASSESS_AI_WORKER_CA_FILE` | (none) | absolute path to the private CA certificate that signed the worker's certificate; trusted only for this connection |
| `SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT` | (required; no default) | the confirmed Privacy Policy effective date and time, `YYYY-MM-DDTHH:MM:SS+08:00`; set the same value for the website **and** the dispatcher |

Worker:

| Variable | Default | Notes |
|---|---|---|
| `SMART_ASSESS_WORKER_SECRET` | (required) | |
| `SMART_ASSESS_WORKER_STATE_DIR` | (required) | private 0700 folder for nonces and job files |
| `SMART_ASSESS_WORKER_OLLAMA_MODEL` | (required) | no built-in default; exactly as `ollama list` shows it, or the health check reports not ready |
| `SMART_ASSESS_WORKER_MAX_JOB_SECONDS` | 900 | longest a job may run (30-1800), whatever the application asks |
| `SMART_ASSESS_WORKER_OLLAMA_URL` | `http://127.0.0.1:11434` | loopback, or HTTPS |
| `SMART_ASSESS_WORKER_OLLAMA_TIMEOUT` | 180 | seconds |
| `SMART_ASSESS_WORKER_OLLAMA_CONTEXT_TOKENS` | 8192 | Ollama's default (2048) is too small for several documents |
| `SMART_ASSESS_WORKER_OLLAMA_MAX_OUTPUT_TOKENS` | 1500 | |
| `SMART_ASSESS_WORKER_OLLAMA_MAX_RESPONSE_BYTES` | 262144 | |
| `SMART_ASSESS_WORKER_MAX_INPUT_CHARS` | 16000 | OCR text sent to the model, shared across documents |
| `SMART_ASSESS_WORKER_MAX_BODY_BYTES` | 78643200 | 10 documents × 5 MB, base64 |
| `SMART_ASSESS_WORKER_OCR_LANGUAGES` | `eng` | e.g. `eng+tgl` once installed |
| `SMART_ASSESS_WORKER_OCR_PROCESS_TIMEOUT` | 30 | per program |
| `SMART_ASSESS_WORKER_OCR_TOTAL_TIMEOUT` | 90 | per document |
| `SMART_ASSESS_WORKER_TESSERACT_PATH`, `..._PDFINFO_PATH`, `..._PDFTOTEXT_PATH`, `..._PDFTOPPM_PATH` | `/usr/bin` or `/usr/local/bin` | PATH is never searched |

### Tagalog OCR

Only English (`eng`) language data is installed locally. Filipino documents and
mixed Filipino/English text are read less reliably, and names with accents may
be misread. To add Tagalog safely:

1. Download `tgl.traineddata` from the official `tesseract-ocr/tessdata_best`
   (or `tessdata_fast`) repository, pinned to a release tag; check its SHA-256
   against the published file.
2. Install it read-only next to `eng.traineddata` (owned by root, mode 0644).
3. Run `tesseract --list-langs` and the OCR tests, then set
   `SMART_ASSESS_WORKER_OCR_LANGUAGES=eng+tgl` and compare results on synthetic
   Filipino documents before using it in production.

### Choosing a model

`gemma2:9b` and `llama3.2:3b` are text models; they never see the images, only
OCR text. Run `php bin/ai-model-evaluation.php --models=gemma2:9b,llama3.2:3b
--tools-dir=/opt/homebrew/bin` and compare **outcomes**, not explanations. The
rule checks (missing documents, unreadable text, surname, ARP number) work even
when the model is wrong; the model adds cross-document and document-type checks.

## Tests

```sh
php tests/ai_document_analysis_test.php                     # no database
php tests/ai_dispatch_deploy_test.php                       # no database: dispatcher unit, settings, grants
SMART_ASSESS_TEST_DB=<name>_test DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=<user> \
SMART_ASSESS_DB_PASS='<password>' \
SMART_ASSESS_TEST_DB_ADMIN_USER=<scratch admin> SMART_ASSESS_TEST_DB_ADMIN_PASS='<password>' \
php tests/ai_verification_workflow_test.php
php tests/ocr_service_test.php
```

The workflow test also forces real InnoDB deadlocks (it needs the TRIGGER privilege
on the test database, and on a server with binary logging
`log_bin_trust_function_creators=1`). With the optional scratch administrator it
creates and drops the dispatcher's least-privilege MySQL account to test the shipped
grants; without it, those checks are reported as SKIP.

The workflow test runs a real submission, the dispatcher and the worker against
fake OCR programs and a fake Ollama, and checks Staff/Admin/Head access. It uses
only a database whose name ends in `_test`. The test database user needs a
password, because PHP's built-in server drops empty environment variables.

## Troubleshooting

| Symptom (Staff panel / dispatcher output) | Check |
|---|---|
| "AI document checking is not set up" | Run the migration. |
| Jobs stay "Waiting in the queue" | Is the dispatcher timer running? `systemctl list-timers smart-assess-ai-dispatch.timer`; last run: `journalctl -u smart-assess-ai-dispatch -n 20` |
| Dispatcher exit code 75, "not ready" | Worker down, unreachable, or Ollama without the configured model. Jobs wait without using attempts. `curl --cacert <ca> https://<worker>:8443/v1/health` from the web server. |
| Dispatcher exit code 3 | A worker-side setting: the message names it. Secret or clocks (`timedatectl`); the worker's log line `[SMART_ASSESS_WORKER] Not configured: ...`; certificate and CA file; nginx `client_max_body_size` and the worker URL; leftover job folders the worker cannot remove (permissions on `SMART_ASSESS_WORKER_STATE_DIR`). |
| "stopped ... times while checking this request" | The same request made the worker or Ollama stop 3 times: inspect it by hand; check the worker's memory (`journalctl -u php*-fpm -u ollama`). |
| Dispatcher exit code 3, "waiting jobs were queued with ..." | The website and dispatcher effective dates differ, or differed when those jobs were queued. Make them identical; then see "Recovering from a corrected effective date". |
| `--retire-mismatched` exit code 1 | The jobs changed since the preview, or the code was mistyped: preview again. Nothing was changed. |
| `--retire-mismatched` exit code 3, "REFUSED" | The `--website-date` you typed differs from the dispatcher's value: verify both settings files and correct the wrong one first. Nothing was changed. |
| Dispatcher exit code 2 | A setting is missing in `/etc/smart-assess/ai-dispatch.env` (the message names it), including `SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT`. |
| "Not checked by AI: ... submitted before the Privacy Policy" | Expected for requests older than `SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT`; Staff inspect them by hand. "Not enabled": the website lacks the setting. |
| Exit code 3, "held the AI worker's lock" | A worker job is stuck: `systemctl restart php*-fpm` on the worker; the next health check removes its folder. |
| "could not be reached" | Worker running? Security group allows the app server to the worker port? HTTPS certificate trusted by the app server? |
| "took too long" / "did not answer in time" | Job times against the limits in "Time limits"; a smaller model or more CPU. |
| "shared secret is not set up correctly" | Same `..._SECRET` on both sides; clocks within 5 minutes (use chrony/NTP). |
| "AI model ... was not available" | `systemctl status ollama` on the worker; `ollama list` shows the configured model. |
| "did not give a valid answer" | Model too small or context too short; raise `..._CONTEXT_TOKENS`, try another model. |
| Every document "could not be read" | Tool paths, `tesseract --list-langs`, OCR timeouts. |

## Privacy and security

* Only Staff (role 2) receive AI results, through the Staff-only branch of
  `sa_internal_bootstrap()`; Admin and Department Head pages and the direct
  `internal_bootstrap` API never contain them. The Staff actions
  `ai_verification_requeue` and `ai_verification_reviewed` require role 2.
* There is no public AI endpoint. The dispatcher is command-line only. The
  worker is on a private network and refuses unsigned, stale or replayed jobs.
* Documents are never sent to external AI services.
* All AI text is escaped before display. Model output that does not match the
  schema exactly is discarded.
* Logs contain job IDs and error codes only, never document text, names,
  numbers or references.
* The public Privacy Policy (`web/privacy-policy.php`) describes the automatic
  check: what is sent, where it runs, what is kept, who sees it, and that Staff
  decide. See "Privacy Policy review" below before publishing it.
* Known limits: OCR is English only; a "Passed" result can be wrong; the model
  can miss inconsistencies; signatures, seals, notarization and ownership are
  never verified; the required-document list stored with a submission comes
  from the client form, so the check recomputes requirements on the server with
  `DocumentRequirements`.

### Staff decide (reviewed in Steps 6–10, 14 Nov 2026)

* **Nothing the model writes in its own words reaches Staff.** The model's
  answer must match the schema exactly (no field can approve, reject or change
  anything). Its free-text fields (a finding's `explanation`, a document's
  `note`) are still validated, then discarded. Every model finding Staff see is
  worded by the application from validated codes only (category, field,
  document labels) and starts with "AI suggestion (unverified):" or "Cannot be
  checked by the AI:" (`DocumentAnalysis::classify()`). So no phrasing,
  language, spelling trick, hidden character or look-alike letter can make the
  AI say that a signature, seal or document is genuine, or that a request
  should be approved, rejected or released. (Step 6 tried a word list instead;
  the Step 7 review showed synonyms and obfuscation got past it, and it was
  removed.)
* **Evidence is an exact quote or nothing.** A model finding may carry a short
  quote ("evidence"). The worker, the only place that has the OCR text, keeps it
  only if it occurs in the text of the finding's document (compared with
  whitespace and invisible characters reduced to single spaces); anything
  invented, paraphrased or taken from another document is removed
  (`DocumentAnalysis::keepQuotedEvidence()`). Such workers answer with result
  version 2. For a version 1 answer (the copy installed in `/opt` before this
  change) the application cannot check quotes, so it drops all model evidence.
  A quote is also dropped (by the worker and again by the application, for any
  worker version) when it is shorter than 12 characters or 3 words, or belongs to
  a "cannot verify" finding (`DocumentAnalysis::evidenceAllowed()`), so a single
  cherry-picked word such as "GENUINE" or "APPROVED" can never stand next to a
  finding. Staff see a kept quote labelled as text read from the document by OCR,
  which may contain reading errors. A longer real quote can still contain such
  words if the document says them ("APPROVED BY THE REGISTRAR"); it is shown as
  the document's text, not as the AI's judgement.
* **Missing details only from a fixed list.** The model may name missing details
  only as codes from `DocumentAnalysis::MISSING_FIELD_LABELS` (for example
  `date_of_birth`, `arp_number`); Staff see the application's labels. Any other
  name is dropped.
* **"Passed" is never the model's to give.** It needs every server check to be
  clean (every required document uploaded, every document read clearly — a
  partly read document is a reading problem — and no rule finding) and the model
  to have reviewed every document and reported nothing, including nothing that
  "cannot be checked" from text (that needs Staff, so it is **Needs review**).
  The Passed text says it does not verify that any document, signature or seal
  is genuine. A model answer that is merely valid JSON cannot produce it: only
  the absence of every concern can. "Needs review" names each reason that applies
  (counts of inconsistencies, reading problems, possibly missing details and
  points the AI cannot check, the AI's manual-review suggestion, or a model that
  did not review the documents). In the Staff panel every model judgement (the
  findings, the "looks like this document?" column, the outcome banner) is
  labelled "AI suggestion (unverified)". Limitation: a model that wrongly says a
  document is the right type, with every server check clean, still gives Passed.
* **Originals before "reviewed".** Each time a Staff member opens, previews or
  downloads an original (`web/request-file.php`), one row per Staff member and
  document is kept in `request_document_views` (migration
  `20261114_request_document_views.sql`; first and last opening, count; no
  content, path or applicant details). Not in `audit_log`, so security events
  are never crowded out of the Admin's audit view. The AI result can be marked
  **reviewed** only by a Staff member who has opened every original **that the
  application can serve**; originals missing from storage or of an unsupported
  type are listed as "Cannot be opened" on the Staff page and in the 409 answer,
  and never block the review (`AiVerification::originalFile()` is the one check
  used by the file page, the gate and the Staff page). The Staff page checks a
  file's type only for requests whose AI check is finished and not yet reviewed
  (where the review depends on it); for the others its "can be opened" hint
  checks the stored path, the uploads folder, presence and readability (the type
  was checked at upload). Opening a file and the review gate always check fully.
  Until the migration has run, "Mark as reviewed" answers that the opening record
  is not set up.
* **Limitation: opened is not inspected.** A row shows that the file was sent to
  that Staff member's browser, not that they looked at it, read it or
  understood it. The gate makes skipping the originals deliberate; it cannot
  prove a careful review. Approving, rejecting or releasing a request is a
  separate Staff action that never reads or depends on the AI result, and the
  system does not require the originals to be opened before that decision.
* **Nothing else is written or sent.** The dispatcher and the AI processing write
  only `ai_verification_jobs`; Staff AI actions add audit entries and opening
  rows. No AI path changes a request, its status history or notifications, or
  calls the SMS or email senders (the workflow test checks every other table
  byte for byte and records any send attempt).
* **A submission never falsely succeeds.** The AI queue insert and the
  confirmation queue each run behind a savepoint, so an ordinary failure undoes
  only that insert and the request is saved without it. If the database rolled
  the whole transaction back (a deadlock, or a lock timeout with
  `innodb_rollback_on_timeout` on; production has it off), the request is gone,
  so the submission fails with an error and its uploaded files are removed, and
  a last check after the commit confirms the request exists before success is
  returned (`sa_submission_still_saved()`). The workflow test forces a real
  InnoDB deadlock in each queue to prove it. If the commit itself fails (for
  example the connection dropped), its outcome is uncertain, so a fresh
  connection checks whether the request was saved (`sa_commit_submission()`):
  saved means success and the files stay; not saved means the usual clean-up;
  if even that check fails, the submission is reported as failed but no uploaded
  file is deleted (a saved request never loses its documents). A failing
  rollback (lost connection) is logged and never skips the clean-up.
  `tests/submission_commit_recovery_test.php` covers these outcomes; the
  workflow test kills a submission's database connection mid-transaction.
* **Access.** Staff (role 2) only, checked on the server for every AI action,
  every page and the direct `internal_bootstrap` API. Admin, Department Head,
  signed-in clients and visitors get 401/403 and no AI data; role fields sent by
  a client are ignored; AI actions require POST and the CSRF token. Request
  tracking never shows AI results.
* **Dispatcher account and sandbox** (`deploy/systemd/smart-assess-ai-dispatch.service`,
  not installed; tested in an isolated Ubuntu 26.04 VM).
  * Its own non-login account `sa-ai-dispatch`, not www-data, so Apache
    processes cannot read its environment (worker secret, its database password).
  * **Files.** It is not in the www-data group. Its own group `sa-ai-read` gets read
    access (POSIX ACLs; owners, groups and modes are unchanged) to exactly
    `bin/ai-verification-dispatch.php`, `web/bootstrap.php`, `app/services/*.php`
    and `private_uploads/requests` (plus pass-through on the folders above them).
    `deploy/sbin/smart-assess-ai-dispatch-permissions.sh` sets this; the unit
    `smart-assess-ai-dispatch-permissions.service` (a root oneshot outside the
    dispatcher's read-only sandbox, required by the dispatcher) runs it again
    before every dispatcher run, because each deployment
    replaces the code files (copied through a temporary folder and renamed, with
    `ubuntu:www-data 0640`), which removes such entries; uploads get a default
    entry for new files. Inside the sandbox `/var/www` is an empty read-only
    folder with only those paths mounted in. Needs the `acl` package.
  * **Hidden:** all of `/etc/smart-assess` (the website settings, the website's
    database login in `database.env`, Gmail; the dispatcher's own settings file is
    read by systemd before the sandbox starts), backups, the worker's secret and
    state, the GitHub runner, MySQL's data and maintenance login.
  * **Network:** the egress filter (`deploy/nftables/ai-dispatch-egress.nft`,
    unit `smart-assess-ai-dispatch-egress.service`, required by the dispatcher)
    allows the `sa-ai-dispatch` account only TCP to 127.0.0.1:8095. DNS
    (127.0.0.53/54), MySQL over TCP (3306) and X (33060), Ollama (11434), Apache,
    other addresses and IPv6 are rejected, and the resolver's local sockets and
    D-Bus are hidden, so names cannot be resolved by any route. MySQL is reached
    through its local socket (`DB_HOST=localhost`). `IPAddressDeny=any` /
    `IPAddressAllow=localhost` stay as a second layer.
  * **Database:** its own MySQL account (`deploy/mysql/ai-dispatch-db-user.sql.example`)
    reads only nine columns of `requests` (no contact details), eight of
    `request_documents`, and reads and updates `ai_verification_jobs`; the
    workflow test runs the dispatcher with exactly these grants and shows 13
    forbidden actions are refused.
  * `tests/ai_dispatch_deploy_test.php` checks the unit, the filter, the
    permissions script and the release-order check; `systemd-analyze verify`
    must still be run on the server before installing.

### Release order (application first, then the worker)

Worker answers carry a result version. A refreshed worker answers version 2 (it
checks the model's quotes against the OCR text); the application accepts
version 1 and 2 from this branch on, but release 6482fcd0 accepts version 1 only
and would fail every job as an invalid worker result. Therefore:

1. Deploy the application release that contains this change.
2. Before refreshing `/opt/smart-assess-ai-worker` (or installing or starting the
   dispatcher), stage the new worker copy and run, on the server:
   `php /var/www/smart-assess/bin/ai-worker-compat-check.php --app=/var/www/smart-assess --worker=<staged copy>`
   It must print "compatible" (exit 0). Exit 1 means "NOT COMPATIBLE: deploy the
   application first"; exit 2 means the files could not be read. Do not continue
   on anything but exit 0.
3. Refresh the worker copy, then run the same check with `--worker=/opt/smart-assess-ai-worker`.

The check reads source files only. A new application with the worker installed
in Step 4 (version 1) is compatible: model quotes are then simply dropped.

### Production worker as installed (Steps 4 and 5, 13–14 Nov 2026)

The production worker differs from the separate-server plan below: it runs on
the web server itself (Free plan, no second server), reachable only on
loopback.

| Part | As installed |
|---|---|
| Worker URL for the dispatcher | `http://127.0.0.1:8095` (plain HTTP is accepted only on loopback; no CA file; see `deploy/systemd/ai-dispatch.env.example`) |
| HTTP front | lighttpd, 127.0.0.1:8095 only, unit `smart-assess-ai-worker.service`, account `sa-ai-worker` |
| PHP | PHP-FPM 8.5 pool, unit `smart-assess-ai-worker-fpm.service`, private socket, no database drivers loaded |
| Network | nft table `inet sa_ai_worker_egress` (unit `smart-assess-ai-worker-egress.service`): the worker account may reach only Ollama on 127.0.0.1:11434; MySQL, DNS, metadata and the internet are rejected |
| Limits | slice `smart-assess-ai-worker.slice` (1 CPU, 1 GiB) inside `smart-assess-ai.slice` (4.5 GiB with Ollama) |
| Secret | `/etc/smart-assess-ai-worker/worker.env`, root 0600 |
| Code | a copy of `worker/` and `app/services/` in `/opt/smart-assess-ai-worker`, refreshed by hand when a release changes them. The copy installed in Step 4 answers result version 1, so until it is refreshed the application drops all model evidence |

AI checking stays off until the dispatcher, its settings file and the website's
`SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT` are set up, each with separate approval.

## Production deployment (not done; needs explicit approval)

Nothing below has been applied. Every step needs approval, a backup first and
the rollback named in the sequence at the end.

### Architecture

| Server | Runs | Holds |
|---|---|---|
| **Web server** (existing Sydney EC2, ap-southeast-2) | Apache + PHP website, Staff dashboard, MySQL (`smart_assess_sydney`), SMS and Gmail, **the dispatcher** (systemd timer) | the database, all uploads (`private_uploads/`), Gmail and SMS configuration |
| **AI worker** (new EC2, same Region, private subnet) | nginx + php-fpm serving `worker/index.php`, Tesseract, Poppler, Ollama on loopback | nothing permanent: each job's files live in a 0700 folder only while it runs |

The web server starts every connection; the worker never connects to the web
server, the database or any storage. A client submission queues a job in the
same transaction as the request, so it never waits for the AI; if the worker is
down, jobs wait (see "Retries") and submissions are unaffected.

### AWS resources (in the project's selected Region, ap-southeast-2)

* **Worker instance**: separate EC2 instance in a **private subnet** (no public
  IP). Size for the model: `gemma2:9b` needs about 10 GB RAM (more with a large
  context); a GPU instance is optional. Encrypted EBS volume (nginx may buffer
  large request bodies to disk while a job is received).
* **Security groups**:
  * worker SG inbound: worker HTTPS port (e.g. 8443) **only from the web
    server's security group**. No SSH from the internet (use SSM Session
    Manager). **Never** allow 11434.
  * worker SG outbound: only what updates need (or VPC endpoints/NAT).
  * web server SG outbound: allow the worker port to the worker SG.
* **IAM**: instance role with `AmazonSSMManagedInstanceCore` only. The worker
  needs no S3, database or Secrets access unless the secret is stored there.
* **Secret**: generate the shared secret once (`openssl rand -hex 32`), store it
  in AWS Secrets Manager or SSM Parameter Store (SecureString), and put it in
  the two root-only configuration files below. Never commit it.
* **Cost**: the worker is a second, larger instance and is billed while it runs.
  Check the project's spend limit in AWS Settings > Billing before creating it.

### Worker host

1. Install PHP (cli/fpm, curl, mbstring, fileinfo), Tesseract, Poppler,
   Ollama; `ollama pull <model>`; Ollama bound to `127.0.0.1`.
2. Copy `worker/` and `app/services/` only (not the web app, not uploads).
3. Serve `worker/` with nginx + php-fpm over HTTPS on the private address (TLS
   from a private CA or ACM Private CA; give the web server its CA with
   `SMART_ASSESS_AI_WORKER_CA_FILE`). nginx: `client_max_body_size 80m;`,
   `fastcgi_read_timeout 1000s;`.
4. php-fpm pool: start from `deploy/systemd/worker-php-fpm-pool.conf.example`
   (two children so health answers while a job runs; time limits longer than
   `SMART_ASSESS_WORKER_MAX_JOB_SECONDS`; the worker settings as `env[...]`).
5. systemd: php-fpm, nginx and ollama with `Restart=on-failure`;
   `MemoryMax`/`CPUQuota` on ollama; state directory owned by the PHP user,
   mode 0700. Clocks synchronized (chrony; the signature allows 5 minutes).
6. Log rotation; logs contain no document content.

### What must be deployed (read before changing the deploy script)

The web application depends on **the entire `smart-assess-app/app/`
directory**, not only `web/`: `web/bootstrap.php` loads
`app/services/AiVerification.php` (which loads `DocumentRequirements.php` and
`DocumentAnalysis.php`), and `web/api.php` uses `DocumentRequirements` for every
upload. A deploy that copies only `web/` makes **every page fail with HTTP 500**.

* Web server: deploy `smart-assess-app/app/`, `web/`, `bin/`, `database/` and
  `deploy/` together, from the same commit. `worker/` is not needed there; if it
  is copied, it is outside the document root and has no URL.
* The Apache document root must be `/var/www/smart-assess/web`. `app/`, `bin/`,
  `worker/`, `database/`, `deploy/`, `private_uploads/` and `logs/` must never be
  inside the document root or reachable by URL.
* Worker host: deploy `worker/` and `app/services/` only.

### How deployment runs today

* `.github/workflows/deploy-sydney.yml` ("Deploy SMART ASSESS-MABINI") is the
  production deploy. It runs **only when started by hand** (`workflow_dispatch`),
  on the self-hosted runner on the Sydney server, in the `production`
  environment (required reviewers), checks that the commit is `main` of
  `23-49809/CAPSTONE`, then runs `sudo -n /usr/local/sbin/deploy-smart-assess.sh`.
* `.github/workflows/deploy.yml` ("Deploy SMART ASSESS") is the old Singapore
  deploy. Its file still says `on: push` to `main`, but the workflow is
  **disabled in GitHub** (state `disabled_manually`, checked 2026-11-11) and its
  AWS role step fails, so a merge to `main` does **not** deploy anything today.
  Do not re-enable it. Its helper scripts `.github/scripts/verify-production.sh`
  and `backup-production.sh` still use the old `/var/www/capstone` layout and
  are not used by the Sydney deploy.
* The server script `/usr/local/sbin/deploy-smart-assess.sh` is not in this
  repository and has not been reviewed yet. The read-only readiness check
  (`sydney-readiness-check.sh`, section 8) prints it with secrets masked.
  **Before this release, confirm from that output that it copies `app/` and
  that it cannot delete `private_uploads/`, `logs/`, legacy `web/uploads/`,
  `web/.well-known/` or other server-only files.**

### Production layout (expected; confirm with the readiness report)

```
/var/www/
├── smart-assess.html                   read by PHP (web/prototype.php), not served
├── smart-assess-internal.html          read by PHP, not served
└── smart-assess/                       = the repository's smart-assess-app/
    ├── web/                            ← the ONLY document root (internal.php, api.php ...)
    ├── app/services/                   required by web/ and bin/
    ├── bin/                            CLI only (dispatcher, first admin, draft cleanup)
    ├── database/migrations/            applied by hand, never served
    ├── deploy/systemd/                 unit files and examples, copied to /etc by hand
    ├── vendor/                         Composer (Gmail client)
    ├── private_uploads/  logs/         runtime data: never overwritten or deleted by a deploy
    └── web/uploads/                    legacy uploads (if present): never deleted by a deploy
/etc/smart-assess/
├── apache.env                          the website's settings (format to confirm)
├── ai-dispatch.env                     the dispatcher's settings (new, root 0600)
└── ai-worker-ca.pem                    the worker's private CA certificate (new)
```

### Deploy script: exact requirements

The server deploy script must:

1. Copy from the checked-out commit, in one step, all of:
   `smart-assess-app/web/`, `app/`, `bin/`, `database/`, `deploy/`, `vendor/`
   (or run `composer install --no-dev`), `gmail-mailer.php`, `iprog-sms.php`
   into `/var/www/smart-assess/`, and `smart-assess.html`,
   `smart-assess-internal.html` into `/var/www/`. If it uses `rsync --delete`,
   it must exclude every server-only path and be tried with `--dry-run` first:

   ```sh
   rsync -a --delete --dry-run --itemize-changes \
     --exclude '/private_uploads/' --exclude '/logs/' --exclude '/web/uploads/' \
     --exclude '/web/.well-known/' --exclude '/worker/' --exclude '/tests/' \
     --exclude '/ai_checker/' --exclude '/.git/' \
     "$CHECKOUT/smart-assess-app/" /var/www/smart-assess/
   ```

   Read the dry-run list: any `*deleting` line outside code folders stops the
   deploy. If the script copies only `web/` today, every page will fail with
   HTTP 500 after this release.
2. Keep ownership and permissions: code readable by the web user but not
   writable by it; `private_uploads/` and `logs/` writable by the web user,
   mode 0750 or stricter.
3. Run `php -l` on `web/*.php`, `app/services/*.php` and `bin/*.php` before
   switching over.
4. Not run database migrations automatically (they are applied by hand after a backup).
5. Not install or enable the dispatcher timer (done once, by hand, below).

Apache (the virtual host for the site):

```apache
DocumentRoot /var/www/smart-assess/web
<Directory /var/www/smart-assess>
    Require all denied
    AllowOverride None
</Directory>
<Directory /var/www/smart-assess/web>
    Require all granted
    AllowOverride None
    Options -Indexes
</Directory>
```

With this, `app/`, `bin/`, `database/`, `deploy/`, `worker/`, `vendor/`,
`private_uploads/` and `logs/` have no URL at all. Uploaded documents are only
served by `web/request-file.php`, which requires a Staff session.

Proposed post-deploy checks (not applied; review first):

```sh
APP=/var/www/smart-assess
test -f "$APP/app/services/DocumentRequirements.php"
test -f "$APP/app/services/AiVerification.php"
for f in "$APP"/app/services/*.php "$APP"/bin/*.php; do php -l "$f" >/dev/null; done
for path in /app/services/AiVerification.php /bin/ai-verification-dispatch.php /deploy/systemd/ai-dispatch.env.example \
            /worker/index.php /database/migrations/ /private_uploads/ /logs/ /vendor/autoload.php; do
    code=$(curl --silent --max-time 10 --output /dev/null --write-out '%{http_code}' "https://smartassess-mabini.com$path")
    case "$code" in 200) echo "Private path reachable: $path"; exit 1 ;; esac
done
```

### Web server: dispatcher

The website itself also needs `SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT` (in
`/etc/smart-assess/apache.env`, in that file's own format), with exactly the
value used in `ai-dispatch.env`; without it the website queues nothing.
`DB_HOST` in `ai-dispatch.env` must be the website's own value (`localhost`
for the socket or `127.0.0.1` for TCP match different MySQL accounts). The
dispatcher starts no PHP session (`web/bootstrap.php` skips it on the command
line), so it writes nothing to disk.

Files: `deploy/systemd/smart-assess-ai-dispatch.service`, `.timer` and
`ai-dispatch.env.example`. The service runs
`php bin/ai-verification-dispatch.php --limit=20 --max-seconds=1800` as
`www-data` (which can read the 0640 uploads), with its own environment file,
`MemoryMax=640M`, `CPUQuota=50%`, low CPU and I/O priority, and a read-only
file system. The timer starts it one minute after the previous run ended, so
runs never overlap. The environment file is separate from `apache.env`
because systemd reads only `KEY=VALUE` lines (not Apache `SetEnv`), and so the
worker secret is never given to web requests. The install commands are in the
service file's header; nothing is installed by a deploy.

### Timer behaviour after failed runs (verify on the server)

The dispatcher exits 75 or 3 while the worker is down or misconfigured, so the
service run is marked failed. The design relies on the timer starting it again
a minute later anyway. This could not be checked on macOS; verify it once on
the server after installing (read-only commands; no change):

```sh
systemd-analyze verify /etc/systemd/system/smart-assess-ai-dispatch.service /etc/systemd/system/smart-assess-ai-dispatch.timer
systemctl list-timers smart-assess-ai-dispatch.timer   # NEXT must be about a minute away after a failed run
journalctl -u smart-assess-ai-dispatch --since "-10 min"  # one run per minute, also after exit codes 3/75
```

If the timer stops after a failed run on that systemd version, add
`SuccessExitStatus=75` to the service (outages then count as successful runs;
exit code 3 still shows as failed) and repeat the check.

### Files that must be committed together

Most of this feature is still untracked in the integration worktree; a commit
that includes `web/bootstrap.php` but not `app/` makes every page fail with
HTTP 500. The commit must include **every** path below (check with
`git status --porcelain` before committing; nothing here is committed yet):

* modified: `smart-assess.html`, `smart-assess-internal.html`, and under
  `smart-assess-app/`: `README.md`, `gmail-mailer.php`, `web/api.php`,
  `web/assets/php-bridge.js`, `web/bootstrap.php`, `web/drafts.php`,
  `web/internal.php`, `web/privacy-policy.php`, `web/terms-acceptance.php`,
  `web/terms.php`, `tests/sms_duplicate_test.php`
* new folders and files under `smart-assess-app/`: `app/` (all of
  `app/services/`, including `WorkerStateCleaner.php`), `worker/`, `deploy/`,
  `docs/`, `bin/ai-model-evaluation.php`, `bin/ai-verification-dispatch.php`,
  `database/migrations/20261111_ai_document_verification.sql`, and the tests
  `ai_document_analysis`, `ai_retry_timeouts`, `ai_verification_workflow`,
  `applicant_contact_privacy`, `document_requirements`, `ocr_service`,
  `staff_notification`, `submission_requirements`, `terms_version`
  (`tests/<name>_test.php`)
* never: `logs/`, `private_uploads/`, real `.env`/settings files, database
  dumps, `SMART ASSESS.session.sql`, the empty root `iprog-sms.php`

### Privacy Policy review

`web/privacy-policy.php` now describes the automatic document check. Before it
goes live (no later than the day AI checking is turned on), confirm:

1. The Office has reviewed and approved the text, including section 2's
   "Automatic document check".
2. The date at the top is the date the reviewed text goes live.
3. Both servers are in the AWS Asia Pacific (Sydney) Region (ap-southeast-2).
4. The worker has no public internet address and accepts connections only from
   the web server (security group).
5. Publication timing: deployed with the code (step 3 below) but the AI is not
   yet on, the page describes a check that is not running yet; if that is not
   acceptable, hold the page until step 8.
6. The worker's `SMART_ASSESS_WORKER_OLLAMA_URL` is its loopback address, so
   documents are not sent to an outside AI service.
7. The worker's web-server and PHP temporary request-body files are on its
   encrypted disk (the text promises only that files are kept to check them).
8. `SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT` is the confirmed effective date and
   time, identical on the website and the dispatcher (the dispatcher refuses a
   mismatch). The page prints it in the "Automatic document check" section; it
   is not the "Last updated" date. Requests submitted earlier are not checked by
   AI, automatically or by Staff (decided; enforced on the server).

The policy makes no new retention promise: AI results are kept with the request
like other request records (nothing deletes them automatically). The Terms
version is `2026-11-12` (the file-format rule and the tracking-name wording); the Privacy Policy is not
part of the Terms acceptance, so this change does not ask clients to accept again.

### Deployment sequence

| # | Step | Check | Rollback |
|---|---|---|---|
| 1 | Readiness report from the Sydney server (read-only script) | deploy script copies `app/` and deletes nothing server-only; MySQL ≥ 8.0.1; `requests.id`/`users.id` signed INT InnoDB; DB user privileges; memory and disk; `apache.env` format | — |
| 2 | Review, commit, PR and merge (approval) | tests pass in CI/locally | revert the merge |
| 3 | Back up database and `private_uploads/`, then deploy with `deploy-sydney.yml` (manual) — AI stays off: no migration, no timer | site, submissions, Staff pages, Terms re-acceptance, SMS/Gmail unchanged; private paths 404 | redeploy the previous commit; restore from the backup only if data was harmed |
| 4 | Build the worker (private subnet, SG, TLS, chrony, Ollama, model) | `GET /v1/health` from the web server is `{"ok":true}`; port 11434 closed | stop or terminate the worker instance |
| 5 | Evaluate the model on synthetic documents (`bin/ai-model-evaluation.php`) and measure job times on the worker | typical job well under the 780 s budget | choose another model / instance |
| 6 | Create `/etc/smart-assess/ai-dispatch.env` and the worker pool file with the shared secret (root 0600) | `systemd-analyze verify` | delete the files |
| 7 | Fresh database backup, then run `20261111_ai_document_verification.sql` | table exists; new submissions queue `pending` jobs (harmless; nothing processes them yet) | `DROP TABLE ai_verification_jobs;` (AI off again) |
| 8 | Publish the reviewed Privacy Policy (if held back); install the units; one manual run with a synthetic test request on an approved test record or staging copy | job reaches `completed`; Staff see it; Admin/Head see nothing; request status unchanged | `systemctl disable --now smart-assess-ai-dispatch.timer` |
| 9 | Enable the timer; watch `journalctl -u smart-assess-ai-dispatch`; brief Staff that results are advice | jobs complete within minutes of submission | disable the timer (jobs stay queued; submissions unaffected) |

### Checklist before production

- [ ] Code reviewed, committed and merged with approval.
- [ ] Readiness report reviewed: deploy script copies `app/`, deletes nothing server-only.
- [ ] Model chosen from evaluation results; job times measured; Tagalog OCR decision made.
- [ ] Worker private: no public IP, 11434 closed, SG limited to the web server.
- [ ] HTTPS between web server and worker, certificate verified (`SMART_ASSESS_AI_WORKER_CA_FILE`).
- [ ] Secret generated, stored in Secrets Manager/SSM, identical on both sides.
- [ ] Clocks synchronized (chrony/NTP) on both servers.
- [ ] Document root is `/var/www/smart-assess/web` (`app/`, `bin/`, `deploy/`, `worker/` not reachable by URL).
- [ ] Database backed up; migration applied.
- [ ] Privacy Policy reviewed and published (see "Privacy Policy review").
- [ ] Dispatcher timer enabled; worker php-fpm, nginx and Ollama restart on failure.
- [ ] Test with synthetic documents passed; Admin/Head checked.
- [ ] Office informed: AI results are advice; Staff make every decision.
