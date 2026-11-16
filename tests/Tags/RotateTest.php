<?php

namespace Tests\Tags;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Parse;
use Statamic\Tags\Rotate;
use Tests\TestCase;

class RotateTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Rotate::resetStaticState();
    }

    private function tag($tag, $context = [])
    {
        return (string) Parse::template($tag, $context, trusted: true);
    }

    #[Test]
    public function it_rotates_between_values()
    {
        $this->assertSame(
            '1-0-1-0-',
            $this->tag('{{ loop times="4" }}{{ switch between="1|0" }}-{{ /loop }}')
        );
    }

    #[Test]
    public function it_rotates_outside_of_a_loop()
    {
        $this->assertSame(
            'a-b-c-a-',
            $this->tag('{{ rotate between="a|b|c" }}-{{ rotate between="a|b|c" }}-{{ rotate between="a|b|c" }}-{{ rotate between="a|b|c" }}-')
        );
    }

    #[Test]
    public function it_repeats_values_in_expanded_mode()
    {
        $this->assertSame(
            'a-a-b-a-a-b-',
            $this->tag('{{ loop times="6" }}{{ switch between="a:2|b" }}-{{ /loop }}')
        );
    }

    #[Test]
    public function it_continues_across_nested_loops_by_default()
    {
        $this->assertSame(
            '1-0-1-|0-1-0-|',
            $this->tag('{{ loop times="2" }}{{ loop times="3" }}{{ switch between="1|0" }}-{{ /loop }}|{{ /loop }}')
        );
    }

    #[Test]
    public function it_restarts_for_each_run_of_a_loop_when_per_loop_is_enabled()
    {
        $this->assertSame(
            '1-0-1-|1-0-1-|',
            $this->tag('{{ loop times="2" }}{{ loop times="3" }}{{ switch between="1|0" per_loop="true" }}-{{ /loop }}|{{ /loop }}')
        );
    }

    #[Test]
    public function per_loop_works_in_expanded_mode()
    {
        $this->assertSame(
            'a-a-b-|a-a-b-|',
            $this->tag('{{ loop times="2" }}{{ loop times="3" }}{{ switch between="a:2|b" per_loop="true" }}-{{ /loop }}|{{ /loop }}')
        );
    }

    #[Test]
    public function per_loop_falls_back_to_the_counter_outside_of_a_loop()
    {
        $this->assertSame(
            'a-b-',
            $this->tag('{{ rotate between="a|b" per_loop="true" }}-{{ rotate between="a|b" per_loop="true" }}-')
        );
    }
}
