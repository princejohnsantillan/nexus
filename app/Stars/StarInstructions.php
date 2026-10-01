<?php

declare(strict_types=1);

namespace App\Stars;

use App\Models\Connection;
use App\Models\Star;
use Illuminate\Database\Eloquent\Collection;

/**
 * The server instructions a Star sends clients: what the Star is, how its
 * tools are named, its description, and one line for each Connection with
 * its handle and account label, so an agent knows which tools belong to
 * which account. When the Star has siblings (Connections of the same
 * service), they also say that those tools' descriptions name their account.
 *
 * They are kept to 2,048 characters, where Claude Code cuts instructions
 * off, without losing a Connection: the Connections' "use for" notes are
 * shortened, then left out, those of Connections without a sibling first,
 * since a sibling's note is what tells its account apart; then the
 * description is shortened and every Connection's line is cut to the same
 * length, the longest that lets them all fit, keeping at least its handle.
 * Only when even the handles don't fit (far more Connections than the
 * default limits allow) is the text cut.
 */
final readonly class StarInstructions
{
    public const int MAX_LENGTH = 2048;

    /**
     * The longest each "use for" note may be, longest first, as pairs: for
     * Connections with a sibling, and for the rest. 0 leaves the notes out.
     *
     * @var list<array{int, int}>
     */
    private const array NOTE_LENGTHS = [[200, 200], [200, 80], [200, 0], [80, 0], [0, 0]];

    /**
     * The longest the description may be once the Connections' lines need cutting, longest first.
     */
    private const array SHORT_DESCRIPTION_LENGTHS = [200, 0];

    public function for(Star $star): string
    {
        $connections = $star->connections()->orderBy('name')->orderBy('connections.id')->get();
        $siblingIds = Connection::idsWithSiblings($connections);
        $hasSiblings = $siblingIds !== [];

        foreach (self::NOTE_LENGTHS as [$siblingNoteLength, $noteLength]) {
            $text = $this->compose($star, $star->description, $this->lines($connections, $siblingIds, $siblingNoteLength, $noteLength), $hasSiblings);

            if (mb_strlen($text) <= self::MAX_LENGTH) {
                return $text;
            }
        }

        $lines = $this->lines($connections, $siblingIds, 0, 0);
        $floors = array_values(array_map(fn (Connection $connection): int => mb_strlen("- {$connection->handle}: …"), $connections->all()));

        foreach (self::SHORT_DESCRIPTION_LENGTHS as $descriptionLength) {
            $description = $this->shorten($star->description ?? '', $descriptionLength);
            $available = self::MAX_LENGTH - mb_strlen($this->compose($star, $description, [], $hasSiblings)) - mb_strlen("\n\n".$this->heading()) - count($lines);
            $length = $this->lineLength($lines, $floors, $available);

            if ($length !== null) {
                return $this->compose($star, $description, array_map(
                    fn (string $line, int $floor): string => $this->shorten($line, max($length, $floor)),
                    $lines,
                    $floors,
                ), $hasSiblings);
            }
        }

        return $this->shorten($this->compose($star, null, $lines, $hasSiblings), self::MAX_LENGTH);
    }

    /**
     * @param  list<string>  $lines
     */
    private function compose(Star $star, ?string $description, array $lines, bool $hasSiblings): string
    {
        $naming = __('Tools from the ":star" Star in Nexus. Each tool\'s name starts with the handle of the Connection it belongs to and two underscores: github__search_issues is the search_issues tool of the Connection with the handle github.', [
            'star' => $star->name,
        ]);

        if ($hasSiblings) {
            $naming .= ' '.__('Some of its Connections are accounts of the same service: their tools\' descriptions start with "From" and the account, so use the account the task is for.');
        }

        $sections = [$naming];

        if (filled($description)) {
            $sections[] = $description;
        }

        if ($lines !== []) {
            $sections[] = $this->heading()."\n".implode("\n", $lines);
        }

        return implode("\n\n", $sections);
    }

    private function heading(): string
    {
        return __('Connections:');
    }

    /**
     * One line per Connection with its account label: "- github: GitHub ·
     * octocat — use for: work".
     *
     * @param  Collection<int, Connection>  $connections
     * @param  list<int>  $siblingIds
     * @param  int  $siblingNoteLength  The longest a "use for" note of a Connection with a sibling may be.
     * @param  int  $noteLength  The longest the other Connections' notes may be.
     * @return list<string>
     */
    private function lines(Collection $connections, array $siblingIds, int $siblingNoteLength, int $noteLength): array
    {
        return array_values($connections->map(function (Connection $connection) use ($siblingIds, $siblingNoteLength, $noteLength): string {
            $label = $connection->accountLabel(in_array($connection->id, $siblingIds, true) ? $siblingNoteLength : $noteLength);

            return "- {$connection->handle}: {$label}";
        })->all());
    }

    /**
     * The longest length every line can be cut to so that, never shorter
     * than its floor ("- github: …", keeping its handle), they fit in the
     * space available; null when not even the floors fit.
     *
     * @param  list<string>  $lines
     * @param  list<int>  $floors
     */
    private function lineLength(array $lines, array $floors, int $available): ?int
    {
        $lengths = array_map(mb_strlen(...), $lines);
        $total = fn (int $length): int => array_sum(array_map(fn (int $line, int $floor): int => min($line, max($length, $floor)), $lengths, $floors));

        if ($total(0) > $available) {
            return null;
        }

        [$low, $high] = [0, max([0, ...$lengths])];

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            [$low, $high] = $total($middle) <= $available ? [$middle, $high] : [$low, $middle - 1];
        }

        return $low;
    }

    /**
     * The text cut to at most this many characters, ending with "…" when
     * cut; empty when the length is 0.
     */
    private function shorten(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return $length === 0 ? '' : mb_substr($text, 0, $length - 1).'…';
    }
}
