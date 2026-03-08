<?php

namespace Febryntara\TelemetryLogger\Contracts;

use Illuminate\Http\Request;

interface UserResolverContract
{
    /**
     * Resolve user info from the request.
     * Return an array of key-value pairs, or null if unauthenticated.
     */
    public function resolve(Request $request): ?array;
}
