<?php

declare(strict_types=1);

namespace Spiral\OpenTelemetry\Tests\Unit\Propagation;

use Spiral\OpenTelemetry\Propagation\TracestateGetter;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(TracestateGetter::class)]
final class TracestateGetterTest
{
    #[DataSet([['tracestate' => ['a=1', 'b=2']], 'a=1,b=2'], 'two fields')]
    #[DataSet([['tracestate' => ['a=1', 'b=2,c=3', 'd=4']], 'a=1,b=2,c=3,d=4'], 'three fields, one with two members')]
    #[DataSet([['TraceState' => ['a=1', 'b=2']], 'a=1,b=2'], 'case-insensitive key')]
    #[DataSet([['tracestate' => 'a=1,b=2'], 'a=1,b=2'], 'string')]
    #[DataSet([['tracestate' => ['a=1,b=2']], 'a=1,b=2'], 'list of one')]
    #[DataSet([['tracestate' => []], null], 'empty list')]
    #[DataSet([[], null], 'missing')]
    public function tracestateFieldsAreCombined(array $carrier, ?string $expected): void
    {
        Assert::same((new TracestateGetter())->get($carrier, 'tracestate'), $expected);
    }

    public function otherKeysKeepFirstField(): void
    {
        $carrier = ['traceparent' => ['first', 'second']];

        Assert::same((new TracestateGetter())->get($carrier, 'traceparent'), 'first');
    }

    public function getAllKeepsFieldsApart(): void
    {
        $carrier = ['tracestate' => ['a=1', 'b=2']];

        Assert::same((new TracestateGetter())->getAll($carrier, 'tracestate'), ['a=1', 'b=2']);
    }

    public function keysAreDelegated(): void
    {
        $carrier = ['traceparent' => 'parent', 'tracestate' => ['a=1']];

        Assert::same((new TracestateGetter())->keys($carrier), ['traceparent', 'tracestate']);
    }
}
