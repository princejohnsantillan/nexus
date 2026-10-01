<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the OAuth client Nexus signs in to a Connection's server as comes
 * from, in the order Nexus tries them.
 */
enum OAuthClientSource: string
{
    /** The user's own OAuth app, whose client ID and secret they entered on the Connection. */
    case OwnApp = 'own_app';

    /** The OAuth app this deployment registered for the Connection's connector. */
    case DeploymentApp = 'deployment_app';

    /** Nexus's Client ID Metadata Document: the client ID is the document's URL, which the server fetches. */
    case MetadataDocument = 'metadata_document';

    /** A client the server registered for this Connection through dynamic client registration. */
    case Registered = 'registered';
}
