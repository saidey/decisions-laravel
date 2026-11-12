<?php

namespace DecisionsAi\Laravel\Http;

use Closure;
use DecisionsAi\Laravel\SecurityResponse;
use DecisionsAi\Laravel\Sensors\Security;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BlockList
{
    public function __construct(private SecurityResponse $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (config('decisions.security.enforce', false)) {
                $ip = Security::clientIp($request);
                foreach ($this->security->blocks() as $block) {
                    if (is_string($ip) && $this->matches($ip, $block['value'])) {
                        $request->attributes->set('decisions.blocked', true);

                        return new Response('Forbidden', 403, ['Content-Type' => 'text/plain']);
                    }
                }
            }
        } catch (Throwable) {
            // Cache outages must allow the request through.
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->security->refresh();
    }

    private function matches(string $ip, string $value): bool
    {
        [$network, $prefix] = array_pad(explode('/', $value, 2), 2, null);
        $address = @inet_pton($ip);
        $network = @inet_pton($network);
        // A proxy may report IPv4 clients as ::ffff:a.b.c.d; compare them as IPv4.
        if ($address !== false && strlen($address) === 16 && $network !== false && strlen($network) === 4
            && str_starts_with($address, str_repeat("\0", 10)."\xff\xff")) {
            $address = substr($address, 12);
        }
        if ($address === false || $network === false || strlen($address) !== strlen($network)) {
            return false;
        }
        $bits = strlen($address) * 8;
        if ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix > $bits)) {
            return false;
        }
        $bits = $prefix === null ? $bits : (int) $prefix;
        $bytes = intdiv($bits, 8);
        $remaining = $bits % 8;

        return substr($address, 0, $bytes) === substr($network, 0, $bytes)
            && ($remaining === 0 || ((ord($address[$bytes]) ^ ord($network[$bytes])) & (0xFF << (8 - $remaining))) === 0);
    }
}
