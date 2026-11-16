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
    public function it_restarts_for_each_run_of_a_nested_loop()
    {
        $this->assertSame(
            '1-0-1-|1-0-1-|',
            $this->tag('{{ loop times="2" }}{{ loop times="3" }}{{ switch between="1|0" }}-{{ /loop }}|{{ /loop }}')
        );
    }

    #[Test]
    public function it_restarts_for_each_run_of_a_nested_variable_loop()
    {
        $data = [
            'groups' => [
                ['items' => [['title' => 'a'], ['title' => 'b'], ['title' => 'c']]],
                ['items' => [['title' => 'd'], ['title' => 'e'], ['title' => 'f']]],
            ],
        ];

        $this->assertSame(
            'a1-b0-c1-|d1-e0-f1-|',
            $this->tag('{{ groups }}{{ items }}{{ title }}{{ switch between="1|0" }}-{{ /items }}|{{ /groups }}', $data)
        );
    }

    #[Test]
    public function it_restarts_for_each_run_of_a_nested_loop_rendered_by_a_tag()
    {
        // The scope parameter makes the runtime hand the loop to the tag's parseLoop method.
        $this->assertSame(
            '1-0-1-|1-0-1-|',
            $this->tag('{{ loop times="2" }}{{ loop times="3" scope="inner" }}{{ switch between="1|0" }}-{{ /loop }}|{{ /loop }}')
        );
    }

    #[Test]
    public function it_restarts_for_each_loop()
    {
        $this->assertSame(
            '1-0-1-|1-0-|',
            $this->tag('{{ loop times="3" }}{{ switch between="1|0" }}-{{ /loop }}|{{ loop times="2" }}{{ switch between="1|0" }}-{{ /loop }}|')
        );
    }

    #[Test]
    public function it_continues_in_the_outer_loop_after_an_inner_loop()
    {
        $this->assertSame(
            'a[1-0-1-]b[1-0-1-]a[1-0-1-]',
            $this->tag('{{ loop times="3" }}{{ switch between="a|b" }}[{{ loop times="3" }}{{ switch between="1|0" }}-{{ /loop }}]{{ /loop }}')
        );
    }

    #[Test]
    public function the_same_tag_continues_in_the_outer_loop_after_an_inner_loop()
    {
        $this->assertSame(
            'a[a-b-a-]b[a-b-a-]a[a-b-a-]',
            $this->tag('{{ loop times="3" }}{{ switch between="a|b" }}[{{ loop times="3" }}{{ switch between="a|b" }}-{{ /loop }}]{{ /loop }}')
        );
    }

    #[Test]
    public function rows_that_are_not_rendered_do_not_advance_the_value()
    {
        $this->assertSame(
            '1:1-3:0-4:1-|1:1-3:0-4:1-|',
            $this->tag('{{ loop times="2" }}{{ loop times="4" }}{{ if value != 2 }}{{ value }}:{{ switch between="1|0" }}-{{ /if }}{{ /loop }}|{{ /loop }}')
        );
    }
}
