# AI Tool Framework

**Document Type:** Architecture Reference
**Module:** `app/Base/AI`
**Last Updated:** 2026-03-09
**Related:** `docs/architecture/ai/capability-map.md` (tool roadmap), `docs/architecture/ai/lara.md` (Lara), `docs/architecture/ai/agent-model.md` (agent model)

---

## 1. Problem Essence

Agent tools share structural patterns — argument validation, schema declaration, error formatting, action dispatch — that were duplicated across every tool. The tool framework extracts these into `Base/AI`, providing deep abstractions that make new tools trivial to build and existing tools thinner.

---

## 2. Architecture Overview

```
Base/AI (Framework Infrastructure)          Core/AI (Module Implementation)
┌─────────────────────────────────┐        ┌──────────────────────────────┐
│ Contracts/Tool                  │◄───────│ Tools/BashTool               │
│ Tools/AbstractTool              │◄───────│ Tools/QueryDataTool          │
│ Tools/AbstractActionTool        │◄───────│ Tools/BrowserTool (11 acts)  │
│ Tools/Schema/ToolSchemaBuilder  │        │ Tools/MessageTool  (8 acts)  │
│ Tools/ToolResult                │        │ ...18 more tools             │
│ Tools/ToolArgumentException     │        │                              │
│ Enums/ToolCategory              │        │ Services/                    │
│ Enums/ToolRiskClass             │        │   AgentToolRegistry  │
│ Tools/Concerns/                 │        │   AgenticRuntime             │
│   FormatsProcessResult          │        └──────────────────────────────┘
└─────────────────────────────────┘
```

**Base/AI** owns the tool contract, base classes, schema builder, result types, and classification enums. **Core/AI** owns the concrete tools, registry, and runtime. This separation means:

- New tools need only extend a base class and implement business logic
- Framework infrastructure (error handling, schema validation, action dispatch) lives in one place
- Classification enums are framework-level concepts, not module internals

---

## 3. Tool Contract

```php
namespace App\Base\AI\Contracts;

interface Tool
{
    public function name(): string;
    public function description(): string;
    public function parametersSchema(): array;
    public function requiredCapability(): ?string;
    public function category(): ToolCategory;
    public function riskClass(): ToolRiskClass;
    public function execute(array $arguments): string;
}
```

Every tool self-declares its **category** (UI grouping) and **risk class** (safety classification). The registry reads these directly — no separate metadata mapping required.

| Method | Purpose |
|--------|---------|
| `name()` | Unique identifier used in LLM function calling |
| `description()` | LLM-facing description of capabilities |
| `parametersSchema()` | OpenAI-compatible JSON Schema for parameters |
| `requiredCapability()` | AuthZ capability key (null = authenticated-only) |
| `category()` | `ToolCategory` enum for UI catalog grouping |
| `riskClass()` | `ToolRiskClass` enum for safety badges and audit |
| `execute()` | Entry point — receives parsed arguments, returns string |

---

## 4. Abstract Base Classes

### 4.1 AbstractTool — Standard Tools

For tools with a single operation. Seals `execute()` to provide uniform error handling and exposes `handle()` for business logic.

```
┌─────────────────────────────┐
│ AbstractTool                │
├─────────────────────────────┤
│ final execute(args): string │ ← Catches ToolArgumentException
│ abstract handle(args): str  │ ← Your logic here
│ abstract schema(): ?Builder │ ← Fluent schema definition
│ requireString(args, key)    │
│ optionalString(args, key)   │
│ requireInt(args, key, ...)  │
│ optionalInt(args, key, ...) │
│ optionalBool(args, key)     │
│ requireEnum(args, key, ...) │
└─────────────────────────────┘
```

**Error contract:** Throw `ToolArgumentException` for input validation errors — `AbstractTool` catches it and returns `"Error: {message}"` to the LLM. Other exceptions propagate to the registry's error handler.

**Typed argument extractors:**

| Method | Returns | Behavior |
|--------|---------|----------|
| `requireString($args, $key)` | `string` | Trims; throws if missing/empty |
| `optionalString($args, $key)` | `?string` | Trims; returns null if missing/empty |
| `requireInt($args, $key, $min, $max)` | `int` | Throws if missing; clamps to range |
| `optionalInt($args, $key, $default, $min, $max)` | `int` | Returns default if missing; clamps |
| `optionalBool($args, $key, $default)` | `bool` | Returns default if missing |
| `requireEnum($args, $key, $allowed, $default)` | `string` | Validates against allowed list; uses default or throws |

### 4.2 AbstractActionTool — Multi-Action Tools

For tools that expose multiple operations through a single LLM function (e.g., BrowserTool with 11 actions, MessageTool with 8). Extends `AbstractTool` and seals `handle()` to add action dispatch.

```
┌──────────────────────────────────┐
│ AbstractActionTool               │
├──────────────────────────────────┤
│ final handle(args): string       │ ← Extracts + validates 'action'
│ abstract actions(): array        │ ← ['navigate', 'snapshot', ...]
│ abstract handleAction(act, args) │ ← Dispatch per action
│ parametersSchema()               │ ← Auto-injects 'action' enum
└──────────────────────────────────┘
```

**What it does automatically:**
1. Extracts the `action` parameter from arguments
2. Validates it against the `actions()` list
3. Injects `action` as a required string enum into the schema
4. Routes to `handleAction($action, $arguments)`

**You do NOT include `action` in your `schema()` definition** — the base class adds it.

---

## 5. Schema Builder

`ToolSchemaBuilder` replaces hand-crafted JSON Schema arrays with a fluent, validated builder.

```php
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;

protected function schema(): ?ToolSchemaBuilder
{
    return ToolSchemaBuilder::make()
        ->string('command', 'The bash command to execute')->required()
        ->integer('timeout', 'Max seconds', min: 1, max: 300)
        ->boolean('verbose', 'Show detailed output')
        ->string('format', 'Output format', ['json', 'text', 'markdown'])
        ->array('tags', 'Filter tags', ['type' => 'string'])
        ->oneOf('recipient', 'Target user', [
            ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer']]],
            ['type' => 'object', 'properties' => ['email' => ['type' => 'string']]],
        ]);
}
```

**API:**

| Method | Description |
|--------|-------------|
| `string($name, $desc, ?$enum)` | String property, optional enum constraint |
| `integer($name, $desc, ?$min, ?$max)` | Integer with optional range |
| `boolean($name, $desc)` | Boolean flag |
| `array($name, $desc, $items)` | Array with item schema |
| `oneOf($name, $desc, $schemas)` | Union type |
| `required()` | Marks the last-added property as required |
| `build()` | Returns the OpenAI-compatible schema array |

**Output format:**
```json
{
    "type": "object",
    "properties": {
        "command": { "type": "string", "description": "The bash command to execute" }
    },
    "required": ["command"]
}
```

---

## 6. Result Types

### 6.1 ToolResult (Structured)

`ToolResult` provides typed result construction with backward-compatible string coercion.

```php
use App\Base\AI\Tools\ToolResult;

// Success
return ToolResult::success('Query returned 5 rows.');

// Error (auto-prefixes "Error: ")
return ToolResult::error('Table not found.');

// Success with client-side action
return ToolResult::withClientAction('Navigating to dashboard.', [
    'type' => 'navigate',
    'url' => '/dashboard',
]);
```

**Properties:** `content` (string), `isError` (bool), `clientActions` (array).

**Backward compatibility:** Implements `Stringable`. `__toString()` embeds client actions as `<agent-action>` XML blocks, so existing code consuming strings works unchanged.

### 6.2 ToolArgumentException

Thrown by argument extractors or tool logic for input validation failures.

```php
throw new ToolArgumentException('Command must not be empty.');
// → AbstractTool returns "Error: Command must not be empty." to LLM
```

---

## 7. Classification Enums

### 7.1 ToolCategory

UI catalog grouping. Each category has a `label()` for display and `sortOrder()` for ordering.

| Value | Label | Sort | Example Tools |
|-------|-------|------|---------------|
| `DATA` | Data & Queries | 1 | QueryDataTool |
| `WEB` | Web & Search | 2 | WebFetchTool, WebSearchTool |
| `MEMORY` | Memory & Knowledge | 3 | MemorySearchTool, GuideTool |
| `SYSTEM` | System & Runtime | 4 | BashTool, ArtisanTool, SystemInfoTool |
| `BROWSER` | Browser Automation | 5 | BrowserTool, NavigateTool, WriteJsTool |
| `DELEGATION` | Task Delegation | 6 | DelegateTaskTool, AgentListTool |
| `MESSAGING` | Messaging | 7 | MessageTool, NotificationTool |
| `AUTOMATION` | Automation | 8 | ScheduleTaskTool |
| `MEDIA` | Media Analysis | 9 | ImageAnalysisTool, DocumentAnalysisTool |

### 7.2 ToolRiskClass

Safety classification for UI badges and audit policy.

| Value | Label | Color | Description |
|-------|-------|-------|-------------|
| `READ_ONLY` | Read Only | success (green) | No side effects |
| `INTERNAL` | Internal | default (gray) | Internal operations only |
| `EXTERNAL_IO` | External I/O | warning (yellow) | Network I/O to external services |
| `BROWSER` | Browser | warning (yellow) | Browser automation |
| `MESSAGING` | Messaging | warning (yellow) | Sends notifications or messages |
| `HIGH_IMPACT` | High Impact | danger (red) | System-wide changes possible |

---

## 8. Creating a New Tool

### 8.1 Simple Tool (Single Operation)

```php
namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;

class MyTool extends AbstractTool
{
    public function name(): string
    {
        return 'my_tool';
    }

    public function description(): string
    {
        return 'Describe what this tool does for the LLM.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::DATA;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::READ_ONLY;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_my_tool.execute';
    }

    protected function schema(): ?ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('query', 'The search query')->required()
            ->integer('limit', 'Max results', min: 1, max: 100);
    }

    protected function handle(array $arguments): string
    {
        $query = $this->requireString($arguments, 'query');
        $limit = $this->optionalInt($arguments, 'limit', 10, 1, 100);

        // Business logic here
        $results = $this->search($query, $limit);

        return json_encode($results, JSON_PRETTY_PRINT);
    }
}
```

### 8.2 Multi-Action Tool

```php
namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractActionTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;

class MyActionTool extends AbstractActionTool
{
    public function name(): string
    {
        return 'my_action_tool';
    }

    public function description(): string
    {
        return 'Manage resources: create, read, update, or delete.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::DATA;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::INTERNAL;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_my_action.execute';
    }

    protected function actions(): array
    {
        return ['create', 'read', 'update', 'delete'];
    }

    // Do NOT include 'action' — auto-injected by AbstractActionTool
    protected function schema(): ?ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('resource_id', 'Resource identifier')->required()
            ->string('data', 'JSON payload for create/update');
    }

    protected function handleAction(string $action, array $arguments): string
    {
        return match ($action) {
            'create' => $this->handleCreate($arguments),
            'read'   => $this->handleRead($arguments),
            'update' => $this->handleUpdate($arguments),
            'delete' => $this->handleDelete($arguments),
        };
    }

    private function handleCreate(array $args): string { /* ... */ }
    private function handleRead(array $args): string { /* ... */ }
    private function handleUpdate(array $args): string { /* ... */ }
    private function handleDelete(array $args): string { /* ... */ }
}
```

### 8.3 Registration

Register tools in the module's `ServiceProvider`:

```php
// app/Modules/Core/AI/ServiceProvider.php
$registry = $this->app->make(AgentToolRegistry::class);
$registry->register(new MyTool());
$registry->register($this->app->make(MyActionTool::class)); // If DI needed
```

### 8.4 AuthZ Capability

Add the capability to `app/Base/Authz/Config/authz.php` and assign it to appropriate roles. Then sync:

```bash
php artisan db:seed --class="App\Base\Authz\Database\Seeders\AuthzRoleSeeder"
php artisan db:seed --class="App\Base\Authz\Database\Seeders\AuthzRoleCapabilitySeeder"
```

---

## 9. File Structure

```
app/Base/AI/
├── Contracts/
│   └── Tool.php                     # Core tool interface
├── Enums/
│   ├── ToolCategory.php             # UI catalog grouping
│   └── ToolRiskClass.php            # Safety classification
├── Tools/
│   ├── AbstractTool.php             # Single-operation base class
│   ├── AbstractActionTool.php       # Multi-action base class
│   ├── ToolResult.php               # Structured result type
│   ├── ToolArgumentException.php    # Input validation exception
│   ├── Concerns/
│   │   └── FormatsProcessResult.php # Process output formatting trait
│   └── Schema/
│       └── ToolSchemaBuilder.php    # Fluent JSON Schema builder
├── Config/
│   └── ai.php                       # LLM defaults, provider overlay
├── Services/                        # LlmClient, ModelCatalog, etc.
├── Console/Commands/                # blb:ai:catalog:sync
├── DTO/                             # Value objects
├── Providers/Help/                  # Provider help text
└── ServiceProvider.php              # Registration & binding
```

---

## 10. Design Rationale

### Why Base/AI, not Core/AI?

Tools are **framework infrastructure**. Any module — Core, Business, or Extension — should be able to define tools by extending `AbstractTool`. Placing the contract and base classes in `Base/AI` follows BLB's layer convention: `Base/` provides foundational abstractions that `Modules/` consumes.

### Why sealed `execute()`?

The template method pattern ensures every tool gets uniform error handling, argument validation, and future concerns (logging, metrics, rate limiting) without tool authors needing to remember them.

### Why `AbstractActionTool`?

Tools like BrowserTool (11 actions) and MessageTool (8 actions) share a dispatch pattern: validate action → route to handler. Extracting this into a base class eliminates ~20 lines of boilerplate per action tool and ensures consistent action validation.

### Why `ToolSchemaBuilder`?

Hand-crafted JSON Schema arrays are verbose and error-prone. The builder provides:
- Compile-time method signatures (IDE autocomplete)
- Consistent output format
- Composable with `required()` chaining
- Single point of change if schema format evolves

---

## Revision History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-03-09 | AI + Kiat | Initial — Tool contract, AbstractTool, AbstractActionTool, ToolSchemaBuilder, ToolResult, enums, migration of 20 tools |
