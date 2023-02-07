<?php

namespace Tests\Modifiers;

use Statamic\Modifiers\Modify;
use Tests\TestCase;

/**
 * @group array
 */
class IntersectsTest extends TestCase
{
    /** @test */
    public function it_checks_if_two_arrays_intersect(): void
    {
        $input = [
            'eggs',
            'flour',
            'beef jerky',
        ];

        $context = ['want' => ['eggs']];

        $modified = $this->modify($input, [['flour']], $context);
        $this->assertTrue($modified);

        $modified = $this->modify($input, ['want'], $context);
        $this->assertTrue($modified);

        $modified = $this->modify($input, [['eggs', 'flour']], $context);
        $this->assertTrue($modified);

        $modified = $this->modify($input, [['beef jerky']], $context);
        $this->assertTrue($modified);

        $modified = $this->modify($input, [['milk']], $context);
        $this->assertFalse($modified);

        $modified = $this->modify($input, [], $context);
        $this->assertFalse($modified);

        $modified = $this->modify('a string', [], $context);
        $this->assertFalse($modified);
    }

    private function modify($value, array $params, array $context)
    {
        return Modify::value($value)->context($context)->intersects($params)->fetch();
    }
}
