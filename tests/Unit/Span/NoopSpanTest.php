<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Unit\Span;

use Spiral\OpenTelemetry\Span\NoopSpan;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(NoopSpan::class)]
final class NoopSpanTest
{
    public function isOneInstance(): void
    {
        Assert::same(NoopSpan::instance(), NoopSpan::instance());
    }

    public function settersReturnTheSpanAndKeepNothing(): void
    {
        $span = NoopSpan::instance();

        Assert::same($span->updateName('renamed'), $span);
        Assert::same($span->setStatus('error', 'reason'), $span);
        Assert::same($span->setAttributes(['a' => 1]), $span);
        Assert::same($span->setAttribute('b', 2), $span);

        Assert::same($span->getName(), 'noop');
        Assert::null($span->getStatus());
        Assert::same($span->getAttributes(), []);
        Assert::false($span->hasAttribute('a'));
        Assert::null($span->getAttribute('b'));
    }
}
