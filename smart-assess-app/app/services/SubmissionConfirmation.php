<?php
declare(strict_types=1);

/**
 * Sends the automatic confirmations of new submissions that the website queued
 * (notifications.type = 'submission_confirmation', send_state = 'queued').
 *
 * Run by bin/send-submission-confirmations.php right after a submission, in the
 * background, so the client never waits for IPROG or Gmail.
 *
 * Exactly once: a confirmation is claimed (queued -> sending) before anything is
 * sent, so two runs can never send the same one. It is sent with one attempt and
 * never retried automatically. If a run stops after claiming (killed, power loss),
 * the confirmation stays "sending" with an unconfirmed outcome, rather than risk a
 * second message. Outcomes match staff messages: 'sent' (accepted by IPROG or
 * Gmail; not proof of delivery), 'failed' (definitely not sent) or 'pending'
 * (unconfirmed). Logs hold the request ID and outcome only: never a number,
 * address, message text or token.
 */
final class SubmissionConfirmation
{
    /** Confirmations older than this are also sent by any later run (one that could not start). */
    private const CATCH_UP_AFTER_SECONDS = 120;

    /** Most confirmations one run sends. */
    private const BATCH = 20;

    /**
     * @return array{sent:int, failed:int, pending:int, skipped:int}
     */
    public static function run(PDO $pdo, ?int $requestId, string $iprogPath, string $mailerPath, int $statusWaitMicroseconds = 1500000): array
    {
        $summary = ['sent' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0];
        $select = $pdo->prepare(
            "SELECT id, request_id, channel, message, status_log_id FROM notifications
             WHERE type = 'submission_confirmation' AND send_state = 'queued'
               AND (request_id = :request OR created_at <= NOW() - INTERVAL " . self::CATCH_UP_AFTER_SECONDS . ' SECOND)
             ORDER BY id LIMIT ' . self::BATCH
        );
        $select->execute(['request' => $requestId ?? 0]);
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $claim = $pdo->prepare("UPDATE notifications SET send_state = 'sending' WHERE id = :id AND send_state = 'queued'");
            $claim->execute(['id' => (int) $row['id']]);
            if ($claim->rowCount() !== 1) {
                $summary['skipped']++;   // another run has it
                continue;
            }
            $request = $pdo->prepare('SELECT reference_no, flow, contact_number, email, first_name, last_name FROM requests WHERE id = :id');
            $request->execute(['id' => (int) $row['request_id']]);
            $details = $request->fetch(PDO::FETCH_ASSOC);
            $provider = ['message_id' => null, 'status' => null];
            if (!is_array($details)) {
                $outcome = 'failed';
            } elseif ($row['channel'] === 'sms') {
                $outcome = self::sendSms($iprogPath, (string) $details['contact_number'], (string) $row['message'], $provider, $statusWaitMicroseconds);
            } else {
                $outcome = self::sendEmail($mailerPath, $details);
            }
            self::record($pdo, $row, $outcome, $provider);
            $summary[$outcome]++;
            error_log('[SMART_ASSESS_CONFIRM] request ID ' . (int) $row['request_id'] . '; ' . $row['channel'] . ' confirmation outcome ' . $outcome . '.');
        }
        return $summary;
    }

    /** One IPROG attempt. Same outcome rules as staff SMS (web/api.php sa_sms_deliver). */
    private static function sendSms(string $iprogPath, string $phone, string $message, array &$provider, int $statusWaitMicroseconds): string
    {
        $enabled = strtolower(trim((string) getenv('SMART_ASSESS_SMS_ENABLED')));
        if (!in_array($enabled, ['1', 'true', 'yes', 'on'], true)) {
            return 'failed';   // turned off since it was queued: nothing is sent
        }
        try {
            if (!class_exists('IprogSmsService', false)) {
                if (!is_file($iprogPath)) {
                    return 'failed';
                }
                require_once $iprogPath;
            }
            $service = IprogSmsService::fromEnvironment();
            $sent = $service->send($phone, $message);
            $provider['message_id'] = $sent['message_id'];
            try {
                if ($statusWaitMicroseconds > 0) {
                    usleep($statusWaitMicroseconds);
                }
                $provider['status'] = $sent['message_id'] !== null ? $service->messageStatus($sent['message_id']) : null;
            } catch (Throwable) {
                $provider['status'] = null;
            }
            // Accepted, then reported as failed: not proof that it can be sent again safely.
            return $provider['status'] !== null && preg_match('/^(failed|fail|rejected|undelivered|error|cancell?ed)$/', $provider['status'])
                ? 'pending' : 'sent';
        } catch (IprogSmsException $exception) {
            return $exception->outcome() === IprogSmsException::REJECTED ? 'failed' : 'pending';
        } catch (InvalidArgumentException | RuntimeException) {
            return 'failed';
        } catch (Throwable) {
            return 'pending';
        }
    }

    /** One Gmail attempt with the mailer's own "we received your request" email (no 'message' key). */
    private static function sendEmail(string $mailerPath, array $details): string
    {
        if (!filter_var((string) ($details['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            return 'failed';
        }
        try {
            if (!is_file($mailerPath)) {
                return 'failed';
            }
            $send = require $mailerPath;
            $send([
                'email' => (string) $details['email'],
                'first_name' => (string) $details['first_name'],
                'last_name' => (string) $details['last_name'],
                'reference_no' => (string) $details['reference_no'],
                'flow' => (string) $details['flow'],
            ]);
            return 'sent';
        } catch (Throwable $exception) {
            return str_contains($exception->getMessage(), 'did not confirm') ? 'pending' : 'failed';
        }
    }

    /** The outcome on the notification and its history row together; send_state cleared. */
    private static function record(PDO $pdo, array $row, string $outcome, array $provider): void
    {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE notifications SET delivery_status = :status, send_state = NULL, provider_message_id = :mid, provider_status = :pst WHERE id = :id')
                ->execute([
                    'status' => $outcome,
                    'mid' => self::clean($provider['message_id'] ?? null, 64),
                    'pst' => self::clean($provider['status'] ?? null, 30),
                    'id' => (int) $row['id'],
                ]);
            if ($row['status_log_id'] !== null) {
                $pdo->prepare('UPDATE request_status_log SET delivery_status = :status WHERE id = :id')
                    ->execute(['status' => $outcome, 'id' => (int) $row['status_log_id']]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // The message may have gone out: it stays "sending" (shown as unconfirmed), never queued again.
            error_log('[SMART_ASSESS_CONFIRM] Could not save the outcome of notification ID ' . (int) $row['id'] . ' (' . $outcome . ').');
        }
    }

    private static function clean(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $clean = substr((string) preg_replace('/[^\x21-\x7E]/', '', (string) $value), 0, $max);
        return $clean === '' ? null : $clean;
    }
}
