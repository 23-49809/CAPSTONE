<?php
declare(strict_types=1);

/**
 * Removes job folders that an interrupted AI worker job left behind in the
 * worker's state folder: for example when php-fpm killed the request, the
 * machine lost power, or PHP ran out of memory, so the job's own "finally"
 * cleanup never ran. Those folders can hold a client's documents and the
 * pages rendered from them.
 *
 * Safety rules:
 *  - Call it only while holding the worker lock (worker.lock): the worker runs
 *    one job at a time under that lock, so no folder can belong to a running
 *    job. As a second guard, folders changed less than $minimumAgeSeconds ago
 *    are left alone.
 *  - Only direct children named "job-" + 32 hex characters are touched;
 *    nothing else in the state folder (nonces, the lock file) ever is.
 *  - Symbolic links are never followed: a link named like a job folder is
 *    skipped (its target is not touched), and a link inside a job folder is
 *    removed as a link only.
 *  - Every folder removed must resolve inside the state folder.
 *  - Nothing is logged except counts; never a file name or content.
 */
final class WorkerStateCleaner
{
    public const JOB_FOLDER_PATTERN = '/^job-[a-f0-9]{32}$/';
    private const MAX_DEPTH = 6;

    private readonly string $root;
    private readonly Closure $now;

    /** @param null|Closure():int $now seconds, for tests */
    public function __construct(string $stateDirectory, private readonly int $minimumAgeSeconds = 60, ?Closure $now = null)
    {
        $root = realpath($stateDirectory);
        if ($root === false || $root === DIRECTORY_SEPARATOR || !is_dir($root)) {
            throw new InvalidArgumentException('The worker state folder must be an existing folder other than /.');
        }
        if ($minimumAgeSeconds < 0) {
            throw new InvalidArgumentException('The minimum age cannot be negative.');
        }
        $this->root = $root;
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * Removes abandoned job folders. The caller must hold the worker lock.
     *
     * @return array{removed:int, failed:int, skipped:int} failed > 0 means a folder could not
     *         be removed completely (documents may remain); skipped counts links, files and
     *         folders that are too new
     */
    public function sweep(): array
    {
        $removed = 0;
        $failed = 0;
        $skipped = 0;
        foreach (scandir($this->root) ?: [] as $entry) {
            if (!preg_match(self::JOB_FOLDER_PATTERN, $entry)) {
                continue;
            }
            $path = $this->root . DIRECTORY_SEPARATOR . $entry;
            clearstatcache(true, $path);
            $info = @lstat($path);
            if ($info === false) {
                continue;   // already gone
            }
            if (is_link($path) || !is_dir($path)) {
                $skipped++;
                continue;
            }
            if ((int) $info['mtime'] > ($this->now)() - $this->minimumAgeSeconds) {
                $skipped++;
                continue;
            }
            if (realpath($path) !== $path) {
                $skipped++;
                continue;
            }
            if ($this->removeTree($path, 0)) {
                $removed++;
            } else {
                // Removing part of it changed the folder's time; restore it so the folder still
                // counts as abandoned and the failure keeps being reported until it is fixed.
                @touch($path, (int) $info['mtime']);
                $failed++;
            }
        }
        if ($removed > 0) {
            error_log('[SMART_ASSESS_WORKER] Removed ' . $removed . ' folder(s) left by interrupted jobs.');
        }
        if ($failed > 0) {
            error_log('[SMART_ASSESS_WORKER] Could not remove ' . $failed . ' folder(s) left by interrupted jobs; the worker will not accept jobs until they are removed.');
        }
        return ['removed' => $removed, 'failed' => $failed, 'skipped' => $skipped];
    }

    /** Deletes a folder and everything in it without following links. True when it is gone. */
    private function removeTree(string $directory, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH || !str_starts_with($directory, $this->root . DIRECTORY_SEPARATOR)) {
            return false;
        }
        $entries = @scandir($directory);
        if ($entries === false) {
            return false;
        }
        $complete = true;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path)) {
                $complete = @unlink($path) && $complete;   // the link only, never its target
            } elseif (is_dir($path)) {
                $complete = $this->removeTree($path, $depth + 1) && $complete;
            } else {
                $complete = @unlink($path) && $complete;
            }
        }
        return @rmdir($directory) && $complete;
    }
}
