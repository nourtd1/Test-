<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Math\MathParser;

final class MathParserTest extends TestCase
{
    public function test_basic_addition(): void
    {
        $parser = new MathParser();
        $info = $parser->explain('2+3');
        $this->assertSame(5.0, $info['result']);
    }

    public function test_precedence_and_parentheses(): void
    {
        $parser = new MathParser();
        $this->assertSame(14.0, $parser->explain('2*(3+4)')['result']);
        $this->assertSame(5.0, $parser->explain('2+3*1')['result']);
    }

    public function test_unary_minus(): void
    {
        $parser = new MathParser();
        $this->assertSame(-5.0, $parser->explain('-5')['result']);
        $this->assertSame(3.0, $parser->explain('5+-2')['result']);
    }

    public function test_functions(): void
    {
        $parser = new MathParser();
        $this->assertSame(1.0, round($parser->explain('sin(pi/2)')['result'], 6));
        $this->assertSame(3.0, $parser->explain('sqrt(9)')['result']);
        $this->assertSame(2.0, $parser->explain('log(100)')['result']);
        $this->assertSame(1.0, $parser->explain('ln(e)')['result']);
        $this->assertSame(8.0, $parser->explain('pow(2,3)')['result']);
    }

    public function test_errors(): void
    {
        $this->expectException(Exception::class);
        (new MathParser())->explain('2/0');
    }
}

