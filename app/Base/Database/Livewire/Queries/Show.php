<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Livewire\Queries;

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Livewire\Concerns\ResolvesAvailableModels;
use App\Base\AI\Services\LlmClient;
use App\Base\Database\Exceptions\BlbQueryException;
use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\QueryExecutor;
use App\Modules\Core\User\Models\Query;
use App\Modules\Core\User\Models\User;
use App\Modules\Core\User\Models\UserPin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Show page for a single database query.
 *
 * The natural-language prompt is the primary input. AI generates
 * title, description, and SQL from it. Users explicitly save via a
 * button; inline edits for title/description/SQL are available as
 * overrides. Supports sharing (copy-on-share) with auto-pinning.
 */
class Show extends Component
{
    use ResolvesAvailableModels;
    use WithPagination;

    public ?Query $query = null;

    public bool $isNew = false;

    public string $error = '';

    public string $editName = '';

    public string $editSql = '';

    public string $editDescription = '';

    public string $editPrompt = '';

    public string $selectedModelId = '';

    public bool $isGenerating = false;

    public string $aiError = '';

    public bool $shareOpen = false;

    public string $shareSearch = '';

    public string $shareSuccess = '';

    /**
     * Initialize the component.
     *
     * When the slug is `_new`, enters creation mode with empty defaults
     * and no database record. Otherwise loads the existing query.
     *
     * @param  string  $slug  The URL slug identifying the query, or `_new`
     */
    public function mount(string $slug): void
    {
        if ($slug === '_new') {
            $this->isNew = true;
            $this->editName = __('Untitled Query');
            $this->editSql = '';
            $this->editDescription = '';
            $this->editPrompt = '';
        } else {
            $this->query = Query::query()
                ->where('user_id', auth()->id())
                ->where('slug', $slug)
                ->firstOrFail();

            $this->editName = $this->query->name;
            $this->editSql = $this->query->sql_query;
            $this->editDescription = $this->query->description ?? '';
            $this->editPrompt = $this->query->prompt ?? '';
        }

        $this->selectedModelId = $this->resolveDefaultCompositeModelId(
            (int) auth()->user()?->getCompanyId()
        );
    }

    /**
     * Persist all editable fields to the database.
     *
     * For new queries, creates the record and redirects to the proper URL.
     * For existing queries, updates in place.
     */
    public function save(): void
    {
        $validated = validator([
            'name' => $this->editName,
            'prompt' => $this->editPrompt ?: null,
            'description' => $this->editDescription ?: null,
            'sql_query' => $this->editSql ?: '',
        ], [
            'name' => ['required', 'string', 'max:255'],
            'prompt' => ['nullable', 'string'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sql_query' => ['required', 'string'],
        ])->validate();

        if ($this->isNew) {
            $userId = (int) auth()->id();

            $this->query = Query::query()->create([
                'user_id' => $userId,
                'name' => $validated['name'],
                'slug' => Query::generateSlug($validated['name'], $userId),
                'prompt' => $validated['prompt'],
                'description' => $validated['description'],
                'sql_query' => $validated['sql_query'],
            ]);

            $this->isNew = false;
            $this->redirect(
                route('admin.system.database-queries.show', $this->query->slug),
                navigate: true,
            );

            return;
        }

        $this->query->name = $validated['name'];
        $this->query->slug = Query::generateSlug($validated['name'], $this->query->user_id);
        $this->query->prompt = $validated['prompt'];
        $this->query->description = $validated['description'];
        $this->query->sql_query = $validated['sql_query'];
        $this->query->save();

        $this->dispatch('query-saved');
    }

    /**
     * Delete this query and remove any associated user pins, then redirect to the index.
     *
     * For unsaved queries, simply redirects without deleting.
     */
    public function delete(): void
    {
        if ($this->isNew || $this->query === null) {
            $this->redirect(route('admin.system.database-queries.index'), navigate: true);

            return;
        }

        $slug = $this->query->slug;

        UserPin::query()
            ->where('user_id', auth()->id())
            ->where('url', 'like', '%/database-queries/'.$slug)
            ->delete();

        Query::query()
            ->where('id', $this->query->id)
            ->where('user_id', auth()->id())
            ->delete();

        $this->redirect(route('admin.system.database-queries.index'), navigate: true);
    }

    /**
     * Run the current SQL query — persists first, then re-renders results.
     *
     * For new queries, save() creates the record and redirects; on the
     * redirected page the SQL is executed automatically in render().
     */
    public function runQuery(): void
    {
        if (trim($this->editSql) === '') {
            $this->error = __('Please enter or generate a SQL query first.');

            return;
        }

        $this->save();
        $this->error = '';
        $this->resetPage();
    }

    /**
     * Generate title, description, and SQL from the natural-language prompt.
     *
     * Resolves provider credentials, builds a schema-aware prompt, calls the
     * LLM, and populates all three fields. Does not auto-run or auto-save.
     */
    public function generateSql(): void
    {
        $error = $this->validateGenerateSqlInput();

        if ($error !== null) {
            $this->aiError = $error;

            return;
        }

        $this->isGenerating = true;
        $this->aiError = '';

        try {
            $config = $this->resolveModelConfig();

            if ($config === null) {
                return;
            }

            $schemaContext = $this->buildSchemaContext();

            $result = app(LlmClient::class)->chat(new ChatRequest(
                baseUrl: $config['base_url'],
                apiKey: $config['api_key'],
                model: $config['model'],
                messages: [
                    [
                        'role' => 'system',
                        'content' => <<<SYSTEM
                        You are a database query assistant. Given a database schema and a natural language request, generate:
                        1. A short page title (max 60 chars)
                        2. A one-sentence description of what the query shows
                        3. A single SELECT SQL query

                        Respond in EXACTLY this format (three lines, no extra text):
                        TITLE: <title>
                        DESCRIPTION: <description>
                        SQL: <sql>

                        Rules:
                        - Only SELECT statements are allowed.
                        - Use the table and column names exactly as provided in the schema.
                        - The SQL must be a single statement, no semicolons.
                        - Respond in the same language as the user's request.

                        Database schema:
                        {$schemaContext}
                        SYSTEM,
                    ],
                    [
                        'role' => 'user',
                        'content' => trim($this->editPrompt),
                    ],
                ],
                maxTokens: 1024,
                temperature: 0.1,
                timeout: 30,
                providerName: $config['provider_name'],
                apiType: $config['api_type'] ?? AiApiType::OpenAiChatCompletions,
            ));

            if (isset($result['runtime_error'])) {
                $this->aiError = $result['runtime_error']->userMessage;

                return;
            }

            $content = trim($result['content'] ?? '');

            $parsed = $this->parseGeneratedOutput($content);

            if ($parsed['title'] === '' && $parsed['description'] === '' && $parsed['sql'] === '') {
                $this->aiError = __('Could not parse the AI response. Raw output: :output', [
                    'output' => Str::limit($content, 200),
                ]);

                return;
            }

            if ($parsed['title'] !== '') {
                $this->editName = $parsed['title'];
            }
            if ($parsed['description'] !== '') {
                $this->editDescription = $parsed['description'];
            }
            if ($parsed['sql'] !== '') {
                $this->editSql = $parsed['sql'];
            }
        } catch (\Throwable $e) {
            $this->aiError = $e->getMessage() ?: __('An unexpected error occurred while generating the query.');
        } finally {
            $this->isGenerating = false;
        }
    }

    /**
     * Validate user inputs before generating SQL.
     *
     * Returns an error string if invalid, or null if inputs are valid.
     */
    private function validateGenerateSqlInput(): ?string
    {
        if (trim($this->editPrompt) === '') {
            return __('Please enter a prompt first.');
        }

        if ($this->selectedModelId === '') {
            return __('Please select an AI model.');
        }

        return null;
    }

    /**
     * Share this query with another user by creating an independent copy.
     *
     * The copy is owned by the target user, includes attribution in the
     * description, and a UserPin is auto-created for quick sidebar access.
     *
     * @param  int  $userId  The target user's ID
     */
    public function shareWith(int $userId): void
    {
        if ($this->isNew || $this->query === null) {
            return;
        }

        $newQuery = Query::query()->create([
            'user_id' => $userId,
            'name' => $this->query->name,
            'slug' => Query::generateSlug($this->query->name, $userId),
            'prompt' => $this->query->prompt,
            'sql_query' => $this->query->sql_query,
            'description' => 'Shared by '.auth()->user()->name
                .($this->query->description ? "\n\n".$this->query->description : ''),
            'icon' => $this->query->icon,
        ]);

        $pinUrl = route('admin.system.database-queries.show', $newQuery->slug);

        UserPin::query()->create([
            'user_id' => $userId,
            'label' => $newQuery->name,
            'url' => $pinUrl,
            'url_hash' => UserPin::hashUrl($pinUrl),
            'icon' => $newQuery->icon ?? 'heroicon-o-circle-stack',
            'sort_order' => (UserPin::query()->where('user_id', $userId)->max('sort_order') ?? -1) + 1,
        ]);

        $targetUser = User::query()->find($userId);
        $this->shareSuccess = __('Shared with :name.', ['name' => $targetUser?->name ?? __('user')]);
        $this->shareOpen = false;
        $this->shareSearch = '';
    }

    /**
     * Get users that this query can be shared with.
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    public function shareableUsers(): array
    {
        if ($this->shareSearch === '') {
            return [];
        }

        $search = $this->shareSearch;

        return User::query()
            ->where('id', '!=', auth()->id())
            ->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            })
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(fn (User $u): array => [
                'id' => (int) $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])
            ->all();
    }

    public function render(): View
    {
        $columns = [];
        $rows = [];
        $total = 0;
        $perPage = 25;
        $currentPage = 1;
        $lastPage = 1;

        $hasSql = ! $this->isNew
            && $this->query !== null
            && trim($this->query->sql_query) !== '';

        if ($hasSql) {
            try {
                $executor = app(QueryExecutor::class);
                $result = $executor->execute($this->query->sql_query, $this->getPage());

                $columns = $result['columns'];
                $rows = $result['rows'];
                $total = $result['total'];
                $perPage = $result['per_page'];
                $currentPage = $result['current_page'];
                $lastPage = $result['last_page'];
                $this->error = '';
            } catch (BlbQueryException $e) {
                $this->error = $e->getMessage();
            }
        }

        $savedName = $this->query?->name ?? __('Untitled Query');
        $savedSql = $this->query?->sql_query ?? '';
        $savedDescription = $this->query?->description ?? '';
        $savedPrompt = $this->query?->prompt ?? '';

        return view('livewire.admin.system.database-queries.show', [
            'columns' => $columns,
            'rows' => $rows,
            'total' => $total,
            'perPage' => $perPage,
            'currentPage' => $currentPage,
            'lastPage' => $lastPage,
            'error' => $this->error,
            'availableModels' => $this->loadAvailableModels(
                (int) auth()->user()?->getCompanyId()
            ),
            'savedName' => $savedName,
            'savedSql' => $savedSql,
            'savedDescription' => $savedDescription,
            'savedPrompt' => $savedPrompt,
        ]);
    }

    /**
     * Check whether any editable field differs from the persisted model.
     *
     * Always true for unsaved queries since nothing has been persisted.
     */
    public function getIsDirtyProperty(): bool
    {
        if ($this->isNew || $this->query === null) {
            return true;
        }

        return $this->editName !== $this->query->name
            || $this->editSql !== $this->query->sql_query
            || $this->editDescription !== ($this->query->description ?? '')
            || $this->editPrompt !== ($this->query->prompt ?? '');
    }

    /**
     * Resolve provider credentials and model for the selected model ID.
     *
     * Delegates to the shared ResolvesAvailableModels concern and maps
     * errors to the component's $aiError property.
     *
     * @return array{api_key: string, base_url: string, model: string, provider_name: string|null, api_type?: AiApiType}|null
     */
    private function resolveModelConfig(): ?array
    {
        $result = $this->resolveModelConfigFromComposite($this->selectedModelId);

        if (isset($result['error'])) {
            $this->aiError = $result['error'];

            return null;
        }

        return $result;
    }

    /**
     * Build a compact schema description for the LLM prompt.
     *
     * Lists registered tables with their column names and types.
     */
    private function buildSchemaContext(): string
    {
        $tables = TableRegistry::query()
            ->orderBy('table_name')
            ->pluck('table_name')
            ->all();

        $lines = [];
        foreach ($tables as $table) {
            try {
                $columns = Schema::getColumns($table);
                $colDefs = array_map(
                    fn (array $col): string => $col['name'].' '.$col['type_name'],
                    $columns,
                );
                $lines[] = $table.'('.implode(', ', $colDefs).')';
            } catch (\Throwable) {
                continue;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Parse the structured LLM output into title, description, and SQL.
     *
     * Expected format:
     *   TITLE: <title>
     *   DESCRIPTION: <description>
     *   SQL: <sql>
     *
     * @return array{title: string, description: string, sql: string}
     */
    private function parseGeneratedOutput(string $content): array
    {
        $title = '';
        $description = '';
        $sql = '';

        if (preg_match('/^TITLE:\s*(.+)$/mi', $content, $m)) {
            $title = trim($m[1]);
        }

        if (preg_match('/^DESCRIPTION:\s*(.+)$/mi', $content, $m)) {
            $description = trim($m[1]);
        }

        if (preg_match('/^SQL:\s*(.+)/msi', $content, $m)) {
            $sql = trim($m[1]);
            $sql = preg_replace('/^```(?:sql)?\s*/i', '', $sql);
            $sql = preg_replace('/\s*```\s*$/', '', $sql);
            $sql = rtrim(trim($sql), ';');
        }

        return ['title' => $title, 'description' => $description, 'sql' => $sql];
    }
}
