<?php

use App\Base\Settings\Database\Migrations\Concerns\RenamesSettingRows;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    use RenamesSettingRows;

    private const string OLD_KEY = 'ai.llm.agentic.max_tool_iterations';

    private const string NEW_KEY = 'ai.llm.agentic.max_tool_rounds';

    public function up(): void
    {
        $this->renameSettingRows(self::OLD_KEY, self::NEW_KEY);
    }

    public function down(): void
    {
        $this->renameSettingRows(self::NEW_KEY, self::OLD_KEY);
    }
};
