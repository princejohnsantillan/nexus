<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectionOAuth;

use App\ConnectionOAuth\NexusClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Nexus's Client ID Metadata Document, which servers that accept one fetch
 * in place of registering Nexus. Public, so they can.
 */
class ClientMetadataDocumentController extends Controller
{
    public function __invoke(NexusClient $nexus): JsonResponse
    {
        return response()->json($nexus->metadataDocument(), options: JSON_UNESCAPED_SLASHES)
            ->setPublic()
            ->setMaxAge(3600);
    }
}
