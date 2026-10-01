<?php

declare(strict_types=1);

namespace TestHub\Bundle\HttpClient;

/**
 * Resolves a reference against a base URI the way HttpClient does with base_uri (RFC 3986, section 5.2).
 *
 * @internal
 */
final class UrlResolver
{
    /**
     * RFC 3986, appendix B.
     */
    private const string URI_PATTERN = '{^(?:([^:/?#]+):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?$}';

    public static function resolve(string $base, string $reference): string
    {
        [$scheme, $authority, $path, $query, $fragment] = self::parse($reference);

        if (null === $scheme) {
            [$scheme, $baseAuthority, $basePath, $baseQuery] = self::parse($base);

            if (null === $authority) {
                $authority = $baseAuthority;

                if ('' === $path) {
                    $path = $basePath;
                    $query ??= $baseQuery;
                } elseif (!str_starts_with($path, '/')) {
                    $path = self::merge($baseAuthority, $basePath, $path);
                }
            }
        }

        return (null !== $scheme ? $scheme.':' : '')
            .(null !== $authority ? '//'.$authority : '')
            .self::removeDotSegments($path)
            .(null !== $query ? '?'.$query : '')
            .(null !== $fragment ? '#'.$fragment : '');
    }

    /**
     * @return array{?string, ?string, string, ?string, ?string} scheme, authority, path, query, fragment
     */
    private static function parse(string $uri): array
    {
        preg_match(self::URI_PATTERN, $uri, $m, \PREG_UNMATCHED_AS_NULL);

        return [$m[1] ?? null, $m[2] ?? null, $m[3] ?? '', $m[4] ?? null, $m[5] ?? null];
    }

    private static function merge(?string $baseAuthority, string $basePath, string $path): string
    {
        if (null !== $baseAuthority && '' === $basePath) {
            return '/'.$path;
        }

        $slash = strrpos($basePath, '/');

        return (false === $slash ? '' : substr($basePath, 0, $slash + 1)).$path;
    }

    /**
     * RFC 3986, section 5.2.4.
     */
    private static function removeDotSegments(string $path): string
    {
        $output = '';

        while ('' !== $path) {
            if (str_starts_with($path, '../')) {
                $path = substr($path, 3);
            } elseif (str_starts_with($path, './') || str_starts_with($path, '/./')) {
                $path = substr($path, 2);
            } elseif ('/.' === $path) {
                $path = '/';
            } elseif (str_starts_with($path, '/../') || '/..' === $path) {
                $path = '/'.substr($path, 4);
                $output = substr($output, 0, (int) strrpos($output, '/'));
            } elseif ('.' === $path || '..' === $path) {
                $path = '';
            } else {
                $end = strpos($path, '/', 1);
                $segment = false === $end ? $path : substr($path, 0, $end);
                $output .= $segment;
                $path = substr($path, \strlen($segment));
            }
        }

        return $output;
    }
}
