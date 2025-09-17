<?php
declare(strict_types=1);

namespace App\Math;

use Exception;

/**
 * MathParser: Shunting-yard + RPN evaluator
 * Supports: + - * / ^, parentheses, unary -, functions: sin, cos, tan, sqrt, log (base10), ln, pow(a,b)
 * All trigonometric functions use radians.
 */
class MathParser
{
    private array $operators = [
        '+' => ['precedence' => 2, 'assoc' => 'L', 'arity' => 2],
        '-' => ['precedence' => 2, 'assoc' => 'L', 'arity' => 2],
        '*' => ['precedence' => 3, 'assoc' => 'L', 'arity' => 2],
        '/' => ['precedence' => 3, 'assoc' => 'L', 'arity' => 2],
        '^' => ['precedence' => 4, 'assoc' => 'R', 'arity' => 2],
        'u-' => ['precedence' => 5, 'assoc' => 'R', 'arity' => 1], // unary minus
    ];

    private array $functions = ['sin', 'cos', 'tan', 'sqrt', 'log', 'ln', 'pow'];

    public function tokenize(string $expr): array
    {
        $expr = trim($expr);
        // Normalize unicode minus to ascii
        $expr = str_replace(["\xE2\x88\x92"], ['-'], $expr);
        $tokens = [];
        $len = strlen($expr);
        $i = 0;
        while ($i < $len) {
            $ch = $expr[$i];
            if (ctype_space($ch)) { $i++; continue; }
            // recognize constant pi
            if (($ch === 'p' || $ch === 'P') && $i+1 < $len && ($expr[$i+1] === 'i' || $expr[$i+1] === 'I')) {
                $tokens[] = ['type' => 'number', 'value' => (string)M_PI];
                $i += 2; continue;
            }
            if (ctype_digit($ch) || ($ch === '.' && $i+1 < $len && ctype_digit($expr[$i+1]))) {
                $num = $ch; $i++;
                while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) { $num .= $expr[$i++]; }
                $tokens[] = ['type' => 'number', 'value' => $num];
                continue;
            }
            if (ctype_alpha($ch)) {
                $ident = $ch; $i++;
                while ($i < $len && (ctype_alpha($expr[$i]) || ctype_digit($expr[$i]) || $expr[$i] === '_')) { $ident .= $expr[$i++]; }
                $ident = strtolower($ident);
                if ($ident === 'e') {
                    $tokens[] = ['type' => 'number', 'value' => '2.7182818284590452'];
                } else {
                    $tokens[] = ['type' => 'ident', 'value' => $ident];
                }
                continue;
            }
            if (in_array($ch, ['+', '-', '*', '/', '^', '(', ')', ','])) {
                $tokens[] = ['type' => 'sym', 'value' => $ch];
                $i++;
                continue;
            }
            throw new Exception('Unexpected character: ' . $ch);
        }
        return $tokens;
    }

    public function toRPN(array $tokens, array &$steps = []): array
    {
        $output = [];
        $stack = [];
        $expectUnary = true; // At start, a leading '-' is unary
        foreach ($tokens as $tok) {
            if ($tok['type'] === 'number') {
                $output[] = $tok;
                $steps[] = 'Push number ' . $tok['value'];
                $expectUnary = false;
            } elseif ($tok['type'] === 'ident') {
                if (!in_array($tok['value'], $this->functions, true)) {
                    throw new Exception('Unknown function: ' . $tok['value']);
                }
                $stack[] = ['type' => 'func', 'value' => $tok['value']];
                $steps[] = 'Push function ' . $tok['value'];
                $expectUnary = true;
            } elseif ($tok['type'] === 'sym') {
                $sym = $tok['value'];
                if ($sym === ',') {
                    while (!empty($stack) && end($stack)['value'] !== '(') {
                        $output[] = array_pop($stack);
                    }
                    if (empty($stack)) { throw new Exception('Misplaced comma'); }
                    $expectUnary = true;
                } elseif ($sym === '(') {
                    $stack[] = ['type' => 'sym', 'value' => '('];
                    $steps[] = 'Push ( to stack';
                    $expectUnary = true;
                } elseif ($sym === ')') {
                    while (!empty($stack) && end($stack)['value'] !== '(') {
                        $output[] = array_pop($stack);
                    }
                    if (empty($stack)) { throw new Exception('Mismatched parentheses'); }
                    array_pop($stack); // pop '('
                    // if top is a function, pop it too
                    if (!empty($stack) && end($stack)['type'] === 'func') {
                        $output[] = array_pop($stack);
                    }
                    $steps[] = 'Resolve ) to output';
                    $expectUnary = false;
                } else { // operator
                    $op = $sym;
                    // Determine unary minus using expectation flag
                    if ($op === '-' && $expectUnary) { $op = 'u-'; }
                    $o1 = $this->operators[$op] ?? null;
                    if (!$o1) { throw new Exception('Unknown operator: ' . $op); }
                    while (!empty($stack)) {
                        $top = end($stack);
                        if ($top['type'] === 'op') {
                            $o2 = $this->operators[$top['value']];
                            $cond = ($o1['assoc'] === 'L' && $o1['precedence'] <= $o2['precedence']) || ($o1['assoc'] === 'R' && $o1['precedence'] < $o2['precedence']);
                            if ($cond) { $output[] = array_pop($stack); continue; }
                        }
                        break;
                    }
                    $stack[] = ['type' => 'op', 'value' => $op];
                    $steps[] = 'Push operator ' . $op;
                    $expectUnary = true;
                }
            }
        }
        while (!empty($stack)) {
            $top = array_pop($stack);
            if ($top['value'] === '(' || $top['value'] === ')') { throw new Exception('Mismatched parentheses'); }
            $output[] = $top;
        }
        return $output;
    }

    public function evalRPN(array $rpn, array &$steps = []): float
    {
        $stack = [];
        foreach ($rpn as $tok) {
            if ($tok['type'] === 'number') {
                $stack[] = (float)$tok['value'];
            } elseif (($tok['type'] === 'op') || ($tok['type'] === 'sym' && isset($this->operators[$tok['value']]))) {
                $op = $tok['value'];
                if ($op === 'u-') {
                    if (count($stack) < 1) { throw new Exception('Not enough operands for unary -'); }
                    $a = array_pop($stack);
                    $res = -$a;
                    $stack[] = $res;
                    $steps[] = "u- $a => $res";
                } else {
                    if (count($stack) < 2) { throw new Exception('Not enough operands for ' . $op); }
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    switch ($op) {
                        case '+': $res = $a + $b; break;
                        case '-': $res = $a - $b; break;
                        case '*': $res = $a * $b; break;
                        case '/': if ($b == 0.0) { throw new Exception('Division by zero'); } $res = $a / $b; break;
                        case '^': $res = pow($a, $b); break;
                        default: throw new Exception('Unknown operator ' . $op);
                    }
                    $stack[] = $res;
                    $steps[] = "$a $op $b => $res";
                }
            } elseif ($tok['type'] === 'func') {
                $fn = $tok['value'];
                switch ($fn) {
                    case 'sin': case 'cos': case 'tan':
                        if (count($stack) < 1) { throw new Exception('Not enough args for ' . $fn); }
                        $a = array_pop($stack);
                        $res = $fn($a);
                        $steps[] = "$fn($a) => $res";
                        $stack[] = $res;
                        break;
                    case 'sqrt':
                        if (count($stack) < 1) { throw new Exception('Not enough args for sqrt'); }
                        $a = array_pop($stack);
                        if ($a < 0) { throw new Exception('Sqrt of negative'); }
                        $res = sqrt($a);
                        $steps[] = "sqrt($a) => $res";
                        $stack[] = $res;
                        break;
                    case 'log':
                        if (count($stack) < 1) { throw new Exception('Not enough args for log'); }
                        $a = array_pop($stack);
                        if ($a <= 0) { throw new Exception('Log base10 of non-positive'); }
                        $res = log10($a);
                        $steps[] = "log10($a) => $res";
                        $stack[] = $res;
                        break;
                    case 'ln':
                        if (count($stack) < 1) { throw new Exception('Not enough args for ln'); }
                        $a = array_pop($stack);
                        if ($a <= 0) { throw new Exception('Ln of non-positive'); }
                        $res = log($a);
                        $steps[] = "ln($a) => $res";
                        $stack[] = $res;
                        break;
                    case 'pow':
                        if (count($stack) < 2) { throw new Exception('Not enough args for pow'); }
                        $b = array_pop($stack);
                        $a = array_pop($stack);
                        $res = pow($a, $b);
                        $steps[] = "pow($a,$b) => $res";
                        $stack[] = $res;
                        break;
                    default:
                        throw new Exception('Unknown function ' . $fn);
                }
            } else {
                throw new Exception('Invalid token in RPN');
            }
        }
        if (count($stack) !== 1) { throw new Exception('Invalid expression'); }
        return (float)$stack[0];
    }

    public function explain(string $expr): array
    {
        $steps = [];
        $tokens = $this->tokenize($expr);
        $rpn = $this->toRPN($tokens, $steps);
        $evalSteps = [];
        $result = $this->evalRPN($rpn, $evalSteps);
        return [
            'tokens' => $tokens,
            'rpn' => $rpn,
            'shunting_steps' => $steps,
            'eval_steps' => $evalSteps,
            'result' => $result
        ];
    }
}

