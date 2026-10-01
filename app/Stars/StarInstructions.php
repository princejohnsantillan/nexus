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
 * which account.
 *
 * They are kept to 2,048 characters, where Claude Code cuts instructions
 * off, without losing a Connection: the Connections' "use for" notes are
 * shortened, then left out; then the description is shortened and every
 * Connection's line is cut to the same length, the longest that lets them
 * all fit, keeping at least its handle. Only when even the handles don't
 * fit (far more Connections than the default limits allow) is the text cut.
 */
final readonly class StarInstructions
{
    public const int MAX_LENGTH = 2048;

    /**
     * The longest each "use for" note may be, longest first; 0 leaves them out.
     */
    private const array NOTE_LENGTHS = [200, 80, 0];

    /**
     * The longest the description may be once the Connections' lines need cutting, longest first.
     */
    private const array SHORT_DESCRIPTION_LENGTHS = [200, 0];

    public function for(Star $star): string
    {
        $connections = $star->connections()->orderBy('name')->orderBy('connections.id')->get();

        foreach (self::NOTE_LENGTHS as $noteLength) {
            $text = $this->compose($star, $star->description, $this->lines($connections, $noteLength));

            if (mb_strlen($text) <= self::MAX_LENGTH) {
                return $text;
            }
        }

        $lines = $this->lines($connections, 0);
        $floors = array_values(array_map(fn (Connection $connection): int => mb_strlen("- {$connection->handle}: …"), $connections->all()));

        foreach (self::SHORT_DESCRIPTION_LENGTHS as $descriptionLength) {
            $description = $this->shorten($star->description ?? '', $descriptionLength);
            $available = self::MAX_LENGTH - mb_strlen($this->compose($star, $description, [])) - mb_strlen("\n\n".$this->heading()) - count($lines);
            $length = $this->lineLength($lines, $floors, $available);

            if ($length !== null) {
                return $this->compose($star, $description, array_map(
                    fn (string $line, int $floor): string => $this->shorten($line, max($length, $floor)),
                    $lines,
                    $floors,
                ));
            }
        }

        return $this->shorten($this->compose($star, null, $lines), self::MAX_LENGTH);
    }

    /**
     * @param  list<string>  $lines
     */
    private function compose(Star $star, ?string $description, array $lines): string
    {
        $sections = [
            __('Tools from the ":star" Star in Nexus. Each tool\'s name starts with the handle of the Connection it belongs to and two underscores: github__search_issues is the search_issues tool of the Connection with the handle github.', [
                'star' => $star->name,
            ]),
        ];

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
     * One line per Connection: "- github: GitHub · octocat — use for: work".
     *
     * @param  Collection<int, Connection>  $connections
     * @return list<string>
     */
    private function lines(Collection $connections, int $noteLength): array
    {
        return array_values($connections->map(fn (Connection $connection): string => "- {$connection->handle}: {$this->label($connection, $noteLength)}")->all());
    }

    /**
     * The Connection's name, the account it signed in as, and what the user
     * said to use it for, when known.
     */
    private function label(Connection $connection, int $noteLength): string
    {
        $label = $connection->name;

        if (filled($connection->account_identity)) {
            $label .= ' · '.$connection->account_identity;
        }

        if ($noteLength > 0 && filled($connection->description)) {
            $label .= ' — '.__('use for: :note', ['note' => $this->shorten($connection->description, $noteLength)]);
        }

        return $label;
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
