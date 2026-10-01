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
 * off: the Connections' "use for" notes are shortened, then left out, and
 * as a last resort the text is cut.
 */
final readonly class StarInstructions
{
    public const int MAX_LENGTH = 2048;

    /**
     * The longest each "use for" note may be, longest first; 0 leaves them out.
     */
    private const array NOTE_LENGTHS = [200, 80, 0];

    public function for(Star $star): string
    {
        $connections = $star->connections()->orderBy('name')->orderBy('connections.id')->get();

        $text = '';

        foreach (self::NOTE_LENGTHS as $noteLength) {
            $text = $this->compose($star, $connections, $noteLength);

            if (mb_strlen($text) <= self::MAX_LENGTH) {
                return $text;
            }
        }

        return mb_substr($text, 0, self::MAX_LENGTH - 1).'…';
    }

    /**
     * @param  Collection<int, Connection>  $connections
     */
    private function compose(Star $star, Collection $connections, int $noteLength): string
    {
        $sections = [
            __('Tools from the ":star" Star in Nexus. Each tool\'s name starts with the handle of the Connection it belongs to and two underscores: github__search_issues is the search_issues tool of the Connection with the handle github.', [
                'star' => $star->name,
            ]),
        ];

        if (filled($star->description)) {
            $sections[] = $star->description;
        }

        if ($connections->isNotEmpty()) {
            $sections[] = __('Connections:')."\n".$connections
                ->map(fn (Connection $connection): string => "- {$connection->handle}: {$this->label($connection, $noteLength)}")
                ->implode("\n");
        }

        return implode("\n\n", $sections);
    }

    /**
     * The Connection's name, the account it signed in as, and what the user
     * said to use it for, when known: "GitHub · octocat — use for: work".
     */
    private function label(Connection $connection, int $noteLength): string
    {
        $label = $connection->name;

        if (filled($connection->account_identity)) {
            $label .= ' · '.$connection->account_identity;
        }

        if ($noteLength > 0 && filled($connection->description)) {
            $note = mb_strlen($connection->description) > $noteLength
                ? mb_substr($connection->description, 0, $noteLength - 1).'…'
                : $connection->description;

            $label .= ' — '.__('use for: :note', ['note' => $note]);
        }

        return $label;
    }
}
