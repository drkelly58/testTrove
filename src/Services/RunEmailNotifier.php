<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\MailSettings;
use App\UserPreferences;
use PDO;

/**
 * Optional run lifecycle emails (instance must enable mail; user must opt in per preference).
 */
final class RunEmailNotifier
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MailSettings $mailSettings,
        private readonly MailService $mail,
    ) {
    }

    /**
     * Tester assigned to a run (create or reassignment).
     */
    public function notifyRunAssigned(int $runId, int $assigneeUserId, ?int $actorUserId): void
    {
        if (!$this->mailSettings->isEnabled()) {
            return;
        }
        if ($actorUserId !== null && $assigneeUserId === $actorUserId) {
            return;
        }
        $u = $this->fetchUserEmailAndPrefs($assigneeUserId);
        if ($u === null) {
            return;
        }
        $prefs = UserPreferences::decode($u['preferences'] ?? null);
        if (!($prefs[UserPreferences::KEY_EMAIL_NOTIFY_RUN_ASSIGNED] ?? false)) {
            return;
        }

        $ctx = $this->fetchRunNotificationContext($runId);
        if ($ctx === null) {
            return;
        }

        $actorLabel = $actorUserId !== null ? $this->displayNameForUser($actorUserId) : 'Someone';
        $project = $ctx['project_name'];
        $runName = $ctx['run_name'];
        $url = $this->runUrl($runId);
        $urlLine = $url !== null ? "\n\nOpen the run: {$url}\n" : "\n";

        $plain = "Hello,\n\n{$actorLabel} assigned you a test run in “{$project}”.\n\n"
            . "Run: {$runName}\n"
            . $urlLine
            . "\n— TestTrove";

        $safeProject = htmlspecialchars($project, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeRun = htmlspecialchars($runName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeActor = htmlspecialchars($actorLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linkHtml = $url !== null
            ? '<p><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Open this run</a></p>'
            : '';

        $html = '<p>Hello,</p><p><strong>' . $safeActor . '</strong> assigned you a test run in <strong>'
            . $safeProject . '</strong>.</p><p><strong>Run:</strong> ' . $safeRun . '</p>' . $linkHtml
            . '<p>— TestTrove</p>';

        $this->mail->send(
            $u['email'],
            'Test run assigned: ' . $runName,
            $plain,
            $html,
        );
    }

    /**
     * Run owner notified when an assigned run reaches auto-complete (all items pass or fail).
     */
    public function notifyAssignedRunCompleted(int $runId, ?int $executorUserId): void
    {
        if (!$this->mailSettings->isEnabled()) {
            return;
        }
        $ctx = $this->fetchRunNotificationContext($runId);
        if ($ctx === null) {
            return;
        }
        $assignedTo = $ctx['assigned_to_user_id'];
        $createdBy = $ctx['created_by_user_id'];
        if ($assignedTo === null || $createdBy === null) {
            return;
        }
        if ($assignedTo === $createdBy) {
            return;
        }

        $owner = $this->fetchUserEmailAndPrefs($createdBy);
        if ($owner === null) {
            return;
        }
        $prefs = UserPreferences::decode($owner['preferences'] ?? null);
        if (!($prefs[UserPreferences::KEY_EMAIL_NOTIFY_RUN_COMPLETED] ?? false)) {
            return;
        }

        $runName = $ctx['run_name'];
        $project = $ctx['project_name'];
        $executorLabel = $executorUserId !== null
            ? $this->displayNameForUser($executorUserId)
            : 'A tester';
        $assigneeLabel = $this->displayNameForUser($assignedTo);

        $url = $this->runUrl($runId);
        $urlNote = $url !== null ? "\n\nRun overview: {$url}\n" : "\n";

        $plain = "Hello,\n\nAn assigned test run you created is complete.\n\n"
            . "Project: {$project}\n"
            . "Run: {$runName}\n"
            . "Assigned to: {$assigneeLabel}\n"
            . "Completed by: {$executorLabel}\n"
            . $urlNote
            . "\n— TestTrove";

        $safeProject = htmlspecialchars($project, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeRun = htmlspecialchars($runName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeExec = htmlspecialchars($executorLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeAssignee = htmlspecialchars($assigneeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linkHtml = $url !== null
            ? '<p><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">View run overview</a></p>'
            : '';

        $html = '<p>Hello,</p><p>An assigned test run you created is <strong>complete</strong>.</p>'
            . '<ul><li><strong>Project:</strong> ' . $safeProject . '</li>'
            . '<li><strong>Run:</strong> ' . $safeRun . '</li>'
            . '<li><strong>Assigned to:</strong> ' . $safeAssignee . '</li>'
            . '<li><strong>Completed by:</strong> ' . $safeExec . '</li></ul>'
            . $linkHtml . '<p>— TestTrove</p>';

        $this->mail->send(
            $owner['email'],
            'Run complete: ' . $runName,
            $plain,
            $html,
        );
    }

    /**
     * @return array{email: string, preferences: ?string}|null
     */
    private function fetchUserEmailAndPrefs(int $userId): ?array
    {
        $st = $this->pdo->prepare('SELECT email, preferences FROM users WHERE id = :id LIMIT 1');
        $st->execute(['id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return ['email' => $email, 'preferences' => isset($row['preferences']) ? (string) $row['preferences'] : null];
    }

    /**
     * @return array{run_name: string, project_name: string, assigned_to_user_id: ?int, created_by_user_id: ?int}|null
     */
    private function fetchRunNotificationContext(int $runId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT r.name AS run_name, p.name AS project_name,
                    r.assigned_to_user_id, r.created_by_user_id
             FROM test_runs r
             INNER JOIN projects p ON p.id = r.project_id
             WHERE r.id = :id LIMIT 1'
        );
        $st->execute(['id' => $runId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'run_name' => (string) $row['run_name'],
            'project_name' => (string) $row['project_name'],
            'assigned_to_user_id' => isset($row['assigned_to_user_id']) && $row['assigned_to_user_id'] !== null
                && $row['assigned_to_user_id'] !== ''
                ? (int) $row['assigned_to_user_id'] : null,
            'created_by_user_id' => isset($row['created_by_user_id']) && $row['created_by_user_id'] !== null
                && $row['created_by_user_id'] !== ''
                ? (int) $row['created_by_user_id'] : null,
        ];
    }

    private function displayNameForUser(int $userId): string
    {
        $st = $this->pdo->prepare(
            'SELECT display_name, email FROM users WHERE id = :id LIMIT 1'
        );
        $st->execute(['id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return 'User #' . $userId;
        }
        $dn = trim((string) ($row['display_name'] ?? ''));
        if ($dn !== '') {
            return $dn;
        }

        return (string) ($row['email'] ?? 'User #' . $userId);
    }

    private function runUrl(int $runId): ?string
    {
        $base = $this->mailSettings->appBaseUrl();
        if ($base === '') {
            return null;
        }

        return $base . '/app/runs/' . $runId . '/overview';
    }
}
