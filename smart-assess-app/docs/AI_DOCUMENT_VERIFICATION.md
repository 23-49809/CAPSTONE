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
| `app/services/OcrImagePreparer.php` | GD-only greyscale / straightened / cleaned copies of an image, made only when the first OCR pass reads poorly. |
| `worker/index.php` | The worker's only web entry point. |
| `bin/ai-verification-dispatch.php` | Dispatcher (CLI). |
| `bin/ai-model-evaluation.php` | Compares local models on synthetic documents. |
| `deploy/systemd/smart-assess-ai-dispatch.service`, `.timer` | Dispatcher service and one-minute timer for the web server (not installed). |
| `deploy/systemd/ai-dispatch.env.example` | The dispatcher's own environment file (KEY=VALUE, root 0600). |
| `deploy/mysql/ai-dispatch-db-user.sql.example` | The dispatcher's least-privilege MySQL account (not applied). |
| `deploy/systemd/smart-assess-ai-dispatch-egress.service`, `deploy/nftables/ai-dispatch-egress.nft` | The dispatcher's egress filter: only 127.0.0.1:8095 (not installed). |
| `deploy/sbin/smart-assess-ai-dispatch-permissions.sh`, `deploy/systemd/smart-assess-ai-dispatch-permissions.service` | Read access for the dispatcher's group `sa-ai-read`, re-applied before every run (not installed). |
| `bin/ai-worker-compat-check.php` | Release-order check: is a worker copy compatible with the deployed application? |
| `deploy/sbin/smart-assess-ai-worker-refresh.sh` | Refreshes the worker copy only when the release-order check passes; health check and rollback (not installed). |
| `deploy/sbin/smart-assess-ai-egress-guard.sh`, `deploy/systemd/smart-assess-ai-egress-guard.service`, `.timer`, `deploy/systemd/nftables.service.d/50-smart-assess-ai-egress.conf` | Keep both AI egress filters loaded or stop what they protect (not installed). |
| `deploy/systemd/smart-assess-ai-worker-link.socket`/`.service`, `deploy/systemd/smart-assess-ai-ollama-link.socket`/`.service`, `deploy/systemd/smart-assess-ai-worker-fpm.service.d/50-private-network.conf` | Step 13: the dispatcher and the worker pool run without network; these socket links are their only connections (not installed). |
| `deploy/systemd/smart-assess-ai-worker-http.socket`, `deploy/systemd/smart-assess-ai-worker.service.d/50-private-network.conf`, `deploy/systemd/worker-lighttpd.conf.example` | Step 15: lighttpd runs without network; systemd holds 127.0.0.1:8095 and hands it over (not installed). |
| `deploy/systemd/smart-assess-ai-worker-fpm.service.d/50-private-network.conf`, `deploy/systemd/smart-assess-ai-dispatch.service` (Step 16 lines) | Step 16: PHP-FPM and the dispatcher see nothing in `/run` but the sockets they use; the pool keeps its runtime folder across restarts (not installed). |
| `bin/ai-worker-smoke-test.php`; `bin/ai-verification-dispatch.php --only-job=ID` | Step 17: a smoke test that sends only its own synthetic image to the worker (real Tesseract and model; prints statuses only), and a dispatcher mode that processes one named job and never claims another (for the controlled activation test). |
| `deploy/systemd/worker-php-fpm-pool.conf.example` | Example php-fpm pool for the worker server, with its time limits. |
| `database/migrations/20261116_ai_remote_worker.sql` | Processor selection (`off`/`aws`/`remote`), remote request nonces, idempotent remote results (not applied). See "Remote pull worker". |
| `web/ai-remote.php`, `app/services/AiRemoteQueue.php`, `app/services/RemoteWorkerSignature.php` | Remote pull worker, website side: signed machine-to-machine endpoint (disabled by default; answers 404). |
| `bin/ai-remote-pull.php`, `app/services/AiRemoteClient.php` | Remote pull worker, remote side (e.g. a Mac): connects out over HTTPS, runs OCR and the model locally. |
| `bin/ai-processor.php` | Shows or selects the only processor allowed to claim jobs; `--status` for monitoring. |

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

Both settings (`apache.env` and `ai-dispatch.env`) must be identical. The website records on each job the
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

### OCR passes and image preparation

`OcrService` reads each image (and each rendered PDF page) in one Tesseract pass first, as
uploaded, asking for word confidences (TSV). It retries only when that pass reads poorly
(fewer than 150 letters/digits read with confidence 70 or more, or a mean word confidence
under 80) **or is incomplete**: for an ID slot the worker passes a completeness check
(`DocumentAnalysis::ocrCompleteness()`): a PhilSys card counts as complete only when its title,
field labels and name fields were read (a cut-off "JU" is not a name), another accepted ID when
its printed title was read. A confident but incomplete reading is therefore not accepted while
a retry may read more. Other documents get no completeness check.

At most seven retries run: Tesseract's orientation detection (PSM 1, for photos taken sideways
or upside down; needs `osd.traineddata`, skipped without it), a single-block layout (PSM 6, for
cards held at an angle), and greyscale, straightened, sharpened and cleaned copies made with
PHP's GD extension (`OcrImagePreparer`). The most complete pass wins, then the most confidently
read one, so no transformation is applied blindly; a clear, complete image still takes one pass.
Nothing is invented: when no pass reads a field, the field stays unverified.

Straightening crops a light card from a darker background and measures the text angle on the
central part of the card only (dark table corners would otherwise decide the angle).
Brightness stretching was evaluated and is not used (it helped only cards already read).

Limits: retries stop at the document's OCR deadline; a copy is prepared only with at least 2 s
left (GD work cannot be interrupted); on a PDF, retries leave 4 s for each page still to read.
GD decodes only JPEG and PNG (WebP is left to Tesseract) of at most 12 MP, and works on at most
2400 px. Measured on a 12 MP photo: PHP resident memory +150 MB, Tesseract about 170 MB in its
own process, so about 350 MB at the peak in the 1 GiB worker slice (one job at a time). GD memory
is not counted by PHP's `memory_limit`; these size limits bound it. The original upload is never
changed; copies are 0600 files in the private temporary folder, removed with it (and with the
job folder by `WorkerStateCleaner` if the process is killed).

Without GD on the worker the retries use Tesseract's modes only: on the synthetic set, 13 of 16
names instead of 16 (noisy and dark-table photos are lost), never a wrong name.

A PDF page whose text layer has fewer than 200 letters/digits (for example a typed header over
a scanned card) is also OCR'd; the OCR text replaces the layer only when it reads at least 20
more letters/digits, and a failed or late OCR keeps the layer (the page never fails because of it).

#### Checking the worker's OCR dependencies (read-only)

Run on the server as an administrator; none of these change anything. The worker pool may run
with its own runtime configuration, so check GD with the pool's own binary and flags, not the
command-line PHP:

1. `systemctl cat smart-assess-ai-worker-fpm.service`: note the `ExecStart` binary and its
   `-c`/`-n`/`-y` options (the runtime files are in `/etc/smart-assess-ai-worker-runtime/`).
2. GD in that runtime: `<that php-fpm binary> <same -c/-n options> -m | grep -ix gd`, and
   `<binary> <options> -i | grep -E 'GD Support|JPEG Support|PNG Support|disable_functions'`
   (JPEG and PNG support must be enabled; `imagecreatefromjpeg`/`imagecreatefrompng` must not be
   in `disable_functions`). Also `dpkg -l | grep -E 'php8\.5-gd'`.
3. Tesseract, as the pool's account (the `user =` line of the runtime pool file):
   `sudo -u <that user> /usr/bin/tesseract --version` and
   `sudo -u <that user> /usr/bin/tesseract --list-langs` (must list `eng` and `osd`; the
   folder it prints must hold `osd.traineddata`). Also `dpkg -l | grep -E 'tesseract-ocr-(eng|osd)'`.
4. Missing GD or `osd` does not stop the worker or fail any document; it only means fewer
   retries. Installing either is a production change and needs its own approval.

OpenCV was evaluated (perspective warp, CLAHE, denoising, adaptive thresholds) and is not
used: on the synthetic cards GD plus Tesseract's own layout modes read every case OpenCV did,
while OpenCV would add a Python runtime and about 110 MB per call on the worker. CLAHE,
denoising and adaptive thresholds often made reading worse.

**PhilSys names.** When the model gives no name that is found in an ID's text and the text
carries the PhilSys card titles, `DocumentAnalysis::philsysName()` reads the labelled fields
(Apelyido/Last Name, Mga Pangalan/Given Names, Gitnang Apelyido/Middle Name; labels may be
misread by one letter): each value is the next line, in capitals. Both the last and the given
name are required, so a card whose last name cannot be read keeps its name unverified; nothing
is guessed. The name found still goes through the same check against the card's text.

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
php tests/ocr_scenarios_test.php   # real Tesseract on generated fictional cards; exits 2 without the tools
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

### Staff decide (reviewed in Steps 6–12, 14 Nov 2026)

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
* **Document identification and exact names (result version 3).** For each
  document the model also gives `document_kind` (a code from the fixed lists in
  `DocumentAnalysis::ID_KINDS`, `PROPERTY_KINDS` and `OTHER_KINDS`, so an ID is
  never confused with a title, clearance or deed), `holder_name`,
  `holder_name_clear` and `property_number`; it no longer writes a `note`. The
  worker keeps the name and the number only if they occur in that document's own
  OCR text (`DocumentAnalysis::keepQuotedDetails()`). It then replaces them with
  the text's own characters, so Staff never see a spelling the model corrected,
  completed or took from the form. Matching is by whole words, without letter
  case or accents, and the worker reports how the name was found (`name_match`):
  `exact` (all its words in one block, with only spaces, commas, periods or
  hyphens between; a hyphen at a line break is joined), `parts` (labelled parts
  such as "Last Name … Given Names …" with only name-field labels between them,
  shown in the document's order), `incomplete` (a single word, or more name-like
  text right next to it, e.g. a middle name or suffix the model left out; shown,
  with a finding asking Staff to read the full name), or `none`. Words scattered
  across a document (an address, another person's name) are never put together.
  A property number is kept only as a whole number (`018-0004` is not found in
  `018-00045`; spacing around its hyphen may differ). A type that contradicts the
  requirement slot (a clearance in an ID slot, an ID where a title is required)
  becomes an unverified AI finding. The application shows a name only when
  `name_match` is not `none`, and never from a version 1 or 2 result. Words with
  digits or stray symbols are marked as possibly misread
  (`DocumentAnalysis::uncertainNameParts()`), and the name is never corrected.
  ID card numbers are not requested. The document kind is shown as an unverified
  AI suggestion; the name and number are shown as text read by OCR. Release
  order: deploy the application first (it accepts versions 1, 2 and 3), then
  refresh the worker; `bin/ai-worker-compat-check.php` refuses the reverse.
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
  example the connection dropped), its outcome is uncertain: the database may
  even finish the commit a moment later. A fresh connection looks for the request
  several times over about 3.5 seconds (`sa_commit_submission()`): found means
  success; otherwise the submission is reported as failed but **no file is
  deleted** (not the request's copies, not the draft's own files), so a saved
  request never loses its documents and a draft is never damaged. Only a failure
  before the commit (a definite rollback) removes the request's copies. Files a
  submission takes from a saved draft are **copied** into the request (a new file
  gets the folder's default access entries) and the draft's own files are removed
  only after the commit. A failing rollback (lost connection) is logged and never
  skips the clean-up.
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
    folder with only those paths mounted in. Needs the `acl` package. The script
    refuses any path with a symbolic link in any component, an unexpected owner
    or a world-writable folder, grants files only by a physical walk that never
    follows links (`setfacl -R -P`), checks every path again afterwards, and its
    unit mounts drafts, logs and every folder it never changes read only and has
    only `CAP_FOWNER` and `CAP_DAC_READ_SEARCH`. If a stored file is still not
    readable when a job runs (for example it arrived during a run), the job is
    retried later without using an attempt (`stored_file_unreadable`), never
    reported as "could not be prepared".
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
  * **No network at all (Step 13).** A firewall filter by user exists only while the
    ruleset holds it: `nft flush ruleset`, or `nftables.service` (its
    `/etc/nftables.conf` begins with `flush ruleset`), removes it, and until it is
    reloaded (the guard: up to about 30 s) a process that is already running could
    reach every service on 127.0.0.1. Measured on Ubuntu 26.04 with the Step 12 design:
    MySQL, MySQL X, Apache, SSH, Ollama and the DNS stub (which resolves external names)
    were all reachable during the gap; the internet stayed blocked (`IPAddressDeny`).
    So the dispatcher and the worker's PHP-FPM pool (which parses the documents) now run
    with `PrivateNetwork=yes` and only Unix sockets (`RestrictAddressFamilies=AF_UNIX`):
    systemd gives each its own network namespace with only an empty loopback when the
    process starts, which no firewall change can alter. Each has one way out, a socket
    file served by systemd's minimal forwarder (`systemd-socket-proxyd`), which can only
    connect to one fixed address:
    * dispatcher -> `/run/smart-assess-ai-link/worker.sock` (group `sa-ai-dispatch`)
      -> the worker on 127.0.0.1:8095 (`smart-assess-ai-worker-link.socket`);
    * worker pool -> `/run/smart-assess-ai-ollama/ollama.sock` (group `sa-ai-worker`)
      -> Ollama on 127.0.0.1:11434 (`smart-assess-ai-ollama-link.socket`), set with
      `SMART_ASSESS_WORKER_OLLAMA_SOCKET` (worker.env and the pool's `env[]` line) and the
      drop-in `smart-assess-ai-worker-fpm.service.d/50-private-network.conf`.
    MySQL is reached through its socket file (`DB_HOST=localhost`). Verified in the VM
    with processes already running: after a flush, in the full 30 s gap, with the guard
    broken, with an invalid filter file, with the nftables hook failing, and right after
    a reboot, each reached only its own socket. If a link is stopped or its forwarder is
    broken, the worker reports "not ready" and the dispatcher "unreachable"; no other
    route exists. The nftables filters, the guard (timer every 30 s and the
    `nftables.service` drop-in, which keeps them loaded or stops the worker) and
    `IPAddressDeny` remain as extra layers.
  * **lighttpd without network (Step 15).** lighttpd (the worker's HTTP front, user
    `sa-ai-worker`) parses every request. Measured in the VM with the Step 5 sandbox: an
    already-running process could reach Ollama and world-connectable socket files (snapd,
    local SSH, polkit, systemd's manager) even with the filter loaded, and after a flush also
    MySQL, MySQL X, Apache, SSH and the DNS stub. Now systemd itself holds 127.0.0.1:8095
    (`smart-assess-ai-worker-http.socket`) and hands the listening socket to lighttpd, which
    runs with `PrivateNetwork=yes`, only Unix sockets, and an empty read-only `/run` except the
    PHP-FPM socket folder (drop-in `smart-assess-ai-worker.service.d/50-private-network.conf`,
    plus `server.systemd-socket-activation = "enable"` in its configuration,
    `worker-lighttpd.conf.example`). Callers see no difference. Verified: with the process
    already running, before and after a flush and after a reboot it reached only the PHP-FPM
    socket; a missing activation line or an invalid configuration makes lighttpd fail, and after
    3 failed starts systemd closes the address so callers are refused at once (recovery:
    `systemctl reset-failed` both units, then start them); the guard stops the socket unit first.
  * **Nothing else in `/run` for PHP-FPM and the dispatcher (Step 16).** Both had no network but
    still saw most of `/run`; measured in the VM, each could connect to 22 socket files there besides the ones it uses
    (snapd, the local SSH socket, polkit, systemd's control sockets, the journal, userdb, uuidd,
    multipathd), and to any socket created there later. Now both get an empty read-only `/run`
    except what they use:
    PHP-FPM (drop-in `smart-assess-ai-worker-fpm.service.d/50-private-network.conf`) its own
    runtime folder (writable: it creates `fpm.sock`), the Ollama link, systemd's notify socket
    (`Type=notify`: without it the pool's start times out, measured) and the journal's syslog
    socket (`error_log = syslog`); the dispatcher (`smart-assess-ai-dispatch.service`) MySQL's
    socket folder (`DB_HOST=localhost`) and its worker link. Folders are bound, never socket
    files, so sockets recreated by restarts are still seen. The pool also keeps its runtime
    folder across restarts (`RuntimeDirectoryPreserve=restart`), so lighttpd's bind of it stays
    valid even if lighttpd is not restarted with the pool (on systemd 259 it is: measured).
    Verified in the VM: each reaches only those sockets, also after new 0666 sockets appear, after
    a flush and after a reboot; SIGKILL and an out-of-memory kill of the pool, a killed pool
    child, restarts of the Ollama link and the worker refresh all recover; real worker-to-Ollama
    jobs and the dispatcher's database connection work.
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
3. Refresh the worker copy with `deploy/sbin/smart-assess-ai-worker-refresh.sh`
   (installed as `/usr/local/sbin/smart-assess-ai-worker-refresh.sh`): it stages the
   copy, runs the check and **refuses** (exit 1, nothing changed) unless it passes,
   then swaps it in, restarts the worker's PHP pool, checks `/v1/health` and rolls
   back if the worker is not healthy. `--check-only` stages and checks only.
4. Enforced at run time too: the dispatcher runs the same check against
   `/opt/smart-assess-ai-worker` before every run (`ExecStartPre`), so a mismatched
   worker stops the dispatcher before any job is sent.

The check reads source files only. A new application with the worker installed
in Step 4 (version 1) is compatible: model quotes are then simply dropped.

**Version 4 (printed titles; request-type checks).** The worker now also reports, per file,
which document titles or issuers appear in that file's own OCR text (`kind_evidence`,
`DocumentAnalysis::KIND_EVIDENCE`; never the file name). The application accepts versions
1 to 4; the release before it accepts 1 to 3 and would fail version 4 answers, so the same
order applies: application first, then the worker (the compatibility check enforces it).
The Staff page then runs only the checks of the request type:

* Document Request: on each ID, **Name Match** (the name read from the ID against the
  submitted name; a representative's own ID is the one compared, the owner's ID name is not
  compared when the owner's name was not submitted) and **ID Type** (a government-issued ID,
  confirmed only when its title or issuer appears in the text).
* Land Transfer: **Document identification** of the five required documents only (Tax
  Declaration or Title; Deed of Sale or Donation). The ID is still required with the
  application but is not part of its Document Check; "all checks passed" needs all five.
  Evidence is the document's own heading (a line starting with the title, with only a short
  tail); a document merely mentioned inside another ("covered by Transfer Certificate of Title
  No. ...", "Tax Declaration No. ...") does not count, a title also needs its Registry of Deeds,
  and a file showing the headings of two different documents is never Correct.

Verdicts are Correct, Mismatch, Possible mismatch, Missing or Unable to verify. A type the
model suggests without its title in the text, an unreadable file and any result from an
earlier worker are "Unable to verify", never Correct. Everything stays advisory.

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

## Remote pull worker (Stage 1: code and tests only, NOT enabled anywhere)

A second, optional processor: a machine such as a Mac runs OCR and the model
itself and **connects out** to the website over HTTPS. Nothing connects to it; it
opens no port, needs no tunnel and no port forwarding.

```
Mac: bin/ai-remote-pull.php ──HTTPS, signed──► https://<site>/ai-remote.php (web/ai-remote.php)
     │ local Tesseract/Poppler + Ollama          │ AiRemoteQueue → AiVerification (same claim, lease,
     │ (AiDocumentWorker, unchanged)              │ eligibility, validation and storage as the dispatcher)
     └─ health → claim → document (one at a time) → complete | fail
```

**Only one processor at a time.** `ai_processor_settings` (migration 20261116) holds
`off`, `aws` or `remote`. Every claim reads it with a shared lock inside the claim
transaction; `bin/ai-processor.php --set=…` changes it only while no job holds a live
lease. The AWS dispatcher exits with code 5 unless `aws` is selected; the endpoint
answers `processor_not_selected` unless `remote` is. After the migration the value is
`off`. Before the migration the dispatcher behaves exactly as before.

**Privacy rules are unchanged.** The remote worker claims through the same query as the
dispatcher: only requests submitted on or after `SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT`
and queued under that date. With no date set it gets nothing (`not_enabled`). There is
no Staff override. **Processing real applicant documents on a personal computer needs
Office approval and Privacy Policy wording that describes it before it is ever enabled**:
the current policy describes a single AWS server.

**Authentication** (`RemoteWorkerSignature`): a separate secret
(`SMART_ASSESS_AI_REMOTE_SECRET` on the website, at least 32 characters; the endpoint
refuses to run if it equals the AWS worker's secret). On the Mac the secret is read only
from `SMART_ASSESS_AI_REMOTE_SECRET_FILE` (mode 0600, owned by the user; a secret in the
environment is refused). Requests are signed over method, path, timestamp, nonce and the
body's SHA-256 (±5 minutes); nonces are stored in `ai_remote_nonces`, so a replay is
refused (409). Every answer is signed over the request's nonce, status and body (documents:
over their SHA-256), so the Mac accepts only the website's real answer. Message prefixes
differ from the AWS worker protocol, so a signature of one is never valid for the other.

**Endpoint** `web/ai-remote.php`: answers 404 to everything unless
`SMART_ASSESS_AI_REMOTE_ENABLED=1` and the secret are set. POST and JSON only, at most
1 MB, no session or cookie (`SA_NO_SESSION`), exact fields per operation:

| Operation | What it does |
|---|---|
| `health` | processor and whether AI is enabled (no job data) |
| `claim` | the next eligible job: ID, claim token, lease left, time budget, declared details, and each document's key, label, type, size and SHA-256 (never a path) |
| `document` | one document of a job the caller holds a **live** claim on, found through `jobManifest()`; streamed, with its SHA-256 signed |
| `complete` | validated with `DocumentAnalysis::validateWorkerResult()` against that job's documents and stored only within the live lease; a repeat answers `already_stored` |
| `fail` | one of `AiRemoteQueue::FAIL_CODES`, with the normal retry rules |

**Recovery.** The Mac tracks the lease on the wall clock (which keeps running while it
sleeps). A result whose lease ran out is never sent; the website refuses it anyway
(`lease_expired`, or `stale` once the job was claimed again). An abandoned claim simply
expires and the job is claimed again (one attempt used). Downloads that fail or do not
match their size and SHA-256 are reported as `remote_download_failed` (no attempt used, up
to `REMOTE_TRANSIENT_LIMIT` = 5 times per job; after that a failed download uses an attempt).
Leftover job folders are removed before every claim, under the client's lock. After a failed
job the client pauses for its poll interval before claiming again. A result that cannot be
delivered (website unreachable, maintenance, rate limit) is retried after 5, 10, 20 and 40
seconds while the lease allows; a repeat of a stored result is answered `already_stored`.

**Limits against a stolen or misused secret (review H1).** These hold whatever the remote
worker does:
- **One live remote claim at a time.** While any job holds a live claim, `claim` answers
  `{"job":null,"active_claim":true}`. The check and the claim run under a MySQL named lock,
  so two simultaneous requests can never both get a job. A Mac that restarts mid-job waits
  for its old lease to run out.
- **Claim and download limits (review H-A)**, sized for SMART ASSESS's real volume:
  `SMART_ASSESS_AI_REMOTE_MAX_CLAIMS_PER_HOUR` (default **3**, at most 20),
  `SMART_ASSESS_AI_REMOTE_MAX_CLAIMS_PER_DAY` (default **10**, at most 50) and
  `SMART_ASSESS_AI_REMOTE_MAX_DOWNLOADS_PER_HOUR` (default **15**, at most 100), counted from
  `ai_remote_events`. Every claim counts whatever happens to it afterwards, so failing or
  completing jobs quickly never frees more claims. Over a limit: `429 rate_limited`; the job
  keeps waiting. Claims are checked under one named lock and downloads under another, so
  concurrent requests cannot overshoot a limit.
- **Each document once per claim.** `ai_remote_downloads` (primary key job + SHA-256 of the
  claim token + document key) refuses a second download of the same document under the same
  claim (`409 already_downloaded`), atomically, even for simultaneous requests. The only
  retry: the Mac reports `remote_download_failed`, the job is given back, and a **new** claim
  (counted against the limits) may download it again.
- **Time-boxed (required):** `SMART_ASSESS_AI_REMOTE_ENABLED_UNTIL` (date and time with offset,
  `YYYY-MM-DDTHH:MM:SS+08:00`). Unset, invalid, past, or more than 31 days ahead: every request
  gets 404, even with `SMART_ASSESS_AI_REMOTE_ENABLED=1`. Set it to the end of the agreed
  testing window only. A correctly signed claim outside the window is logged as
  `claim_outside_window` (monitor line) for alerting; other requests as `outside_window`.
  **If the window closes while a job is claimed:** from that moment every request gets 404, so
  the Mac can neither deliver the result nor report a failure (it retries delivery until its
  lease runs out, then discards the result). The job stays `processing` until its lease expires
  (up to `SMART_ASSESS_AI_REMOTE_LEASE_SECONDS`, default 17 minutes) and then waits as an
  expired claim: nothing is stored, no request is changed. It is processed later only by the
  processor selected then (the AWS dispatcher if `aws` is selected, or the remote worker in a
  new window), and that try counts as an attempt. Staff see it as "Checking now" until then.
  End a window only when the Mac is idle (`--status`: 0 jobs under a live claim).
- **Exposure if the secret leaks while the window is open:** at most 3 jobs per hour and 10 a
  day (with their declared names and ARP numbers), and at most 15 documents per hour, each
  document once per claim, until the window ends, the processor is set `off`, or the secret is
  rotated. Keep the window short and watch the monitor lines.
- **Optional address allowlist** `SMART_ASSESS_AI_REMOTE_ALLOWED_IPS` (comma-separated IPv4/IPv6
  addresses and IPv4 CIDR ranges). An extra layer only: an allowed address still needs a valid
  signature, and a refused one gets the same 404 as a disabled endpoint.
  **How the address is determined (review L-D):** only PHP's `REMOTE_ADDR`, the address of the
  TCP connection Apache received. Forwarded headers (`X-Forwarded-For`, `Forwarded`,
  `X-Real-IP`) are **never** read: anyone can send them, so they must never be trusted unless a
  design explicitly adds and secures a known proxy. Consequences:
  - If Apache listens on an IPv6 socket, an IPv4 client can appear as an IPv4-mapped IPv6 address
    (`::ffff:203.0.113.5`), which does **not** match the IPv4 entry `203.0.113.5`: such a request
    is refused (fails closed). Check `REMOTE_ADDR` in the access log and list the address in the
    form it actually appears.
  - Behind a reverse proxy, load balancer or CDN, `REMOTE_ADDR` is the proxy's address, so the
    allowlist would admit everyone the proxy forwards. Do not rely on the allowlist in such a
    setup (the signature, window and limits still apply). Today the production site has no proxy.
  - A home or mobile connection's address changes; the allowlist is then an obstacle, not
    protection. It is an extra layer only.
- **Failure codes** accepted from the remote worker: `AiRemoteQueue::FAIL_CODES` only.
  `worker_busy` and `worker_unavailable` are refused; `worker_not_configured` after a claim
  is recorded as `worker_error` and uses an attempt (the worker checks its settings before it
  claims), so no job can cycle without limit.

**Remote results are less trusted (review H2).** The website never sees the remote worker's
OCR text, so it cannot check anything the worker quotes. Before a remote result is stored,
`AiRemoteQueue::distrustRemoteText()` removes every evidence quote (model and rule findings),
re-words every rule finding on the server from its validated codes as *"Automatic check
reported by the remote AI worker (unverified): …"*, replaces the model name with the fixed
label *"remote, unverified"* (and drops the digest), replaces the OCR engine with the fixed
label *"remote worker"*, and maps every OCR error code that `OcrService` cannot produce
(`AiRemoteQueue::OCR_ERROR_CODES`) to `unreadable_document` (review H-B). Nothing the remote
worker wrote is shown to Staff: not as document text, a label, an explanation or an error.
(Model findings are always worded by the server.)

**A remote result is never "Passed" (review M-A).** The outcome column only has passed,
needs_review and missing_information, so a remote result that found nothing is stored as
**needs_review** with the reason *"Checked by the remote AI worker (remote, unverified). This
server cannot verify a remote check, so it is never marked Passed: review the original
documents."* This is applied in `AiVerification::finish()` for every remote result
(`storeRemoteResult()`), so retries, Staff re-runs and repeated completions cannot bypass it.
Results from the AWS worker on this server are unchanged.

**Maintenance (review M1).** While the deployment maintenance flag exists, `claim`, `complete`
and `fail` answer `503 maintenance` (downloads are read-only and still work; `health` reports
`"maintenance": true`). The Mac client waits and retries.

**Monitoring (review M3).** `ai_remote_events` records claim, download, complete, fail,
rejected, replay, rate_limited and maintenance events with the job ID only (no names, text,
addresses or secrets; kept 30 days). Requests refused **before** authentication (bad
signature, refused address, outside the window) never touch the database: they are counted in a
small counter file and summarized in at most one log line per kind per minute,
prefixed `[SMART_ASSESS_REMOTE] monitor:` (use that prefix for alerts).

**Monitoring storage (review L-A).** The counter lives in an application-owned private folder,
`smart-assess-app/logs/ai-remote-monitor/` (outside the web root, never the shared temporary
folder). The folder is created 0700 when missing; an existing one must be a real folder (not a
symbolic link) owned by the web-server user, and is tightened to 0700 if it is looser. The
counter file `smart-assess-remote-monitor.json` must not be a symbolic link; it is created 0600
(umask 077), checked after opening to be the same file as its path (device and inode) and owned
by the web-server user, and tightened to 0600 if it is looser. If any of this fails the counter is
not used, `monitor: unavailable` is logged, and **the endpoint refuses every request with 404**
(fail closed). On the server, `smart-assess-app/logs/` must therefore be writable by the web
server user (www-data), and nothing else may own `logs/ai-remote-monitor`. Check the state with
`php bin/ai-processor.php --status` (live claims, limits, event counts for the last hour and
24 hours). Investigate: any `replay`, repeated `unauthenticated` summaries, `rate_limited`,
or claims outside the agreed testing times.

**One secret per environment (review L-B).** Signatures cover the method, path, time, nonce and
body, but not the host name, and each environment keeps its own nonce table. A request captured
on one environment could therefore be replayed to another environment within 5 minutes **if both
used the same secret**. Never reuse a remote-worker secret: generate a separate one
(`openssl rand -hex 32`) for production, for each test or staging server and for each local
setup, and never copy one between them. Each must also differ from that environment's AWS
worker secret (the endpoint refuses to run otherwise).

**Credential rotation (review M4).** The website accepts an optional second secret,
`SMART_ASSESS_AI_REMOTE_SECRET_NEXT`, and signs each answer with the secret that verified the
request. Neither may equal the AWS worker secret (the endpoint then answers 404). Rotate at
least after every testing period, whenever the Mac or its account may be compromised, and when
someone with access leaves:
1. Generate a new secret (`openssl rand -hex 32`) on the Mac into a new 0600 file.
2. On the website, set `SMART_ASSESS_AI_REMOTE_SECRET_NEXT` to it; reload Apache gracefully.
3. Point the Mac's `SMART_ASSESS_AI_REMOTE_SECRET_FILE` at the new file; run one `--once`.
4. On the website, move the new value to `SMART_ASSESS_AI_REMOTE_SECRET`, remove `_NEXT`,
   reload Apache; securely delete the old secret file on the Mac.
5. If the old secret may have leaked: set the processor `off` first, then rotate, then review
   `--status` and the monitor log lines for the exposure period.

**Clock synchronisation (review L6).** Signatures allow 5 minutes of clock difference. Keep
"Set time and date automatically" on for the Mac (System Settings > General > Date & Time) and
NTP (systemd-timesyncd) on the server. A wrong clock shows as `remote_auth_failed` on the Mac;
the client then says to check the clock and the secret file.

**Dispatcher exit code 5 (review L4).** After the migration the processor is `off`, so an
enabled dispatcher timer would exit with code 5 every minute and systemd would show the unit as
failed. That is fail-closed and intended: keep the timer disabled unless `aws` is selected.

**Remote side settings** (`bin/ai-remote-pull.php`): `SMART_ASSESS_AI_REMOTE_URL`
(https, or http only to loopback for tests), `SMART_ASSESS_AI_REMOTE_SECRET_FILE`,
optional `SMART_ASSESS_AI_REMOTE_CA_FILE`, `SMART_ASSESS_WORKER_STATE_DIR` (0700) and the
same `SMART_ASSESS_WORKER_*` OCR and Ollama settings as `worker/index.php`.
Website: `SMART_ASSESS_AI_REMOTE_ENABLED`, `SMART_ASSESS_AI_REMOTE_SECRET`, optional
`SMART_ASSESS_AI_REMOTE_SECRET_NEXT` (rotation only), `SMART_ASSESS_AI_REMOTE_LEASE_SECONDS`
(300-3600, default 1020), `SMART_ASSESS_AI_REMOTE_MAX_CLAIMS_PER_HOUR`,
`SMART_ASSESS_AI_REMOTE_MAX_CLAIMS_PER_DAY`, `SMART_ASSESS_AI_REMOTE_MAX_DOWNLOADS_PER_HOUR`,
`SMART_ASSESS_AI_REMOTE_ALLOWED_IPS`, and the required `SMART_ASSESS_AI_REMOTE_ENABLED_UNTIL`.

**Order if it is ever installed (each step needs approval):** deploy the code (the endpoint
stays 404); back up; apply migration 20261116 (processor becomes `off`); immediately add
`GRANT SELECT ON <db>.ai_processor_settings` for the dispatcher account; only after Office
approval and a Privacy Policy update, add the website settings (including
`SMART_ASSESS_AI_REMOTE_ENABLED_UNTIL` = the end of the approved window) and select `remote`.
Rollback: `bin/ai-processor.php --set=off`, remove the website settings (404 again), then,
if wanted, the rollback lines in the migration.

Tests: `tests/ai_remote_pull_test.php` (Part A without a database; Part B with a `_test`
database: authentication, replay, expired timestamps, one live claim and simultaneous claims,
claim and download limits, address allowlist, lease expiry, stale and repeated completion,
invalid results, remote text distrust, failed downloads and the retry limits, maintenance gate,
secret rotation, disconnection and recovery, model timeout and outage, processor switching,
monitoring, Staff-only results).

## Production deployment (not done; needs explicit approval)

**Production runs on one server: follow "Production installation checklist (single server,
as built)" at the end of this section.** The two-server sections below are the original plan.

Apart from the items the checklist lists as done, nothing below has been applied. Every step needs approval, a backup first and
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

`web/privacy-policy.php` describes the automatic document check as built: one server
runs the website and the check (there is no separate document-check server). The
Municipal Assessors approved SMART ASSESS, including the automatic document check; the
approval record is kept by the Office and is not part of this repository. On 15 Nov 2026
seven passages were corrected to match the single-server system (no change of purpose,
data, recipients, region or access; the claim of an encrypted connection was removed).
Before it goes live (no later than the day AI checking is turned on), confirm:

1. The Office acknowledges the corrected wording (the seven passages listed in the
   pull request), including section 2's "Automatic document check".
2. The date at the top is the date the reviewed text goes live.
3. The website, its stored files and the check run on one server in the AWS Asia
   Pacific (Sydney) Region (ap-southeast-2).
4. The checking service is not reachable from the internet: it listens only on the
   loopback address or local socket files (127.0.0.1:8095 held by systemd; Ollama on
   127.0.0.1:11434), its accounts run without network access (Windows A and B), and every
   job is signed with the shared secret.
5. Publication timing: deployed with the code (step 3 below) but the AI is not
   yet on, the page describes a check that is not running yet; if that is not
   acceptable, hold the page until step 8.
6. The worker's `SMART_ASSESS_WORKER_OLLAMA_URL` is its loopback address, so
   documents are not sent to an outside AI service.
7. The checking service's working copies (its state folder and its web server's private
   temporary folder) are on the server's disk, which is not encrypted (verified 15 Nov
   2026). The text makes no encryption claim and promises only that files are kept to
   check them.
8. `SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT` is the confirmed effective date and
   time, identical on the website and the dispatcher (the dispatcher refuses a
   mismatch). The page prints it in the "Automatic document check" section; it
   is not the "Last updated" date. Requests submitted earlier are not checked by
   AI, automatically or by Staff (decided; enforced on the server).

The policy makes no new retention promise: AI results are kept with the request
like other request records (nothing deletes them automatically). The Terms
version is `2026-11-13`; the Privacy Policy is not
part of the Terms acceptance, so this change does not ask clients to accept again.

### Production installation checklist (single server, as built)

Production does **not** use a separate worker server. Everything runs on the one Sydney
instance (ap-southeast-2):
- Apache + PHP website, MySQL `smart_assess_sydney`;
- the dispatcher (systemd timer, account `sa-ai-dispatch`, no network: MySQL through
  `/run/mysqld/mysqld.sock`, the worker through `/run/smart-assess-ai-link/worker.sock`);
- the worker: systemd holds 127.0.0.1:8095 and hands it to lighttpd, which passes requests
  to the PHP-FPM pool `sa-ai-worker` (Tesseract, Poppler). The pool reaches Ollama
  (127.0.0.1:11434, `llama3.2:3b`) only through `/run/smart-assess-ai-ollama/ollama.sock`.

Traffic never leaves the instance: loopback and Unix sockets, every job signed with the shared
secret. There is no HTTPS between the parts, no CA file, no second instance and no security
group change. The separate-server design in "Architecture" and "AWS resources" above was the
original plan and is not used; its HTTPS, CA and security-group items do not apply. No paid
service or extra AWS resource is needed. Model memory and concurrency stay as installed
(`smart-assess-ai.slice` 4.5 GiB, one job at a time).

Each window needs its own approval, a backup first, and the rollback named below. AI stays off
until the activation step.

| Already done on production | When |
|---|---|
| Migration `20261111_ai_document_verification` (table `ai_verification_jobs`, 0 rows) | 13 Nov 2026 |
| Tesseract and Poppler; Ollama 0.40.13 with `llama3.2:3b`, loopback only | 13–14 Nov 2026 |
| Worker (Step 5): lighttpd + PHP-FPM as `sa-ai-worker`, worker egress filter, `smart-assess-ai.slice` | 14 Nov 2026 |
| Application code of #19, #20 and #21 deployed (the deploy copies files only; it installs no unit) | 14–15 Nov 2026 |
| Migration `20261114_request_document_views` | 14 Nov 2026 |

**0. Read-only readiness check (approval: read-only).**
- free memory and disk; PHP CLI modules (`curl`, `pdo_mysql`, `gd`; without GD the smoke
  test draws its image with `pdftoppm`);
- `systemctl --version` (259);
- `/run/mysqld/mysqld.sock` exists;
- `apt-cache policy acl`;
- which units are installed (the Step 12 guard?);
- the worker copy's result version (`bin/ai-worker-compat-check.php`);
- `ollama list` (name and digest);
- `ai_verification_jobs` counts by status (expected: none);
- `SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT` is set nowhere.

**Window A: worker (#19, #20, #21). AI stays off.**
1. Back up: database dump (SHA-256 recorded); `/etc/smart-assess*`;
   `/etc/systemd/system/smart-assess*`; `/opt/smart-assess-ai-worker`;
   `/etc/smart-assess-ai-worker-runtime/`.
2. Refresh the worker: install `deploy/sbin/smart-assess-ai-worker-refresh.sh`, run it with
   `--check-only`, then run it. Check that health is `{"ok":true}` and that the worker
   answers result version 2.
3. Ollama link (#19, #21):
   - install `smart-assess-ai-ollama-link.socket` and `.service`;
   - add `SMART_ASSESS_WORKER_OLLAMA_SOCKET=/run/smart-assess-ai-ollama/ollama.sock` to
     `/etc/smart-assess-ai-worker/worker.env`, and
     `env[SMART_ASSESS_WORKER_OLLAMA_SOCKET] = $SMART_ASSESS_WORKER_OLLAMA_SOCKET` to the
     runtime `php-fpm.conf`;
   - install `smart-assess-ai-worker-fpm.service.d/50-private-network.conf` (the #21 version);
   - `daemon-reload`; enable `--now` the link socket; restart the pool (lighttpd restarts
     with it).
4. lighttpd (#20):
   - add `server.systemd-socket-activation = "enable"` to the runtime `lighttpd.conf`;
   - install `smart-assess-ai-worker-http.socket`, `smart-assess-ai-worker.service.d/50-private-network.conf`
     and the updated `smart-assess-ai-egress-guard.sh`;
   - stop lighttpd; enable `--now` the HTTP socket; start lighttpd.
5. Guard (Step 12), if the readiness check shows it is missing: the guard service and timer,
   and `nftables.service.d/50-smart-assess-ai-egress.conf`.
6. Check, without touching the firewall:
   - health;
   - `systemctl show -p Type,StatusText smart-assess-ai-worker-fpm` shows `notify`, "Ready";
   - the pool's syslog lines (`journalctl _PID=<pool PID> _TRANSPORT=syslog`);
   - `nsenter -t <PID> -m ls -A /run` shows `smart-assess-ai-ollama smart-assess-ai-worker systemd`
     for the pool and `smart-assess-ai-worker` for lighttpd.
7. Real OCR and model, synthetic document only:

   ```sh
   sudo systemd-run --pipe --wait --collect -p DynamicUser=yes -p SupplementaryGroups=www-data -p PrivateTmp=yes \
     -p EnvironmentFile=/etc/smart-assess-ai-worker/worker.env -p Environment=SMART_ASSESS_AI_WORKER_URL=http://127.0.0.1:8095 \
     /usr/bin/php /var/www/smart-assess/bin/ai-worker-smoke-test.php
   ```

   All 7 checks must pass: Tesseract read the image, the model ran (name and digest as in
   `ollama list`), result version 2. Record the time, and the slice's `memory.peak`.
   - Rollback: restore the backed-up units, configuration and `/opt` copy, `daemon-reload`,
     restart the worker units.

**Window B: dispatcher. It stays unable to run.**
1. `sudo apt-get install acl` (Ubuntu archive, free).
2. Accounts: the `sa-ai-dispatch` user and the `sa-ai-read` group, as in the dispatcher
   unit's header.
3. MySQL account `smart_assess_ai_dispatch` from `deploy/mysql/ai-dispatch-db-user.sql.example`,
   with a generated password kept only in `/etc/smart-assess/ai-dispatch.env` (root 0600).
4. Check the account: `SHOW GRANTS` equals the example exactly. These must be refused:
   - reading `requests.email`, `contact_number` and the address;
   - reading `notifications`;
   - any write to `requests` or `request_status_log`.
5. Install the rest:
   - the permissions script (it now also grants read of `bin/ai-worker-smoke-test.php`) and
     its service;
   - `ai-dispatch-egress.nft` and its service;
   - `smart-assess-ai-worker-link.socket` and `.service`;
   - `smart-assess-ai-dispatch.service` (the #21 version);
   - `smart-assess-ai-dispatch.timer`, installed but **disabled**.
6. Write `ai-dispatch.env` with the worker secret and the database settings, **without**
   `SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT`.
7. Check:
   - `sudo systemctl start smart-assess-ai-dispatch.service` fails with exit 2 (no effective
     date): proof that it cannot run;
   - the permissions service succeeds;
   - the dispatcher's `/run` shows only `mysqld smart-assess-ai-link`;
   - the smoke test passes through the dispatcher's own path:

   ```sh
   sudo systemctl start smart-assess-ai-dispatch-permissions.service
   sudo systemd-run --pipe --wait --collect --uid=sa-ai-dispatch --gid=sa-ai-dispatch -p SupplementaryGroups=sa-ai-read \
     -p PrivateNetwork=yes -p EnvironmentFile=/etc/smart-assess/ai-dispatch.env \
     -p Environment=SMART_ASSESS_AI_WORKER_SOCKET=/run/smart-assess-ai-link/worker.sock \
     /usr/bin/php /var/www/smart-assess/bin/ai-worker-smoke-test.php
   ```

   - Rollback: disable and remove the units, `DROP USER 'smart_assess_ai_dispatch'@'localhost'`,
     remove the environment file.

**Activation with one controlled test (approval, including the synthetic request).**

Privacy Policy: the corrected text describes the single server as built (no separate
document-check server, no claim of an encrypted connection). It must be published (merged and
deployed) and its wording acknowledged by the Office before activation; the effective date is set
only at activation, never in the policy text.

1. Publish the approved Privacy Policy (PR and deploy).
2. Set the same `SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT` in `apache.env` and `ai-dispatch.env`;
   reload Apache. The timer stays disabled.
3. A test client account submits one request with synthetic documents only (never a real ID),
   labelled as a test.
4. Find its job:

   ```sql
   SELECT j.id, j.status FROM ai_verification_jobs j JOIN requests r ON r.id = j.request_id WHERE r.reference_no = '<test reference>';
   ```

   It must be `pending`.
5. Process exactly that job. Any other waiting job (a real submission that arrived meanwhile) is
   never claimed; the run prints how many it left waiting:

   ```sh
   sudo systemctl start smart-assess-ai-dispatch-egress.service smart-assess-ai-dispatch-permissions.service
   sudo systemd-run --pipe --wait --collect --uid=sa-ai-dispatch --gid=sa-ai-dispatch -p SupplementaryGroups=sa-ai-read \
     -p PrivateNetwork=yes -p EnvironmentFile=/etc/smart-assess/ai-dispatch.env \
     -p Environment=SMART_ASSESS_AI_WORKER_SOCKET=/run/smart-assess-ai-link/worker.sock -p WorkingDirectory=/var/www/smart-assess \
     /usr/bin/php -d memory_limit=512M /var/www/smart-assess/bin/ai-verification-dispatch.php --only-job=<ID>
   ```

   `--only-job` refuses any malformed or unknown argument (exit 2) and a job that is not waiting
   (exit 4). In both cases nothing is processed.
6. Check, with the logins done by the Office, never by a script:
   - **Staff:** the result appears; the originals open; "reviewed" is allowed only after
     opening them.
   - **Admin, Department Head and the test client:** no AI result, finding or OCR text;
     originals refused.
   - **Database:** the request's status and `request_status_log` are unchanged; no new
     `notifications` row; no SMS or Gmail attempt in the logs.
7. Keep the test request; record it with the other test records.
8. `sudo systemctl enable --now smart-assess-ai-dispatch.timer`. Brief Staff that results are
   advice only.
   - Rollback: `systemctl disable --now smart-assess-ai-dispatch.timer` (jobs wait;
     submissions unaffected). To withdraw fully, clear the effective date on both sides and
     restore the previous Privacy Policy page.

### Deployment sequence (original two-server plan, superseded)

Replaced by "Production installation checklist (single server, as built)" above.

### Checklist before production (original two-server plan, superseded)

Replaced by "Production installation checklist (single server, as built)" above.

## Performance (Step 12 benchmark, 14 Nov 2026)

Measured locally with synthetic documents only (fictional names and numbers; clear, multi-page,
low-quality, photographed and number-heavy scans), the real `AiDocumentWorker`, Tesseract 5.5 and
llama3.2:3b (the same digest as production) on one CPU thread without a GPU. Production times are
estimated from token counts at the rates measured on the server in Step 4/5 (about 35 prompt and
4 generated tokens per second).

* **The model is 95–98 % of a job.** OCR and the rule checks take 1–5 s per job; the model call
  45–120 s locally, about 70–150 s estimated in production (a 4-document land transfer is the
  slowest). Generation (output tokens) is the larger share at production speed.
* **OCR settings stay as they are** (`-r 150 -scale-to 2000`, Tesseract defaults). Higher resolution
  (200 or 300 dpi, 2500 px) recovered 8-pt text on clean scans but multiplied errors on 120–150 dpi
  noisy scans (character error 42 % → 73–100 %); Sauvola thresholding fixed one noisy scan
  (42 % → 8 %) but destroyed a dark one (8 % → 83 %). No setting was at least as accurate on every
  document, and OCR is not where the time goes.
* **Implemented:** the model is asked for a shorter `note` (60) and `explanation` (100 characters),
  which are never shown. 9 jobs: output tokens −15 %, estimated time −9 %, document-type answers,
  evidence quotes and manual-review flags unchanged, fewer repeated codes (48 → 19) and wrong
  "missing" claims (47 % → 38 %). The validator's limits are unchanged.
* **Measured, not changed:** duplicate jobs are already impossible (one job per request, leases, one
  dispatcher at a time); a model retry never repeats OCR; OCR text is already whitespace-collapsed;
  `num_predict` 1500 is never reached (answers end at 90–340 tokens). The model reloads after 5
  idle minutes (Ollama's default `keep_alive`; about 7 s locally, likely longer on the server):
  keeping it loaded longer holds about 2.9 GB of memory and needs a separate decision.
* **Recommended production settings:** keep `SMART_ASSESS_WORKER_OLLAMA_TIMEOUT` 180, the worker's
  job budget 900 and the dispatcher's lease; a 30 s budget always ends in `model_timeout`.
* **Model quality on these synthetic documents is weak:** about 4 in 10 "missing field" claims name a
  field that is in the text, and every "possible inconsistency" was a false positive. This is why
  all model output is shown only as unverified suggestions and never decides anything.
