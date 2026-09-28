<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\HttpClient;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TestHub\Bundle\HttpClient\UrlResolver;

class UrlResolverTest extends TestCase
{
    #[DataProvider('provideRfcExamples')]
    public function testRfcExamples(string $reference, string $expected): void
    {
        $this->assertSame($expected, UrlResolver::resolve('http://a/b/c/d;p?q', $reference));
    }

    /**
     * RFC 3986, section 5.4.
     *
     * @return iterable<array{string, string}>
     */
    public static function provideRfcExamples(): iterable
    {
        yield ['g:h', 'g:h'];
        yield ['g', 'http://a/b/c/g'];
        yield ['./g', 'http://a/b/c/g'];
        yield ['g/', 'http://a/b/c/g/'];
        yield ['/g', 'http://a/g'];
        yield ['//g', 'http://g'];
        yield ['?y', 'http://a/b/c/d;p?y'];
        yield ['g?y', 'http://a/b/c/g?y'];
        yield ['#s', 'http://a/b/c/d;p?q#s'];
        yield ['g#s', 'http://a/b/c/g#s'];
        yield ['g?y#s', 'http://a/b/c/g?y#s'];
        yield [';x', 'http://a/b/c/;x'];
        yield ['g;x', 'http://a/b/c/g;x'];
        yield ['g;x?y#s', 'http://a/b/c/g;x?y#s'];
        yield ['', 'http://a/b/c/d;p?q'];
        yield ['.', 'http://a/b/c/'];
        yield ['./', 'http://a/b/c/'];
        yield ['..', 'http://a/b/'];
        yield ['../', 'http://a/b/'];
        yield ['../g', 'http://a/b/g'];
        yield ['../..', 'http://a/'];
        yield ['../../', 'http://a/'];
        yield ['../../g', 'http://a/g'];
        yield ['../../../g', 'http://a/g'];
        yield ['../../../../g', 'http://a/g'];
        yield ['/./g', 'http://a/g'];
        yield ['/../g', 'http://a/g'];
        yield ['g.', 'http://a/b/c/g.'];
        yield ['.g', 'http://a/b/c/.g'];
        yield ['g..', 'http://a/b/c/g..'];
        yield ['..g', 'http://a/b/c/..g'];
        yield ['./../g', 'http://a/b/g'];
        yield ['./g/.', 'http://a/b/c/g/'];
        yield ['g/./h', 'http://a/b/c/g/h'];
        yield ['g/../h', 'http://a/b/c/h'];
        yield ['g;x=1/./y', 'http://a/b/c/g;x=1/y'];
        yield ['g;x=1/../y', 'http://a/b/c/y'];
        yield ['g?y/./x', 'http://a/b/c/g?y/./x'];
        yield ['g?y/../x', 'http://a/b/c/g?y/../x'];
        yield ['g#s/./x', 'http://a/b/c/g#s/./x'];
        yield ['g#s/../x', 'http://a/b/c/g#s/../x'];
    }

    public function testBaseWithoutPath(): void
    {
        $this->assertSame('https://api.test/v1/data', UrlResolver::resolve('https://api.test', 'v1/data'));
    }
}
