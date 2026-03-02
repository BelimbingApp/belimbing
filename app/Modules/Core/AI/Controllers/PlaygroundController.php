<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Controllers;

use App\Modules\Core\AI\Services\DigitalWorkerRuntime;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlaygroundController
{
    /**
     * Show the Digital Worker playground.
     */
    public function index(Request $request): View
    {
        $digitalWorkers = $this->digitalWorkers($request);
        $selectedEmployeeId = (int) $request->integer('employee_id', $digitalWorkers->first()?->id ?? 0);

        $sessions = $selectedEmployeeId > 0 ? app(SessionManager::class)->list($selectedEmployeeId) : [];
        $selectedSessionId = $request->string('session_id', '')->toString();

        if ($selectedSessionId === '' && count($sessions) > 0) {
            $selectedSessionId = $sessions[0]->id;
        }

        $messages = $selectedEmployeeId > 0 && $selectedSessionId !== ''
            ? app(MessageManager::class)->read($selectedEmployeeId, $selectedSessionId)
            : [];

        $lastRunMeta = session('last_run_meta');

        return view('admin.ai.playground.index', compact('digitalWorkers', 'selectedEmployeeId', 'sessions', 'selectedSessionId', 'messages', 'lastRunMeta'));
    }

    /**
     * Create a new session.
     */
    public function createSession(Request $request): RedirectResponse
    {
        $employeeId = (int) $request->integer('employee_id');
        if ($employeeId <= 0) {
            return redirect()->route('admin.ai.playground');
        }

        $session = app(SessionManager::class)->create($employeeId);

        return redirect()->route('admin.ai.playground', ['employee_id' => $employeeId, 'session_id' => $session->id]);
    }

    /**
     * Send a message.
     */
    public function sendMessage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'session_id' => ['required', 'string'],
            'message' => ['required', 'string'],
        ]);

        $employeeId = (int) $validated['employee_id'];
        $sessionId = $validated['session_id'];
        $content = trim($validated['message']);

        if ($content === '') {
            return redirect()->route('admin.ai.playground', ['employee_id' => $employeeId, 'session_id' => $sessionId]);
        }

        $messageManager = app(MessageManager::class);
        $runtime = app(DigitalWorkerRuntime::class);

        $messageManager->appendUserMessage($employeeId, $sessionId, $content);
        $messages = $messageManager->read($employeeId, $sessionId);

        $employee = Employee::query()->find($employeeId);
        $systemPrompt = $employee?->job_description
            ? __('You are a Digital Worker. Your role: :role', ['role' => $employee->job_description])
            : __('You are a helpful Digital Worker assistant.');

        $result = $runtime->run($messages, $employeeId, $systemPrompt);

        $messageManager->appendAssistantMessage(
            $employeeId,
            $sessionId,
            $result['content'],
            $result['run_id'],
            $result['meta'],
        );

        $sessionManager = app(SessionManager::class);
        $session = $sessionManager->get($employeeId, $sessionId);

        if ($session && $session->title === null) {
            $title = mb_substr($content, 0, 60);
            if (mb_strlen($content) > 60) {
                $title .= '…';
            }
            $sessionManager->updateTitle($employeeId, $sessionId, $title);
        }

        session()->flash('last_run_meta', ['run_id' => $result['run_id'], ...$result['meta']]);

        return redirect()->route('admin.ai.playground', ['employee_id' => $employeeId, 'session_id' => $sessionId]);
    }

    /**
     * Get available digital workers.
     */
    private function digitalWorkers(Request $request)
    {
        $userEmployee = $request->user()?->employee;

        if (! $userEmployee) {
            return collect();
        }

        return Employee::query()
            ->digitalWorker()
            ->where('supervisor_id', $userEmployee->id)
            ->active()
            ->get();
    }
}
