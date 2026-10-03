<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\BillingCalendar;
use Carbon\CarbonInterface;
use Database\Factories\ToolCallCountFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many tool calls a user's Stars forwarded in one billing week, which
 * starts on Monday 00:00 in Philippine time (BillingCalendar::weekStart()).
 *
 * Free's weekly limit is checked against it, and the Billing page's meter
 * shows it on every plan. Only App\Actions\CountToolCall adds to it, in
 * one statement, so it keeps no timestamps.
 *
 * The current week and the KEPT_WEEKS before it are kept; older weeks are
 * pruned daily.
 *
 * @property int $id
 * @property int $user_id
 * @property string $week_starts_on The date of the week's Monday on the billing calendar, as Y-m-d.
 * @property int $calls
 * @property-read User $user
 *
 * @method static \Database\Factories\ToolCallCountFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ToolCallCount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ToolCallCount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ToolCallCount query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ToolCallCount whereCalls($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ToolCallCount whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ToolCallCount whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ToolCallCount whereWeekStartsOn($value)
 *
 * @mixin \Eloquent
 */
#[WithoutTimestamps]
final class ToolCallCount extends Model
{
    /** @use HasFactory<ToolCallCountFactory> */
    use HasFactory, MassPrunable;

    /**
     * How many weeks before the current one are kept.
     */
    public const int KEPT_WEEKS = 8;

    /**
     * Get the attributes that should be cast. The week stays a Y-m-d string,
     * as weekOf() gives it: a date cast would store a time with it, which
     * SQLite would then compare as different text.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'calls' => 'integer',
        ];
    }

    /**
     * The value of `week_starts_on` for the billing week the moment falls
     * in: the date of its Monday on the billing calendar.
     */
    public static function weekOf(CarbonInterface $moment): string
    {
        return BillingCalendar::weekStart($moment)->toDateString();
    }

    /**
     * The weeks that started more than KEPT_WEEKS weeks before this one.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::query()->where('week_starts_on', '<', BillingCalendar::weekStart(now())->subWeeks(self::KEPT_WEEKS)->toDateString());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
