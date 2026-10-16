<?php

namespace App\Domain\Integrations;

use Closure;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * SSRF defence for requests to URLs users control (HTTP Request node, app actions, custom apps, OAuth token
 * URLs, OpenAPI imports, voice webhooks): http(s) only, and every address the host resolves to must be public.
 * FLOWFORGE_ALLOW_PRIVATE_NETWORK=true lets VPS users reach their own services (localhost, LAN); link-local,
 * cloud metadata and reserved ranges stay blocked regardless. Throws InvalidArgumentException meant for users.
 */
class UrlGuard
{
    /** Never: unspecified, link-local + cloud metadata (169.254.169.254, fd00:ec2::254, Alibaba), multicast, reserved/broadcast. */
    private const ALWAYS_BLOCKED = ['0.0.0.0/8', '169.254.0.0/16', '100.100.100.200/32', '224.0.0.0/4', '240.0.0.0/4', 'fe80::/10', 'ff00::/8', 'fd00:ec2::254/128'];

    /** Loopback, RFC1918, CGNAT and IPv6 unique-local: only with allow_private_network. */
    private const PRIVATE = ['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', '::1/128', 'fc00::/7'];

    private static ?Closure $resolver = null;

    /** Tests: fn (string $host): array of IPs instead of DNS; null goes back to DNS. */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver ? Closure::fromCallable($resolver) : null;
    }

    /** Throws unless the URL may be requested; returns the checked address to connect to. */
    public static function check(string|UriInterface $url): string
    {
        try {
            $uri = $url instanceof UriInterface ? $url : new Uri($url);
        } catch (Throwable) {
            throw new InvalidArgumentException("That isn't a valid web address.");
        }
        if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only http:// and https:// web addresses can be used.');
        }
        $host = trim($uri->getHost(), '[]');
        if ($host === '') {
            throw new InvalidArgumentException("That isn't a valid web address.");
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (self::$resolver ?? self::dns(...))($host);
        $ips = array_values(array_filter(array_map(self::normalize(...), $ips)));
        if (! $ips) {
            throw new InvalidArgumentException("Couldn't find the host {$host}. Check the address for typos.");
        }

        foreach ($ips as $ip) {
            $where = $host === $ip ? $ip : "{$host} is {$ip}";
            if (IpUtils::checkIp($ip, self::ALWAYS_BLOCKED)) {
                throw new InvalidArgumentException("FlowForge never sends requests to {$where}: it's a reserved address (for example the server's cloud metadata service), so it's always blocked.");
            }
            if (! config('flowforge.allow_private_network') && IpUtils::checkIp($ip, self::PRIVATE)) {
                throw new InvalidArgumentException("FlowForge doesn't send requests to private or internal network addresses ({$where}). If this is your own server, set FLOWFORGE_ALLOW_PRIVATE_NETWORK=true in .env.");
            }
        }

        return $ips[0];
    }

    /**
     * Checks the URL and sets the request up to connect only to the checked address and to re-check every
     * redirect (max 5, http/https). Merges with options the request already has.
     */
    public static function protect(PendingRequest $request, string $url): PendingRequest
    {
        $ip = self::check($url);
        $uri = new Uri($url);
        $options = ['allow_redirects' => [
            'max' => 5,
            'protocols' => ['http', 'https'],
            // ponytail: a redirect to another host or port is re-checked but not pinned, so DNS could still change
            // between this check and curl's own lookup; follow redirects by hand (pinning each hop) to close that.
            'on_redirect' => fn ($request, $response, UriInterface $next) => self::check($next),
        ]];
        // Pin the host to the address just checked so a DNS change (rebinding) can't send curl elsewhere.
        if (defined('CURLOPT_RESOLVE') && ! filter_var(trim($uri->getHost(), '[]'), FILTER_VALIDATE_IP)) {
            $port = $uri->getPort() ?? (strtolower($uri->getScheme()) === 'https' ? 443 : 80);
            $options['curl'] = [CURLOPT_RESOLVE => ["{$uri->getHost()}:{$port}:".(str_contains($ip, ':') ? "[{$ip}]" : $ip)]];
        }

        return $request->withOptions($options);
    }

    private static function dns(string $host): array
    {
        $v4 = rescue(fn () => @gethostbynamel($host) ?: [], [], false);
        $v6 = rescue(fn () => @dns_get_record($host, DNS_AAAA) ?: [], [], false);

        return [...$v4, ...array_column($v6, 'ipv6')];
    }

    /** A valid IP, with IPv4-mapped/-compatible IPv6 (::ffff:127.0.0.1) turned into plain IPv4; otherwise null. */
    private static function normalize(mixed $ip): ?string
    {
        $binary = is_string($ip) ? @inet_pton($ip) : false;
        if ($binary === false) {
            return null;
        }
        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10))
            && in_array(substr($binary, 10, 2), ["\xff\xff", "\0\0"], true) && $binary !== inet_pton('::1')) {
            $binary = substr($binary, 12);
        }

        return inet_ntop($binary);
    }
}
