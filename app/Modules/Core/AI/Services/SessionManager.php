<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\Support\File as BlbFile;
use App\Base\Support\Json as BlbJson;
use App\Modules\Core\AI\DTO\Session;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;

class SessionManager
{
    private string $basePath;

    public function __construct()
    {
        $this->basePath = config('ai.workspace_path');
    }

    /**
     * Create a new session for a Agent.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string|null  $title  Optional session title
     */
    public function create(int $employeeId, ?string $title = null): Session
    {
        $id = now(env('BLB_LOCAL_TIMEZONE', config('app.timezone')))->format('Ymd-His');
        $now = new DateTimeImmutable;

        $session = new Session(
            id: $id,
            employeeId: $employeeId,
            channelType: 'web',
            title: $title,
            createdAt: $now,
            lastActivityAt: $now,
            transcriptVersion: 2,
        );

        $dir = $this->sessionsPath($employeeId);
        BlbFile::ensureDirectory($dir);

        // Write meta file
        BlbFile::put(
            $this->metaPath($employeeId, $id),
            json_encode($session->toMeta(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // Create empty JSONL transcript file
        touch($this->transcriptPath($employeeId, $id));

        return $session;
    }

    /**
     * List all sessions for a Agent, sorted by last activity (newest first).
     *
     * @param  int  $employeeId  Agent employee ID
     * @return list<Session>
     */
    public function list(int $employeeId): array
    {
        $dir = $this->sessionsPath($employeeId);

        if (! is_dir($dir)) {
            return [];
        }

        $metaFiles = glob($dir.'/*.meta.json') ?: [];
        $sessions = [];

        foreach ($metaFiles as $file) {
            $content = file_get_contents($file);
            $data = $content === false ? null : BlbJson::decodeArray($content);

            if ($data !== null) {
                $sessions[] = Session::fromMeta($data);
            }
        }

        usort($sessions, fn (Session $a, Session $b) => $b->lastActivityAt <=> $a->lastActivityAt);

        return $sessions;
    }

    /**
     * Get a single session by ID.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session UUID
     */
    public function get(int $employeeId, string $sessionId): ?Session
    {
        $path = $this->metaPath($employeeId, $sessionId);

        if (! file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        $data = $content === false ? null : BlbJson::decodeArray($content);

        return $data !== null ? Session::fromMeta($data) : null;
    }

    /**
     * Update the last_activity_at timestamp on a session.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session UUID
     */
    public function touch(int $employeeId, string $sessionId): void
    {
        $session = $this->get($employeeId, $sessionId);

        if ($session === null) {
            return;
        }

        $updated = new Session(
            id: $session->id,
            employeeId: $session->employeeId,
            channelType: $session->channelType,
            title: $session->title,
            createdAt: $session->createdAt,
            lastActivityAt: new DateTimeImmutable,
            transcriptVersion: $session->transcriptVersion,
            llm: $session->llm,
        );

        file_put_contents(
            $this->metaPath($employeeId, $sessionId),
            json_encode($updated->toMeta(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Update the session title.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session UUID
     * @param  string  $title  New title
     */
    public function updateTitle(int $employeeId, string $sessionId, string $title): void
    {
        $session = $this->get($employeeId, $sessionId);

        if ($session === null) {
            return;
        }

        $updated = new Session(
            id: $session->id,
            employeeId: $session->employeeId,
            channelType: $session->channelType,
            title: $title,
            createdAt: $session->createdAt,
            lastActivityAt: $session->lastActivityAt,
            transcriptVersion: $session->transcriptVersion,
            llm: $session->llm,
        );

        file_put_contents(
            $this->metaPath($employeeId, $sessionId),
            json_encode($updated->toMeta(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Store a user-selected model override in the session's LLM state.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session ID
     * @param  string  $modelId  Model ID to override with
     */
    public function updateModelOverride(int $employeeId, string $sessionId, string $modelId): void
    {
        $session = $this->get($employeeId, $sessionId);

        if ($session === null) {
            return;
        }

        $llm = $session->llm ?? [];
        $llm['model_override'] = $modelId;

        $updated = new Session(
            id: $session->id,
            employeeId: $session->employeeId,
            channelType: $session->channelType,
            title: $session->title,
            createdAt: $session->createdAt,
            lastActivityAt: $session->lastActivityAt,
            transcriptVersion: $session->transcriptVersion,
            llm: $llm,
        );

        file_put_contents(
            $this->metaPath($employeeId, $sessionId),
            json_encode($updated->toMeta(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Delete a session and its transcript.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session UUID
     */
    public function delete(int $employeeId, string $sessionId): void
    {
        $meta = $this->metaPath($employeeId, $sessionId);
        $transcript = $this->transcriptPath($employeeId, $sessionId);

        if (file_exists($meta)) {
            unlink($meta);
        }

        if (file_exists($transcript)) {
            unlink($transcript);
        }
    }

    /**
     * Get the sessions directory path for a Agent.
     *
     * Regular agent: workspace/{employee_id}/sessions
     * Lara:       workspace/{LARA_ID}/sessions/{user_id}  (per-user isolation)
     */
    public function sessionsPath(int $employeeId): string
    {
        $this->assertCanAccessAgent($employeeId);

        $base = $this->basePath.'/'.$employeeId.'/sessions';

        if ($employeeId === Employee::LARA_ID) {
            return $base.'/'.auth()->id();
        }

        return $base;
    }

    /**
     * Get the meta file path for a session.
     */
    public function metaPath(int $employeeId, string $sessionId): string
    {
        return $this->sessionsPath($employeeId).'/'.$sessionId.'.meta.json';
    }

    /**
     * Get the JSONL transcript file path for a session.
     */
    public function transcriptPath(int $employeeId, string $sessionId): string
    {
        return $this->sessionsPath($employeeId).'/'.$sessionId.'.jsonl';
    }

    /**
     * Ensure the current authenticated user can access the Agent's sessions.
     *
     * Two explicit strategies — no silent fallback between them:
     *  - Lara (Employee::LARA_ID): any authenticated user; sessions are per-user isolated via path.
     *  - Regular agent: supervisor-scoped — the user's employee must be the agent's direct supervisor.
     *
     * @throws AuthorizationException
     */
    private function assertCanAccessAgent(int $employeeId): void
    {
        $user = auth()->user();

        if ($employeeId === Employee::LARA_ID) {
            if (! $user instanceof User) {
                throw new AuthorizationException(__('Unauthorized Agent session access.'));
            }

            return;
        }

        if (! $user instanceof User || ! $user->canAccessSupervisedAgent($employeeId)) {
            throw new AuthorizationException(__('Unauthorized Agent session access.'));
        }
    }
}
