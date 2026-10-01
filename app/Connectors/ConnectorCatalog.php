<?php

namespace App\Connectors;

use Filament\Support\Icons\Heroicon;

/**
 * The connectors users can pick instead of configuring a server by hand.
 * Each points at the service's own, official remote MCP server.
 *
 * Scopes are set explicitly: without one, Nexus would request every scope a
 * server advertises (for Gmail, that includes full mailbox access).
 */
final class ConnectorCatalog
{
    /**
     * The user scopes Slack's MCP server advertises in its protected resource
     * metadata (checked 2026-10-01). The Slack app must have all of them.
     */
    public const SLACK_SCOPES = [
        'canvases:read', 'canvases:write', 'channels:history', 'channels:read', 'channels:write', 'chat:write',
        'emoji:read', 'files:read', 'files:write', 'groups:history', 'groups:read', 'groups:write',
        'im:history', 'im:read', 'im:write', 'lists:read', 'lists:write', 'mpim:history', 'mpim:read',
        'mpim:write', 'reactions:read', 'reactions:write', 'search:read.files', 'search:read.im',
        'search:read.mpim', 'search:read.private', 'search:read.public', 'search:read.users',
        'users:read', 'users:read.email',
    ];

    /**
     * @return array<string, Connector>
     */
    public static function all(): array
    {
        $connectors = [
            new Connector(
                key: 'slack',
                name: 'Slack',
                summary: 'Search messages and files, read channels and threads, and send messages as you.',
                url: 'https://mcp.slack.com/mcp',
                icon: Heroicon::OutlinedChatBubbleLeftRight,
                docsUrl: 'https://docs.slack.dev/ai/slack-mcp-server',
                registration: ClientRegistration::PreRegistered,
                scope: implode(' ', self::SLACK_SCOPES),
                appConsoleUrl: 'https://api.slack.com/apps?new_app=1',
                appInstructions: 'Slack only accepts apps registered in its developer console, and an internal app works in its own workspace only. Create one with "From a manifest" using the manifest below (a workspace admin may need to approve it), then enter its client ID and secret.',
                appManifest: [
                    'display_information' => ['name' => 'Nexus'],
                    'oauth_config' => [
                        'redirect_urls' => [],
                        'scopes' => ['user' => self::SLACK_SCOPES],
                    ],
                    'settings' => [
                        'is_mcp_enabled' => true,
                        'token_rotation_enabled' => true,
                    ],
                ],
            ),
            new Connector(
                key: 'github',
                name: 'GitHub',
                summary: 'Repositories, issues, pull requests, code search and Actions.',
                url: 'https://api.githubcopilot.com/mcp/',
                icon: Heroicon::OutlinedCodeBracket,
                docsUrl: 'https://github.com/github/github-mcp-server/blob/main/docs/remote-server.md',
                registration: ClientRegistration::PreRegistered,
                scope: 'repo read:org read:user user:email',
                appConsoleUrl: 'https://github.com/settings/applications/new',
                appInstructions: 'GitHub only accepts apps registered in its developer settings. Create an OAuth app with the callback URL below, then enter its client ID and secret. Organizations may need to approve the app before their repositories are visible.',
            ),
            new Connector(
                key: 'gmail',
                name: 'Gmail',
                summary: 'Search and read threads, manage labels, and write drafts.',
                url: 'https://gmailmcp.googleapis.com/mcp/v1',
                icon: Heroicon::OutlinedEnvelope,
                docsUrl: 'https://developers.google.com/workspace/gmail/api/guides/configure-mcp-server',
                registration: ClientRegistration::PreRegistered,
                scope: 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.compose',
                appConsoleUrl: 'https://console.cloud.google.com/auth/clients/create',
                appInstructions: 'Google\'s Gmail MCP server is in Developer Preview: it needs a Google Cloud project enrolled in the Workspace Developer Preview Program, configured by whoever runs this Nexus.',
                preview: true,
                requiresDeploymentApp: true,
            ),
            new Connector(
                key: 'linear',
                name: 'Linear',
                summary: 'Find, create and update issues, projects and comments.',
                url: 'https://mcp.linear.app/mcp',
                icon: Heroicon::OutlinedCheckCircle,
                docsUrl: 'https://linear.app/docs/mcp',
            ),
            new Connector(
                key: 'notion',
                name: 'Notion',
                summary: 'Search, read and edit pages and databases in your workspace.',
                url: 'https://mcp.notion.com/mcp',
                icon: Heroicon::OutlinedDocumentText,
                docsUrl: 'https://developers.notion.com/guides/mcp/mcp-supported-tools',
            ),
        ];

        return collect($connectors)->keyBy('key')->all();
    }

    public static function find(?string $key): ?Connector
    {
        return $key === null ? null : (self::all()[$key] ?? null);
    }
}
