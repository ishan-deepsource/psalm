<?php
namespace Psalm\Tests\TypeReconciliation;

use function is_array;
use Psalm\Context;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Analyzer\TypeAnalyzer;
use Psalm\Internal\Clause;
use Psalm\Type;
use Psalm\Type\Algebra;
use Psalm\Type\Reconciler;

class ConditionalTest extends \Psalm\Tests\TestCase
{
    use \Psalm\Tests\Traits\InvalidCodeAnalysisTestTrait;
    use \Psalm\Tests\Traits\ValidCodeAnalysisTestTrait;

    /**
     * @return iterable<string,array{string,assertions?:array<string,string>,error_levels?:string[]}>
     */
    public function providerValidCodeParse()
    {
        return [
            'intIsMixed' => [
                '<?php
                    /** @param mixed $a */
                    function foo($a): void {
                        $b = 5;

                        if ($b === $a) { }
                    }',
            ],
            'typeResolutionFromDocblock' => [
                '<?php
                    class A { }

                    /**
                     * @param  A $a
                     * @return void
                     */
                    function fooFoo($a) {
                        if ($a instanceof A) {
                        }
                    }',
                'assertions' => [],
                'error_levels' => ['RedundantConditionGivenDocblockType'],
            ],
            'arrayTypeResolutionFromDocblock' => [
                '<?php
                    /**
                     * @param string[] $strs
                     * @return void
                     */
                    function foo(array $strs) {
                        foreach ($strs as $str) {
                            if (is_string($str)) {}
                        }
                    }',
                'assertions' => [],
                'error_levels' => ['RedundantConditionGivenDocblockType'],
            ],
            'typeResolutionFromDocblockInside' => [
                '<?php
                    /**
                     * @param int $length
                     * @return void
                     */
                    function foo($length) {
                        if (!is_int($length)) {
                            if (is_numeric($length)) {
                            }
                        }
                    }',
                'assertions' => [],
                'error_levels' => ['DocblockTypeContradiction'],
            ],
            'notInstanceof' => [
                '<?php
                    class A { }

                    class B extends A { }

                    $a = new A();

                    $out = null;

                    if ($a instanceof B) {
                        // do something
                    }
                    else {
                        $out = $a;
                    }',
                'assertions' => [
                    '$out' => 'A|null',
                ],
            ],
            'notInstanceOfProperty' => [
                '<?php
                    class B { }

                    class C extends B { }

                    class A {
                        /** @var B */
                        public $foo;

                        public function __construct() {
                            $this->foo = new B();
                        }
                    }

                    $a = new A();

                    $out = null;

                    if ($a->foo instanceof C) {
                        // do something
                    }
                    else {
                        $out = $a->foo;
                    }',
                'assertions' => [
                    '$out' => 'B|null',
                ],
                'error_levels' => [],
            ],
            'notInstanceOfPropertyElseif' => [
                '<?php
                    class B { }

                    class C extends B { }

                    class A {
                        /** @var string|B */
                        public $foo = "";
                    }

                    $a = new A();

                    $out = null;

                    if (is_string($a->foo)) {

                    }
                    elseif ($a->foo instanceof C) {
                        // do something
                    }
                    else {
                        $out = $a->foo;
                    }',
                'assertions' => [
                    '$out' => 'B|null',
                ],
                'error_levels' => [],
            ],
            'typeRefinementWithIsNumericOnIntOrFalse' => [
                '<?php
                    /** @return void */
                    function fooFoo(string $a) {
                        if (is_numeric($a)) { }

                        if (is_numeric($a) && $a === "1") { }
                    }

                    $b = rand(0, 1) ? 5 : false;
                    if (is_numeric($b)) { }',
            ],
            'typeRefinementWithIsNumericAndIsString' => [
                '<?php
                    /**
                     * @param mixed $a
                     * @return void
                     */
                    function foo ($a) {
                        if (is_numeric($a)) {
                            if (is_string($a)) {
                            }
                        }
                    }',
            ],
            'typeRefinementWithIsNumericOnIntOrString' => [
                '<?php
                    $a = rand(0, 5) > 4 ? "hello" : 5;

                    if (is_numeric($a)) {
                      exit;
                    }',
                'assertions' => [
                    '$a' => 'string',
                ],
            ],
            'typeRefinementWithStringOrTrue' => [
                '<?php
                    $a = rand(0, 5) > 4 ? "hello" : true;

                    if (is_bool($a)) {
                      exit;
                    }',
                'assertions' => [
                    '$a' => 'string',
                ],
            ],
            'updateMultipleIssetVars' => [
                '<?php
                    /** @return void **/
                    function foo(string $s) {}

                    $a = rand(0, 1) ? ["hello"] : null;
                    if (isset($a[0])) {
                        foo($a[0]);
                    }',
            ],
            'updateMultipleIssetVarsWithVariableOffset' => [
                '<?php
                    /** @return void **/
                    function foo(string $s) {}

                    $a = rand(0, 1) ? ["hello"] : null;
                    $b = 0;
                    if (isset($a[$b])) {
                        foo($a[$b]);
                    }',
            ],
            'instanceOfSubtypes' => [
                '<?php
                    abstract class A {}
                    class B extends A {}

                    abstract class C {}
                    class D extends C {}

                    function makeA(): A {
                      return new B();
                    }

                    function makeC(): C {
                      return new D();
                    }

                    $a = rand(0, 1) ? makeA(): makeC();

                    if ($a instanceof B || $a instanceof D) { }',
            ],
            'typeReconciliationAfterIfAndReturn' => [
                '<?php
                    /**
                     * @param string|int $a
                     * @return string|int
                     */
                    function foo($a) {
                        if (is_string($a)) {
                            return $a;
                        } elseif (is_int($a)) {
                            return $a;
                        }

                        throw new \LogicException("Runtime error");
                    }',
                'assertions' => [],
                'error_levels' => ['RedundantConditionGivenDocblockType'],
            ],
            'ignoreNullCheckAndMaintainNullValue' => [
                '<?php
                    $a = null;
                    if ($a !== null) { }
                    $b = $a;',
                'assertions' => [
                    '$b' => 'null',
                ],
                'error_levels' => ['TypeDoesNotContainType', 'RedundantCondition'],
            ],
            'ignoreNullCheckAndMaintainNullableValue' => [
                '<?php
                    $a = rand(0, 1) ? 5 : null;
                    if ($a !== null) { }
                    $b = $a;',
                'assertions' => [
                    '$b' => 'int|null',
                ],
            ],
            'ternaryByRefVar' => [
                '<?php
                    function foo(): void {
                        $b = null;
                        $c = rand(0, 1) ? bar($b): null;
                        if (is_int($b)) { }
                    }
                    function bar(?int &$a): void {
                        $a = 5;
                    }',
            ],
            'ternaryByRefVarInConditional' => [
                '<?php
                    function foo(): void {
                        $b = null;
                        if (rand(0, 1) || bar($b)) {
                            if (is_int($b)) { }
                        }
                    }
                    function bar(?int &$a): void {
                        $a = 5;
                    }',
            ],
            'possibleInstanceof' => [
                '<?php
                    interface I1 {}
                    interface I2 {}

                    class A
                    {
                        public function foo(): void {
                            if ($this instanceof I1 || $this instanceof I2) {}
                        }
                    }',
            ],
            'intersection' => [
                '<?php
                    interface I {
                        public function bat(): void;
                    }

                    function takesI(I $i): void {}
                    function takesA(A $a): void {}
                    /** @param A&I $a */
                    function takesAandI($a): void {}
                    /** @param I&A $a */
                    function takesIandA($a): void {}

                    class A {
                        /**
                         * @return A&I|null
                         */
                        public function foo() {
                            if ($this instanceof I) {
                                $this->bar();
                                $this->bat();

                                takesA($this);
                                takesI($this);
                                takesAandI($this);
                                takesIandA($this);
                            }
                        }

                        protected function bar(): void {}
                    }

                    class B extends A implements I {
                        public function bat(): void {}
                    }',
            ],
            'createIntersectionOfInterfaceAndClass' => [
                '<?php
                    class A {
                      public function bat() : void {}
                    }
                    interface I {
                      public function baz() : void;
                    }

                    function foo(I $i) : void {
                      if ($i instanceof A) {
                        $i->bat();
                        $i->baz();
                      }
                    }

                    function bar(A $a) : void {
                      if ($a instanceof I) {
                        $a->bat();
                        $a->baz();
                      }
                    }

                    class B extends A implements I {
                      public function baz() : void {}
                    }

                    foo(new B);
                    bar(new B);',
            ],
            'unionOfArrayOrTraversable' => [
                '<?php
                    function foo(iterable $iterable) : void {
                        if (\is_array($iterable)) {}
                        if ($iterable instanceof \Traversable) {}
                    }',
            ],
            'isTruthy' => [
                '<?php
                    function f(string $s = null): string {
                      if ($s == true) {
                          return $s;
                      }

                      return "backup";
                    }',
            ],
            'stringOrCallableArg' => [
                '<?php
                    /**
                     * @param string|callable $param
                     */
                    function f($param): void {}
                    f("is_array");',
            ],
            'stringOrCallableOrObjectArg' => [
                '<?php
                    /**
                     * @param string|callable|object $param
                     */
                    function f($param): void {}
                    f("is_array");',
            ],
            'intOrFloatArg' => [
                '<?php
                    /**
                     * @param int|float $param
                     */
                    function f($param): void {}
                    f(5.0);
                    f(5);',
            ],
            'nullReplacement' => [
                '<?php
                    /**
                     * @param string|null|false $a
                     * @return string|false $a
                     */
                    function foo($a) {
                      if ($a === null) {
                        if (rand(0, 4) > 2) {
                          $a = "hello";
                        } else {
                          $a = false;
                        }
                      }

                      return $a;
                    }',
            ],
            'nullableIntReplacement' => [
                '<?php
                    $a = rand(0, 1) ? 5 : null;

                    $b = (bool)rand(0, 1);

                    if ($b || $a !== null) {
                        $a = 3;
                    }',
                'assertions' => [
                    '$a' => 'int|null',
                ],
            ],
            'eraseNullAfterInequalityCheck' => [
                '<?php
                    $a = mt_rand(0, 1) ? mt_rand(-10, 10): null;

                    if ($a > 0) {
                      echo $a + 3;
                    }

                    if (0 < $a) {
                      echo $a + 3;
                    }',
            ],
            'twoWrongsDontMakeARight' => [
                '<?php
                    if (rand(0, 1)) {
                        $a = false;
                    } else {
                        $a = false;
                    }',
                'assertions' => [
                    '$a' => 'false',
                ],
            ],
            'instanceofStatic' => [
                '<?php
                    abstract class Foo {
                        /**
                         * @return static[]
                         */
                        abstract public static function getArr() : array;

                        /**
                         * @return static|null
                         */
                        public static function getOne() {
                            $one = current(static::getArr());
                            return $one instanceof static ? $one : null;
                        }
                    }',
            ],
            'isaStaticClass' => [
                '<?php
                    abstract class Foo {
                        /**
                         * @return static[]
                         */
                        abstract public static function getArr() : array;

                        /**
                         * @return static|null
                         */
                        public static function getOne() {
                            $one = current(static::getArr());
                            return is_a($one, static::class, false) ? $one : null;
                        }
                    }',
            ],
            'isAClass' => [
                '<?php
                    class A {}
                    $a_class = rand(0, 1) ? A::class : "blargle";
                    if (is_a($a_class, A::class, true)) {
                      echo "cool";
                    }',
            ],
            'specificArrayFields' => [
                '<?php
                    /**
                     * @param array{field:string} $array
                     */
                    function print_field($array) : void {
                        echo $array["field"];
                    }

                    /**
                     * @param array{field:string,otherField:string} $array
                     */
                    function has_mix_of_fields($array) : void {
                        print_field($array);
                    }',
            ],
            'numericOrStringPropertySet' => [
                '<?php
                    /**
                     * @param string|null $b
                     */
                    function foo($b = null) : void {
                        if (is_numeric($b) || is_string($b)) {
                            takesNullableString($b);
                        }
                    }

                    function takesNullableString(?string $s) : void {}',
            ],
            'falsyScalar' => [
                '<?php
                    /**
                     * @param scalar|null $value
                     */
                    function Foo($value = null) : bool {
                      if (!$value) {
                        return true;
                      }
                      return false;
                    }',
            ],
            'numericStringAssertion' => [
                '<?php
                    /**
                     * @param mixed $a
                     */
                    function foo($a, string $b) : void {
                        if (is_numeric($b) && $a === $b) {
                            echo $a;
                        }
                    }',
            ],
            'reconcileNullableStringWithWeakEquality' => [
                '<?php
                    function foo(?string $s) : void {
                        if ($s == "hello" || $s == "goodbye") {
                            if ($s == "hello") {
                                echo "cool";
                            }
                            echo "cooler";
                        }
                    }',
            ],
            'reconcileNullableStringWithStrictEqualityStrings' => [
                '<?php
                    function foo(?string $s, string $a, string $b) : void {
                        if ($s === $a || $s === $b) {
                            if ($s === $a) {
                                echo "cool";
                            }
                            echo "cooler";
                        }
                    }',
            ],
            'reconcileNullableStringWithWeakEqualityStrings' => [
                '<?php
                    function foo(?string $s, string $a, string $b) : void {
                        if ($s == $a || $s == $b) {
                            if ($s == $a) {
                                echo "cool";
                            }
                            echo "cooler";
                        }
                    }',
            ],
            'allowWeakEqualityScalarType' => [
                '<?php
                    function foo(int $i) : void {
                        if ($i == "5") {}
                        if ("5" == $i) {}
                        if ($i == 5.0) {}
                        if (5.0 == $i) {}
                        if ($i == 0) {}
                        if (0 == $i) {}
                        if ($i == 0.0) {}
                        if (0.0 == $i) {}
                    }
                    function bar(float $i) : void {
                        $i = $i / 100.0;
                        if ($i == "5") {}
                        if ("5" == $i) {}
                        if ($i == 5) {}
                        if (5 == $i) {}
                        if ($i == "0") {}
                        if ("0" == $i) {}
                        if ($i == 0) {}
                        if (0 == $i) {}
                    }
                    function bat(string $i) : void {
                        if ($i == 5) {}
                        if (5 == $i) {}
                        if ($i == 5.0) {}
                        if (5.0 == $i) {}
                        if ($i == 0) {}
                        if (0 == $i) {}
                        if ($i == 0.0) {}
                        if (0.0 == $i) {}
                    }',
            ],
            'filterSubclassBasedOnParentInstanceof' => [
                '<?php
                    class A {}
                    class B extends A {
                       public function foo() : void {}
                    }

                    class C {}
                    class D extends C {}

                    $b_or_d = rand(0, 1) ? new B : new D;

                    if ($b_or_d instanceof A) {
                        $b_or_d->foo();
                    }',
            ],
            'SKIPPED-isArrayOnArrayKeyOffset' => [
                '<?php
                    /** @var array{s:array<mixed, array<int, string>|string>} */
                    $doc = [];

                    if (!is_array($doc["s"]["t"])) {
                        $doc["s"]["t"] = [$doc["s"]["t"]];
                    }',
                'assertions' => [
                    '$doc[\'s\'][\'t\']' => 'array<int, string>',
                ],
            ],
            'removeTrue' => [
                '<?php
                    $a = rand(0, 1) ? new stdClass : true;

                    if ($a === true) {
                      exit;
                    }

                    function takesStdClass(stdClass $s) : void {}
                    takesStdClass($a);',
            ],
            'noReconciliationInElseIf' => [
                '<?php
                    class A {}
                    $a = rand(0, 1) ? new A : null;

                    if (rand(0, 1)) {
                        // do nothing
                    } elseif (!$a) {
                        $a = new A();
                    }

                    if ($a) {}',
            ],
            'removeStringWithIsScalar' => [
                '<?php
                    $a = rand(0, 1) ? "hello" : null;

                    if (is_scalar($a)) {
                        exit;
                    }',
                'assertions' => [
                    '$a' => 'null',
                ],
            ],
            'removeNullWithIsScalar' => [
                '<?php
                    $a = rand(0, 1) ? "hello" : null;

                    if (!is_scalar($a)) {
                        exit;
                    }',
                'assertions' => [
                    '$a' => 'string',
                ],
            ],
            'scalarToNumeric' => [
                '<?php
                    /**
                     * @param scalar $thing
                     */
                    function Foo($thing) : void {
                        if (is_numeric($thing)) {}
                    }',
            ],
            'filterSubclassBasedOnParentNegativeInstanceof' => [
                '<?php
                    class Obj {}
                    class A extends Obj {}
                    class B extends A {}
                    class C extends Obj {}
                    class D extends C {}

                    function takesD(D $d) : void {}

                    /** @param B|D $bar */
                    function foo(Obj $bar) : void {
                        if (!$bar instanceof A) {
                            takesD($bar);
                        }
                    }',
            ],
            'dontEliminateAssignOp' => [
                '<?php
                    class Obj {}
                    class A extends Obj {}
                    class B extends A {}
                    class C extends Obj {}
                    class D extends C {}
                    class E extends C {}

                    function bar(Obj $node) : void {
                        if ($node instanceof B
                            || $node instanceof D
                            || $node instanceof E
                        ) {
                            if ($node instanceof C) {}
                            if ($node instanceof D) {}
                        }
                    }',
            ],
            'eliminateNonArrays' => [
                '<?php
                    interface I {}

                    function takesArray(array $_a): void {}

                    /** @param string|I|string[]|I[] $p */
                    function eliminatesNonArray($p): void {
                        if (is_array($p)) {
                            takesArray($p);
                        }
                    }',
            ],
            'eliminateNonIterable' => [
                '<?php
                    /**
                     * @param  iterable<string>|null $foo
                     */
                    function d(?iterable $foo): void {
                        if (is_iterable($foo)) {
                            foreach ($foo as $f) {}
                        }

                        if (!is_iterable($foo)) {

                        } else {
                            foreach ($foo as $f) {}
                        }
                    }',
            ],
            'isStringServerVar' => [
                '<?php
                    if (is_string($_SERVER["abc"])) {
                        echo substr($_SERVER["abc"], 1, 2);
                    }',
            ],
            'notObject' => [
                '<?php
                  function f(): ?object {
                        return rand(0,1) ? new stdClass : null;
                  }

                  $data = f();
                  if (!$data) {}
                  if ($data) {}',
            ],
            'reconcileWithInstanceof' => [
                '<?php
                    class A {}
                    class B extends A {
                        public function b() : bool {
                            return (bool) rand(0, 1);
                        }
                    }

                    function bar(?A $a) : void {
                        if (!$a || ($a instanceof B && $a->b())) {}
                    }',
            ],
            'reconcileFloatToEmpty' => [
                '<?php
                    function bar(float $f) : void {
                        if (!$f) {}
                    }',
            ],
            'scalarToBool' => [
                '<?php
                    /** @param mixed $s */
                    function foo($s) : void {
                        if (!is_scalar($s)) {
                            return;
                        }

                        if (is_bool($s)) {}
                        if (!is_bool($s)) {}
                        if (is_string($s)) {}
                        if (!is_string($s)) {}
                        if (is_int($s)) {}
                        if (!is_int($s)) {}
                        if (is_float($s)) {}
                        if (!is_float($s)) {}
                    }',
            ],
            'removeFromArray' => [
                '<?php
                    /**
                     * @param array<string> $v
                     */
                    function foo(array $v) : void {
                        if (!isset($v[0])) {
                            return;
                        }

                        if ($v[0] === " ") {
                            array_shift($v);
                        }

                        if (!isset($v[0])) {}
                    }',
            ],
            'arrayEquality' => [
                '<?php
                    /**
                     * @param array<string, array<array-key, string|int>> $haystack
                     * @param array<array-key, int|string> $needle
                     */
                    function foo(array $haystack, array $needle) : void {
                        foreach ($haystack as $arr) {
                            if ($arr === $needle) {}
                        }
                    }',
            ],
            'classResolvesBackToSelfAfterComparison' => [
                '<?php
                    class A {}
                    class B extends A {}
                    function getA() : A {
                      return new A();
                    }

                    $a = getA();
                    if ($a instanceof B) {
                        $a = new B;
                    }',
                'assertions' => [
                    '$a' => 'A',
                ],
            ],
            'isNumericCanBeScalar' => [
                '<?php
                    /** @param scalar $val */
                    function foo($val) : void {
                        if (!is_numeric($val)) {}
                    }',
            ],
            'classStringCanBeFalsy' => [
                '<?php
                    /** @param class-string<stdClass>|null $val */
                    function foo(?string $val) : void {
                        if (!$val) {}
                        if ($val) {}
                    }',
            ],
            'allowStringToObjectReconciliation' => [
                '<?php
                    /**
                     * @param string|object $maybe
                     *
                     * @throws InvalidArgumentException but it should not
                     */
                    function foo($maybe) : string {
                        /** @psalm-suppress DocblockTypeContradiction */
                        if ( ! is_string($maybe) && ! is_object($maybe)) {
                            throw new InvalidArgumentException("bad");
                        }

                        return is_string($maybe) ? $maybe : get_class($maybe);
                    }',
            ],
            'allowObjectToStringReconciliation' => [
                '<?php
                    /**
                     * @param string|object $maybe
                     *
                     * @throws InvalidArgumentException but it should not
                     */
                    function bar($maybe) : string {
                        /** @psalm-suppress DocblockTypeContradiction */
                        if ( ! is_string($maybe) && ! is_object($maybe)) {
                            throw new InvalidArgumentException("bad");
                        }

                        return is_object($maybe) ? get_class($maybe) : $maybe;
                    }',
            ],
            'removeArrayWithIterableCheck' => [
                '<?php
                    $s = rand(0,1) ? "foo" : [1];
                    if (!is_iterable($s)) {
                        strlen($s);
                    }',
            ],
            'removeIterableWithIterableCheck' => [
                '<?php
                    /** @var string|iterable */
                    $s = rand(0,1) ? "foo" : [1];
                    if (!is_iterable($s)) {
                        strlen($s);
                    }',
            ],
            'removeArrayWithIterableCheckWithExit' => [
                '<?php
                    $a = rand(0,1) ? "foo" : [1];
                    if (is_iterable($a)) {
                        return;
                    }
                    strlen($a);',
            ],
            'removeIterableWithIterableCheckWithExit' => [
                '<?php
                    /** @var string|iterable */
                    $a = rand(0,1) ? "foo" : [1];
                    if (is_iterable($a)) {
                        return;
                    }
                    strlen($a);',
            ],
            'removeCallable' => [
                '<?php
                    $s = rand(0,1) ? "strlen" : [1];
                    if (!is_callable($s)) {
                        array_pop($s);
                    }

                    $a = rand(0, 1) ? (function(): void {}) : 1.1;
                    if (!is_callable($a)) {
                        echo $a;
                    }',
            ],
            'removeCallableWithAssertion' => [
                '<?php
                    /**
                     * @param mixed $p
                     * @psalm-assert !callable $p
                     * @throws TypeError
                     */
                    function assertIsNotCallable($p): void { if (!is_callable($p)) throw new TypeError; }

                    /** @return callable|float */
                    function f() { return rand(0,1) ? "f" : 1.1; }

                    $a = f();
                    assert(!is_callable($a));

                    $b = f();
                    assertIsNotCallable($b);

                    atan($a);
                    atan($b);',
            ],
            'PHP71-removeNonCallable' => [
                '<?php
                    $f = rand(0, 1) ? "strlen" : 1.1;
                    if (is_callable($f)) {
                        Closure::fromCallable($f);
                    }',
            ],
            'compareObjectLikeToArray' => [
                '<?php
                    /**
                     * @param array<"from"|"to", bool> $a
                     * @return array{from:bool, to: bool}
                     */
                    function foo(array $a) : array {
                        return $a;
                    }',
            ],
            'dontChangeScalar' => [
                '<?php
                    /**
                     * @param scalar|null $val
                     */
                    function foo($val) : ? bool {
                        if ("1" === $val || 1 === $val) {
                            return true;
                        } elseif ("0" === $val || 0 === $val) {
                            return false;
                        }

                        return null;
                    }',
            ],
            'emptyArrayCheck' => [
                '<?php
                    /**
                     * @param non-empty-array $x
                     */
                    function example(array $x): void {}

                    /** @var array */
                    $x = [];
                    if ($x !== []) {
                        example($x);
                    }',
            ],
            'emptyArrayCheckInverse' => [
                '<?php
                    /**
                     * @param non-empty-array $x
                     */
                    function example(array $x): void {}

                    /** @var array */
                    $x = [];
                    if ($x === []) {
                    } else {
                        example($x);
                    }',
            ],
            'allowNumericToFoldIntoType' => [
                '<?php
                    /**
                     * @param mixed $width
                     * @param mixed $height
                     *
                     * @throws RuntimeException
                     */
                    function Foo($width, $height) : void {
                        if (!is_numeric($width) || !is_numeric($height)) {
                            throw new RuntimeException("Width & Height were not numeric!");
                        }

                        echo sprintf("padding-top:%s%%;", 100 * ($height/$width));
                    }',
            ],
            'notEmptyCheckOnMixedInTernary' => [
                '<?php
                    $a = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off" ? true : false;',
            ],
            'notEmptyCheckOnMixedInIf' => [
                '<?php
                    if (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") {
                        $a = true;
                    } else {
                        $a = false;
                    }',
            ],
            'dontRewriteNullableArrayAfterEmptyCheck' => [
                '<?php
                    /**
                     * @param array{x:int,y:int}|null $start_pos
                     * @return array{x:int,y:int}|null
                     */
                    function foo(?array $start_pos) : ?array {
                        if ($start_pos) {}

                        return $start_pos;
                    }',
            ],
            'falseEqualsBoolean' => [
                '<?php
                    class A {}
                    class B extends A {
                        public function foo() : void {}
                    }
                    class C extends A {
                        public function foo() : void {}
                    }
                    function bar(A $a) : void {
                        if (false === (!$a instanceof B || !$a instanceof C)) {
                            return;
                        }
                        $a->foo();
                    }
                    function baz(A $a) : void {
                        if ((!$a instanceof B || !$a instanceof C) === false) {
                            return;
                        }
                        $a->foo();
                    }',
            ],
            'selfInstanceofStatic' => [
                '<?php
                    class A {
                        public function foo(self $value): void {
                            if ($value instanceof static) {}
                        }
                    }',
            ],
            'reconcileCallable' => [
                '<?php
                    function reflectCallable(callable $callable): ReflectionFunctionAbstract {
                        if (\is_array($callable)) {
                            return new \ReflectionMethod($callable[0], $callable[1]);
                        } elseif ($callable instanceof \Closure || \is_string($callable)) {
                            return new \ReflectionFunction($callable);
                        } elseif (\is_object($callable)) {
                            return new \ReflectionMethod($callable, "__invoke");
                        } else {
                            throw new \InvalidArgumentException("Bad");
                        }
                    }',
            ],
            'noLeakyClassType' => [
                '<?php
                    class A {
                        public array $foo = [];
                        public array $bar = [];

                        public function setter() : void {
                            if ($this->foo) {
                                $this->foo = [];
                            }
                        }

                        public function iffer() : bool {
                            return $this->foo || $this->bar;
                        }
                    }',
            ],
            'noLeakyForeachType' => [
                '<?php

                    class A {
                        /** @var mixed */
                        public $_array_value = null;

                        private function getArrayValue() : ?array {
                            return rand(0, 1) ? [] : null;
                        }

                        public function setValue(string $var) : void {
                            $this->_array_value = $this->getArrayValue();

                            if ($this->_array_value !== null && !count($this->_array_value)) {
                                return;
                            }

                            switch ($var) {
                                case "a":
                                    foreach ($this->_array_value ?: [] as $v) {}
                                    break;

                                case "b":
                                    foreach ($this->_array_value ?: [] as $v) {}
                                    break;
                            }
                        }
                    }',
                [],
                ['MixedAssignment'],
            ],
            'nonEmptyThing' => [
                '<?php
                    /** @param mixed $clips */
                    function foo($clips, bool $found, int $id) : void {
                        if ($found === false) {
                            $clips = [];
                        }

                        $i = array_search($id, $clips);

                        if ($i !== false) {
                            unset($clips[$i]);
                        }
                    }',
                [],
                ['MixedArgument', 'MixedArrayAccess', 'MixedAssignment', 'MixedArrayOffset'],
            ],
            'allowNonEmptyArrayComparison' => [
                '<?php
                    /**
                     * @param non-empty-array $a
                     * @param array<string> $b
                     */
                    function foo(array $a, array $b) : void {
                        if ($a === $b) {}
                    }',
            ],
            'preventCombinatorialExpansion' => [
                '<?php
                    function gameOver(
                        int $b0,
                        int $b1,
                        int $b2,
                        int $b3,
                        int $b4,
                        int $b5,
                        int $b6,
                        int $b7,
                        int $b8
                    ): bool {
                        if (($b0 === 1 && $b1 === 1 && $b2 === 1)
                            || ($b3 === 1 && $b4 === 1 && $b5 === 1)
                            || ($b6 === 1 && $b7 === 1 && $b8 === 1)
                        ) {
                            return true;
                        }

                        return false;
                    }'
            ],
            'checkIterableType' => [
                '<?php
                    /**
                     * @param array<int> $x
                     */
                    function takesArray (array $x): void {}

                    /** @var iterable<int> */
                    $x = null;
                    assert(is_array($x));
                    takesArray($x);

                    /**
                     * @param Traversable<int> $x
                     */
                    function takesTraversable (Traversable $x): void {}

                    /** @var iterable<int> */
                    $x = null;
                    assert($x instanceof Traversable);
                    takesTraversable($x);',
            ],
            'dontReconcileArrayOffset' => [
                '<?php
                    /** @psalm-suppress TypeDoesNotContainType */
                    function foo(array $a) : void {
                        if (!is_array($a)) {
                            return;
                        }

                        if ($a[0] === 5) {}
                    }'
            ],
            'nullCoalesceTypedArrayValue' => [
                '<?php
                    /** @param string[] $arr */
                    function foo(array $arr) : string {
                        return $arr["b"] ?? "bar";
                    }',
            ],
            'nullCoalesceTypedValue' => [
                '<?php
                    function foo(?string $s) : string {
                        return $s ?? "bar";
                    }',
            ],
            'looseEqualityShouldNotConvertMixedToLiteralString' => [
                '<?php
                    /** @var mixed */
                    $int = 0;
                    $string = "0";

                    function takes_string(string $string) : void {}
                    function takes_int(int $int) : void {}

                    if ($int == $string) {
                        /** @psalm-suppress MixedArgument */
                        takes_int($int);
                    }'
            ],
            'looseEqualityShouldNotConverMixedToString' => [
                '<?php
                    /** @var mixed */
                    $int = 0;
                    /** @var string */
                    $string = "0";

                    function takes_string(string $string) : void {}
                    function takes_int(int $int) : void {}

                    if ($int == $string) {
                        /** @psalm-suppress MixedArgument */
                        takes_int($int);
                    }'
            ],
            'looseEqualityShouldNotConvertIntToString' => [
                '<?php
                    /** @var int */
                    $int = 0;
                    /** @var string */
                    $string = "0";

                    function takes_string(string $string) : void {}
                    function takes_int(int $int) : void {}

                    if ($int == $string) {
                        /** @psalm-suppress MixedArgument */
                        takes_int($int);
                    }'
            ],
            'removeAllObjects' => [
                '<?php
                    class A {}
                    class B extends A {
                        public function foo() : void {}
                    }
                    class BChild extends B {}
                    class C extends A {}
                    class D extends A {}

                    /** @param B|C|D $a */
                    function foo(A $a) : B {
                        if ($a instanceof C) {
                            $a = new B();
                        } elseif ($a instanceof D) {
                            $a = new B();
                        } elseif (!$a instanceof BChild) {
                            // do something
                        }

                        return $a;
                    }'
            ],
            'nullCoalescePossibleMixed' => [
                '<?php
                    /**
                     * @psalm-suppress MixedReturnStatement
                     * @psalm-suppress MixedInferredReturnType
                     */
                    function foo() : array {
                        return filter_input_array(INPUT_POST) ?? [];
                    }',
            ],
            'noCrashOnWeirdArrayKeys' => [
                '<?php
                    /**
                     * @psalm-suppress MixedPropertyFetch
                     * @psalm-suppress MixedArrayOffset
                     */
                    function foo(array $a, array $b) : void {
                        if (isset($a[$b[0]->id])) {}
                    }',
            ],
            'assertArrayReturnTypeNarrowed' => [
                '<?php
                    /** @return array{0:Exception} */
                    function f(array $a): array {
                        if ($a[0] instanceof Exception) {
                            return $a;
                        }

                        return [new Exception("bad")];
                    }',
            ],
            'assertTypeNarrowedByAssert' => [
                '<?php
                    /** @return array{0:Exception,1:Exception} */
                    function f(array $ret): array {
                        assert($ret[0] instanceof Exception);
                        assert($ret[1] instanceof Exception);
                        return $ret;
                    }',
            ],
            'assertTypeNarrowedByButOtherFetchesAreMixed' => [
                '<?php
                    /**
                     * @return array{0:Exception}
                     * @psalm-suppress MixedArgument
                     */
                    function f(array $ret): array {
                        assert($ret[0] instanceof Exception);
                        echo strlen($ret[1]);
                        return $ret;
                    }',
            ],
            'assertTypeNarrowedByNestedIsset' => [
                '<?php
                    /**
                     * @psalm-suppress MixedMethodCall
                     * @psalm-suppress MixedArgument
                     */
                    function foo(array $array = []): void {
                        if (array_key_exists("a", $array)) {
                            echo $array["a"];
                        }

                        if (array_key_exists("b", $array)) {
                            echo $array["b"]->format("Y-m-d");
                        }
                    }',
            ],
            'assertCheckOnNonZeroArrayOffset' => [
                '<?php
                    /**
                     * @param array{string,array|null} $a
                     * @return string
                     */
                    function f(array $a) {
                        assert(is_array($a[1]));
                        return $a[0];
                    }',
            ],
            'assertOnParseUrlOutput' => [
                '<?php
                    /**
                     * @param array<"a"|"b"|"c", mixed> $arr
                     */
                    function uriToPath(array $arr) : string {
                        if (!isset($arr["a"]) || $arr["b"] !== "foo") {
                            throw new \InvalidArgumentException("bad");
                        }

                        return (string) $arr["c"];
                    }',
            ],
            'combineAfterLoopAssert' => [
                '<?php
                    function foo(array $array) : void {
                        $c = 0;

                        if ($array["a"] === "a") {
                            foreach ([rand(0, 1), rand(0, 1)] as $i) {
                                if ($array["b"] === "c") {}
                                $c++;
                            }
                        }
                    }',
            ],
            'assertOnXml' => [
                '<?php
                    function f(array $array) : void {
                        if ($array["foo"] === "ok") {
                            if ($array["bar"] === "a") {}
                            if ($array["bar"] === "b") {}
                        }
                    }',
            ],
            'assertOnBacktrace' => [
                '<?php
                    function _validProperty(array $c, array $arr) : void {
                        if (empty($arr["a"])) {}

                        if ($c && $c["a"] !== "b") {}
                    }',
            ],
            'assertOnRemainderOfArray' => [
                '<?php
                    /**
                     * @psalm-suppress MixedInferredReturnType
                     * @psalm-suppress MixedReturnStatement
                     */
                    function foo(string $file_name) : int {
                        while ($data = getData()) {
                            if (is_numeric($data[0])) {
                                for ($i = 1; $i < count($data); $i++) {
                                    return $data[$i];
                                }
                            }
                        }

                        return 5;
                    }

                    function getData() : ?array {
                        return rand(0, 1) ? ["a", "b", "c"] : null;
                    }',
            ],
            'notEmptyCheck' => [
                '<?php
                    /**
                     * @psalm-suppress MixedAssignment
                     */
                    function load(string $objectName, array $config = []) : void {
                        if (isset($config["className"])) {
                            $name = $objectName;
                            $objectName = $config["className"];
                        }
                        if (!empty($config)) {}
                    }',
            ],
            'unsetAfterIssetCheck' => [
                '<?php
                    function checkbox(array $options = []) : void {
                        if ($options["a"]) {}

                        unset($options["a"], $options["b"]);
                    }',
            ],
            'dontCrashWhenGettingEmptyCountAssertions' => [
                '<?php
                    function foo() : bool {
                        /** @psalm-suppress TooFewArguments */
                        return count() > 0;
                    }',
            ],
            'assertHasArrayAccess' => [
                '<?php
                    /**
                     * @return array|ArrayAccess
                     */
                    function getBar(array $array) {
                        if (isset($array[\'foo\'][\'bar\'])) {
                            return $array[\'foo\'];
                        }

                        return [];
                    }',
            ],
            'assertHasArrayAccessWithType' => [
                '<?php
                    /**
                     * @param array<string, array<string, string>> $array
                     * @return array<string, string>
                     */
                    function getBar(array $array) : array {
                        if (isset($array[\'foo\'][\'bar\'])) {
                            return $array[\'foo\'];
                        }

                        return [];
                    }',
            ],
            'assertHasArrayAccessOnSimpleXMLElement' => [
                '<?php
                    function getBar(SimpleXMLElement $e, string $s) : void {
                        if (isset($e[$s])) {
                            echo (string) $e[$s];
                        }

                        if (isset($e[\'foo\'])) {
                            echo (string) $e[\'foo\'];
                        }

                        if (isset($e->bar)) {}
                    }',
            ],
            'assertArrayOffsetToTraversable' => [
                '<?php
                    function render(array $data): ?Traversable {
                        if ($data["o"] instanceof Traversable) {
                            return $data["o"];
                        }

                        return null;
                    }'
            ],
            'assertOnArrayShouldNotChangeType' => [
                '<?php
                    /** @return array|string|false */
                    function foo(string $a, string $b) {
                        $options = getopt($a, [$b]);

                        if (isset($options["config"])) {
                            $options["c"] = $options["config"];
                        }

                        if (isset($options["root"])) {
                            return $options["root"];
                        }

                        return false;
                    }'
            ],
            'assertOnArrayInTernary' => [
                '<?php
                    function foo(string $a, string $b) : void {
                        $o = getopt($a, [$b]);

                        $a = isset($o["a"]) && is_string($o["a"]) ? $o["a"] : "foo";
                        $a = isset($o["a"]) && is_string($o["a"]) ? $o["a"] : "foo";
                        echo $a;
                    }'
            ],
            'nonEmptyArrayAfterIsset' => [
                '<?php
                    /**
                     * @param array<string, int> $arr
                     * @return non-empty-array<string, int>
                     */
                    function foo(array $arr) : array {
                        if (isset($arr["a"])) {
                            return $arr;
                        }

                        return ["b" => 1];
                    }'
            ],
            'setArrayConstantOffset' => [
                '<?php
                    class S {
                        const A = 0;
                        const B = 1;
                        const C = 2;
                    }

                    function foo(array $arr) : void {
                        switch ($arr[S::A]) {
                            case S::B:
                            case S::C:
                            break;
                        }
                    }',
            ],
            'assertArrayWithPropertyOffset' => [
                '<?php
                    class A {
                        public int $id = 0;
                    }
                    class B {
                        public function foo() : void {}
                    }

                    /**
                     * @param array<int, B> $arr
                     */
                    function foo(A $a, array $arr): void {
                        if (!isset($arr[$a->id])) {
                            $arr[$a->id] = new B();
                        }
                        $arr[$a->id]->foo();
                    }'
            ],
            'assertAfterNotEmptyArrayCheck' => [
                '<?php
                    function foo(array $c): void {
                        if (!empty($c["d"])) {}

                        foreach (["a", "b", "c"] as $k) {
                            /** @psalm-suppress MixedAssignment */
                            foreach ($c[$k] as $d) {}
                        }
                    }',
            ],
            'assertNotEmptyTwiceOnInstancePropertyArray' => [
                '<?php
                    class A {
                        private array $c = [];

                        public function bar(string $s, string $t): void {
                            if (empty($this->c[$s]) && empty($this->c[$t])) {}
                        }
                    }'
            ],
            'assertNotEmptyTwiceOnStaticPropertyArray' => [
                '<?php
                    class A {
                        private static array $c = [];

                        public static function bar(string $s, string $t): void {
                            if (empty(self::$c[$s]) && empty(self::$c[$t])) {}
                        }
                    }'
            ],
            'assertConstantArrayOffsetTwice' => [
                '<?php
                    class A {
                        const FOO = "foo";
                        const BAR = "bar";

                        /** @psalm-suppress MixedArgument */
                        public function bar(array $args) : void {
                            if ($args[self::FOO]) {
                                echo $args[self::FOO];
                            }
                            if ($args[self::BAR]) {
                                echo $args[self::BAR];
                            }
                        }
                    }'
            ],
            'assertNotEmptyOnArray' => [
                '<?php
                    function foo(bool $c, array $arr) : void {
                        if ($c && !empty($arr["b"])) {
                            return;
                        }

                        if ($c && rand(0, 1)) {}
                    }'
            ],
            'assertIssetOnArray' => [
                '<?php
                    function foo(bool $c, array $arr) : void {
                        if ($c && $arr && isset($arr["b"]) && $arr["b"]) {
                            return;
                        }

                        if ($c && rand(0, 1)) {}
                    }'
            ],
            'assertMixedOffsetExists' => [
                '<?php
                    class A {
                        /** @var mixed */
                        private $arr;

                        /**
                         * @psalm-suppress MixedArrayAccess
                         * @psalm-suppress MixedReturnStatement
                         * @psalm-suppress MixedInferredReturnType
                         */
                        public function foo() : stdClass {
                            if (isset($this->arr[0])) {
                                return $this->arr[0];
                            }

                            $this->arr[0] = new stdClass;
                            return $this->arr[0];
                        }
                    }'
            ],
            'assertArrayKeyExistsRefinesType' => [
                '<?php
                    class Foo {
                        /** @var array<int,string> */
                        public const DAYS = [
                            1 => "mon",
                            2 => "tue",
                            3 => "wed",
                            4 => "thu",
                            5 => "fri",
                            6 => "sat",
                            7 => "sun",
                        ];

                        /** @param key-of<self::DAYS> $dayNum*/
                        private static function doGetDayName(int $dayNum): string {
                            return self::DAYS[$dayNum];
                        }

                        /** @throws LogicException */
                        public static function getDayName(int $dayNum): string {
                            if (! array_key_exists($dayNum, self::DAYS)) {
                                throw new \LogicException();
                            }
                            return self::doGetDayName($dayNum);
                        }
                    }'
            ],
            'assertPropertiesOfElseStatement' => [
                '<?php
                    class C {
                        public string $a = "";
                        public string $b = "";
                    }

                    function testElse(C $obj) : void {
                        if ($obj->a === "foo") {
                        } elseif ($obj->b === "bar") {
                        } else if ($obj->b === "baz") {}

                        if ($obj->b === "baz") {}
                    }'
            ],
            'assertPropertiesOfElseifStatement' => [
                '<?php
                    class C {
                        public string $a = "";
                        public string $b = "";
                    }

                    function testElseif(C $obj) : void {
                        if ($obj->a === "foo") {
                        } elseif ($obj->b === "bar") {
                        } elseif ($obj->b === "baz") {}

                        if ($obj->b === "baz") {}
                    }'
            ],
            'assertArrayWithOffset' => [
                '<?php
                    /**
                     * @param mixed $decoded
                     * @return array{icons:mixed}
                     */
                    function assertArrayWithOffset($decoded): array {
                        if (!is_array($decoded)
                            || !isset($decoded["icons"])
                        ) {
                            throw new RuntimeException("Bad");
                        }

                        return $decoded;
                    }'
            ],
            'avoidOOM' => [
                '<?php
                    function gameOver(
                        int $b0,
                        int $b1,
                        int $b2,
                        int $b3,
                        int $b4,
                        int $b5,
                        int $b6,
                        int $b7,
                        int $b8
                    ): bool {
                        if (($b0 === 1 && $b4 === 1 && $b8 === 1)
                            || ($b0 === 1 && $b1 === 1 && $b2 === 1)
                            || ($b0 === 1 && $b3 === 1 && $b6 === 1)
                            || ($b1 === 1 && $b4 === 1 && $b7 === 1)
                            || ($b2 === 1 && $b5 === 1 && $b8 === 1)
                            || ($b2 === 1 && $b4 === 1 && $b6 === 1)
                            || ($b3 === 1 && $b4 === 1 && $b5 === 1)
                            || ($b6 === 1 && $b7 === 1 && $b8 === 1)
                        ) {
                            return true;
                        }
                        return false;
                    }'
            ],
            'assertVarRedefinedInIfWithAnd' => [
                '<?php
                    class O {}

                    /**
                     * @param mixed $value
                     */
                    function exampleWithAnd($value): O {
                        if (is_string($value) && ($value = rand(0, 1) ? new O : null) !== null) {
                            return $value;
                        }

                        return new O();
                    }'
            ],
            'assertVarRedefinedInIfWithAndAndMethodCall' => [
                '<?php
                    class O {
                        public function foo() : bool { return true; }
                    }

                    /**
                     * @param mixed $value
                     */
                    function anotherExampleWithAnd($value): O {
                        if (is_string($value) && (($value = rand(0, 1) ? new O : null) !== null) && $value->foo()) {
                            return $value;
                        }

                        return new O();
                    }'
            ],
            'SKIPPED-assertVarRedefinedInIfWithOr' => [
                '<?php
                    class O {}

                    /**
                     * @param mixed $value
                     */
                    function exampleWithOr($value): O {
                        if (!is_string($value) || ($value = rand(0, 1) ? new O : null) === null) {
                            return new O();
                        }

                        return $value;
                    }'
            ],
            'assertVarRedefinedInIfWithExtraIf' => [
                '<?php
                    class O {}

                    /**
                     * @param mixed $value
                     */
                    function exampleWithOr($value): O {
                        if (!is_string($value)) {
                            return new O();
                        }

                        if (($value = rand(0, 1) ? new O : null) === null) {
                            return new O();
                        }

                        return $value;
                    }'
            ],
            'SKIPPED-assertVarRedefinedInOpWithAnd' => [
                '<?php
                    class O {
                        public function foo() : bool { return true; }
                    }

                    /** @var mixed */
                    $value = $_GET["foo"];

                    $a = is_string($value) && (($value = rand(0, 1) ? new O : null) !== null) && $value->foo();',
                [
                    '$a' => 'bool',
                ]
            ],
            'SKIPPED-assertVarRedefinedInOpWithOr' => [
                '<?php
                    class O {
                        public function foo() : bool { return true; }
                    }

                    /** @var mixed */
                    $value = $_GET["foo"];

                    $a = !is_string($value) || (($value = rand(0, 1) ? new O : null) === null) || $value->foo();',
                [
                    '$a' => 'bool',
                ]
            ],
            'assertVarInOrAfterAnd' => [
                '<?php
                    class A {}
                    class B extends A {}
                    class C extends A {}

                    function takesA(A $a): void {}

                    function foo(?A $a, ?A $b): void {
                        $c = ($a instanceof B && $b instanceof B) || ($a instanceof C && $b instanceof C);
                    }'
            ],
            'assertVarAfterNakedBinaryOp' => [
                '<?php
                    class A {
                        public bool $b = false;
                    }

                    function foo(A $a, A $b): void {
                        $c = !$a->b && !$b->b;
                        echo $a->b ? 1 : 0;
                    }'
            ],
            'assertAssertionsWithCreation' => [
                '<?php
                    class A {}
                    class B extends A {}
                    class C extends A {}

                    function getA(A $a): ?A {
                        return rand(0, 1) ? $a : null;
                    }

                    function foo(?A $a, ?A $c): void {
                        $c = $a && ($b = getA($a)) && $c ? 1 : 0;
                    }'
            ],
            'definedInBothBranchesOfConditional' => [
                '<?php
                    class A {
                        public function foo() : void {}
                    }

                    function getA(): ?A {
                        return rand(0, 1) ? new A() : null;
                    }

                    function foo(): void {
                        $a = null;
                        if (($a = getA()) || ($a = getA())) {
                            $a->foo();
                        }
                    }'
            ],
            'definedInConditionalAndCheckedInSubbranch' => [
                '<?php
                    class A {
                        public function foo() : void {}
                    }

                    function getA(): ?A {
                        return rand(0, 1) ? new A() : null;
                    }

                    function foo(): void {
                        if (($a = getA()) || rand(0, 1)) {
                            if ($a) {
                                $a->foo();
                            }
                        }
                    }'
            ],
            'definedInRhsOfConditionalInNegation' => [
                '<?php
                    class A {
                        public function foo() : void {}
                    }

                    function getA(): ?A {
                        return rand(0, 1) ? new A() : null;
                    }

                    function foo(): void {
                        if (rand(0, 1) && ($a = getA()) !== null) {
                            $a->foo();
                        }
                    }'
            ],
            'literalStringComparisonInIf' => [
                '<?php
                    function foo(string $t, bool $b) : void {
                        if ($t !== "a") {
                            if ($t === "b" && $b) {}
                        }
                    }

                    function bar(string $t, bool $b) : void {
                        if ($t !== "a") {
                            if ($t === "b" || $b) {}
                        }
                    }'
            ],
            'literalStringComparisonInElseif' => [
                '<?php
                    function foo(string $t, bool $b) : void {
                        if ($t === "a") {
                        } elseif ($t === "b" && $b) {}
                    }

                    function bar(string $t, bool $b) : void {
                        if ($t === "a") {
                        } elseif ($t === "b" || $b) {}
                    }'
            ],
            'literalStringComparisonInElse' => [
                '<?php
                    function foo(string $t, bool $b) : void {
                        if ($t === "a") {
                        } else {
                            if ($t === "b" && $b) {}
                        }
                    }

                    function bar(string $t, bool $b) : void {
                        if ($t === "a") {
                        } else {
                            if ($t === "b" || $b) {}
                        }
                    }'
            ],
            'definedInOrRHS' => [
                '<?php
                    class A {
                        public function foo() : void {}
                    }

                    function getA(): ?A {
                        return rand(0, 1) ? new A() : null;
                    }

                    function foo(bool $b): void {
                        $a = null;
                        if (!$b || !($a = getA())) {
                            return;
                        }
                        $a->foo();
                    }'
            ],
            'assertOnArrayThings' => [
                '<?php
                    /** @var array<string, array<int, string>> */
                    $a = null;

                    if (isset($a["b"]) || isset($a["c"])) {
                        $all_params = ($a["b"] ?? []) + ($a["c"] ?? []);
                    }'
            ],
            'assertOnNestedLogic' => [
                '<?php
                    function foo(?string $a) : void {
                        if (($a && rand(0, 1)) || rand(0, 1)) {
                            if ($a && strlen($a) > 5) {}
                        }
                    }'
            ],
            'arrayUnionTypeSwitching' => [
                '<?php
                    /** @param array<string, int|string> $map */
                    function foo(array $map, string $o) : void {
                        if ($mapped_type = $map[$o] ?? null) {
                            if (is_int($mapped_type)) {
                                return;
                            }
                        }

                        if (($mapped_type = $map[""] ?? null) && is_string($mapped_type)) {

                        }
                    }'
            ],
            'propertySetOnElementInConditional' => [
                '<?php
                    class DiffElem {
                        /** @var scalar */
                        public $old = false;
                        /** @var scalar */
                        public $new = false;
                    }

                    function foo(DiffElem $diff_elem) : void {
                        if ((is_string($diff_elem->old) && is_string($diff_elem->new))
                            || (is_int($diff_elem->old) && is_int($diff_elem->new))
                        ) {
                        }
                    }'
            ],
            'manyNestedAsserts' => [
                '<?php
                    class A {}
                    class B extends A {}
                    function foo(A $left, A $right) : void {
                        if (($left instanceof B && rand(0, 1))
                            || ($right instanceof B && rand(0, 1))
                        ) {
                            if ($left instanceof B
                                && rand(0, 1)
                                && $right instanceof B
                                && rand(0, 1)
                            ) {}
                        }
                    }'
            ],
            'assertionAfterAssertionInsideBooleanNot' => [
                '<?php
                    class A {}

                    function foo(?A $a) : void {
                        if (rand(0, 1) && !($a && rand(0, 1))) {
                            if ($a !== null) {}
                        }
                    }'
            ],
            'assertionAfterAssertionInsideExpandedBooleanNot' => [
                '<?php
                    class A {}

                    function bar(?A $a) : void {
                        if (rand(0, 1) && (!$a || rand(0, 1))) {
                            if ($a !== null) {}
                        }
                    }'
            ],
            'byrefChangeNested' => [
                '<?php
                    if (!preg_match("/hello/", "hello", $matches) || $matches[0] !== "hello") {}'
            ],
            'checkBeforeUse' => [
                '<?php
                    class A {
                        public function foo() : void {}
                    }

                    function takesA(A $a) : bool {
                        return true;
                    }

                    /**
                     * @param mixed $a
                     */
                    function takesMaybeA($a) : void {
                        /**
                         * @psalm-suppress MixedArgument
                         */
                        if ($a !== null && takesA($a)) {}
                    }'
            ],
            'nestedAssertInElse' => [
                '<?php
                    function foo(string $type, bool $and) : void {
                        if ($type === "a") {
                        } elseif ($type === "b" && $and) {
                        } else {
                            if ($type === "c" && $and) {}
                        }
                    }'
            ],
            'allowEmptyScalarAndNonEmptyScalarAssertions' => [
                '<?php
                    /** @param mixed $value */
                    function foo($value) : void {
                        if (\is_scalar($value)) {
                            if ($value) {
                                echo $value;
                            } else {
                                echo $value;
                            }
                        }
                    }'
            ],
            'ignoreRedundantAssertion' => [
                '<?php
                    function gimmeAString(?string $v): string {
                        /** @psalm-suppress TypeDoesNotContainType */
                        assert(is_string($v) || is_object($v));

                        return $v;
                    }'
            ],
            'possiblyDefinedVarInAssertion' => [
                '<?php
                    class A {
                        public function test() : bool { return true; }
                    }

                    function getMaybeA() : ?A { return rand(0, 1) ? new A : null; }

                    function foo() : void {
                        if (rand(0, 10) && ($a = getMaybeA()) && !$a->test()) {
                            return;
                        }

                        echo isset($a);
                    }'
            ],
            'assertOnVarStaticClassKey' => [
                '<?php
                    abstract class Obj {
                        /**
                         * @param array<class-string, array<string, int>> $arr
                         * @return array<string, int>
                         */
                        public static function getArr(array $arr) : array {
                            if (!isset($arr[static::class])) {
                                $arr[static::class] = ["hello" => 5];
                            }

                            return $arr[static::class];
                        }
                    }'
            ],
            'assertOnVarVar' => [
                '<?php
                    abstract class Obj {
                        /**
                         * @param array<class-string, array<string, int>> $arr
                         * @return array<string, int>
                         */
                        function getArr(array $arr, string $s) : array {
                            if (!isset($arr[$s])) {
                                $arr[$s] = ["hello" => 5];
                            }

                            return $arr[$s];
                        }
                    }'
            ],
            'assertOnPropertyStaticClassKey' => [
                '<?php
                    abstract class Obj {
                        /** @var array<class-string, array<string, int>> */
                        private static $arr = [];

                        /** @return array<string, int> */
                        public static function getArr() : array {
                            $arr = self::$arr;
                            if (!isset($arr[static::class])) {
                                $arr[static::class] = ["hello" => 5];
                            }

                            return $arr[static::class];
                        }
                    }'
            ],
            'assertOnStaticPropertyOffset' => [
                '<?php
                    class C {
                        /** @var array<string, string>|null */
                        private static $map = [];

                        public static function foo(string $id) : ?string {
                            if (isset(self::$map[$id])) {
                                return self::$map[$id];
                            }

                            return null;
                        }
                    }',
            ],
            'issetTwice' => [
                '<?php
                    class B {
                        public function foo() : bool {
                            return true;
                        }
                    }

                    /** @param array<int, B> $p */
                    function foo(array $p, int $id) : void {
                        if ((isset($p[$id]) && rand(0, 1))
                            || (!isset($p[$id]) && rand(0, 1))
                        ) {
                            isset($p[$id]) ? $p[$id] : new B;
                            isset($p[$id]) ? $p[$id]->foo() : "bar";
                        }
                    }'
            ],
            'reconcileEmptinessBetter' => [
                '<?php
                    /**
                     * @param string|array $valuePath
                     */
                    function combine($valuePath) : void {
                        if (!empty($valuePath) && is_array($valuePath)) {

                        } elseif (!empty($valuePath)) {
                            echo $valuePath;
                        }
                    }',
            ],
            'issetAssertionOnStaticProperty' => [
                '<?php
                    class C {
                        protected static array $cache = [];

                        /**
                         * @psalm-suppress MixedArrayAccess
                         * @psalm-suppress MixedReturnStatement
                         * @psalm-suppress MixedInferredReturnType
                         */
                        public static function get(string $k1, string $k2) : ?string {
                            if (!isset(static::$cache[$k1][$k2])) {
                                return null;
                            }

                            return static::$cache[$k1][$k2];
                        }
                    }'
            ],
            'orWithAssignment' => [
                '<?php
                    function maybeString(): ?string {
                        return rand(0, 10) > 4 ? "test" : null;
                    }

                    function test(): string {
                        $foo = maybeString();
                        ($foo !== null) || ($foo = "");

                        return $foo;
                    }'
            ],
            'andWithAssignment' => [
                '<?php
                    function maybeString(): ?string {
                        return rand(0, 10) > 4 ? "test" : null;
                    }

                    function test(): string {
                        $foo = maybeString();
                        ($foo === null) && ($foo = "");

                        return $foo;
                    }'
            ],
        ];
    }

    /**
     * @return iterable<string,array{string,error_message:string,2?:string[],3?:bool,4?:string}>
     */
    public function providerInvalidCodeParse()
    {
        return [
            'makeNonNullableNull' => [
                '<?php
                    class A { }
                    $a = new A();
                    if ($a === null) {
                    }',
                'error_message' => 'TypeDoesNotContainNull',
            ],
            'makeInstanceOfThingInElseif' => [
                '<?php
                    class A { }
                    class B { }
                    class C { }
                    $a = rand(0, 10) > 5 ? new A(): new B();
                    if ($a instanceof A) {
                    } elseif ($a instanceof C) {
                    }',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'functionValueIsNotType' => [
                '<?php
                    if (json_last_error() === "5") { }',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'stringIsNotTnt' => [
                '<?php
                    if (5 === "5") { }',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'stringIsNotNull' => [
                '<?php
                    if (5 === null) { }',
                'error_message' => 'TypeDoesNotContainNull',
            ],
            'stringIsNotFalse' => [
                '<?php
                    if (5 === false) { }',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'typeTransformation' => [
                '<?php
                    $a = "5";

                    if (is_numeric($a)) {
                        if (is_int($a)) {
                            echo $a;
                        }
                    }',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'dontEraseNullAfterLessThanCheck' => [
                '<?php
                    $a = mt_rand(0, 1) ? mt_rand(-10, 10): null;

                    if ($a < 0) {
                      echo $a + 3;
                    }',
                'error_message' => 'PossiblyNullOperand',
            ],
            'dontEraseNullAfterGreaterThanCheck' => [
                '<?php
                    $a = mt_rand(0, 1) ? mt_rand(-10, 10): null;

                    if (0 > $a) {
                      echo $a + 3;
                    }',
                'error_message' => 'PossiblyNullOperand',
            ],
            'nonRedundantConditionGivenDocblockType' => [
                '<?php
                    /** @param array[] $arr */
                    function foo(array $arr) : void {
                       if ($arr === "hello") {}
                    }',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'lessSpecificArrayFields' => [
                '<?php
                    /**
                     * @param array{field:string, otherField:string} $array
                     */
                    function print_field($array) : void {
                        echo $array["field"] . " " . $array["otherField"];
                    }

                    print_field(["field" => "name"]);',
                'error_message' => 'InvalidArgument',
            ],
            'intersectionIncorrect' => [
                '<?php
                    interface I {
                        public function bat(): void;
                    }

                    interface C {}

                    /** @param I&C $a */
                    function takesIandC($a): void {}

                    class A {
                        public function foo(): void {
                            if ($this instanceof I) {
                                takesIandC($this);
                            }
                        }
                    }',
                'error_message' => 'InvalidArgument',
            ],
            'catchTypeMismatchInBinaryOp' => [
                '<?php
                    /** @return array<int, string|int> */
                    function getStrings(): array {
                        return ["hello", "world", 50];
                    }

                    $a = getStrings();

                    if (is_bool($a[0]) && $a[0]) {}',
                'error_message' => 'DocblockTypeContradiction',
            ],
            'preventWeakEqualityToObject' => [
                '<?php
                    function foo(int $i, stdClass $s) : void {
                        if ($i == $s) {}
                    }',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'properReconciliationInElseIf' => [
                '<?php
                    class A {}
                    $a = rand(0, 1) ? new A : null;

                    if (rand(0, 1)) {
                        $a = new A();
                    } elseif (!$a) {
                        $a = new A();
                    }

                    if ($a) {}',
                'error_message' => 'RedundantCondition',
            ],
            'allRemovalOfStringWithIsScalar' => [
                '<?php
                    $a = rand(0, 1) ? "hello" : "goodbye";

                    if (is_scalar($a)) {
                        exit;
                    }',
                'error_message' => 'RedundantCondition',
            ],
            'noRemovalOfStringWithIsScalar' => [
                '<?php
                    $a = rand(0, 1) ? "hello" : "goodbye";

                    if (!is_scalar($a)) {
                        exit;
                    }',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'impossibleNullEquality' => [
                '<?php
                    $i = 5;
                    echo $i === null;',
                'error_message' => 'TypeDoesNotContainNull',
            ],
            'impossibleTrueEquality' => [
                '<?php
                    $i = 5;
                    echo $i === true;',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'impossibleFalseEquality' => [
                '<?php
                    $i = 5;
                    echo $i === false;',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'impossibleNumberEquality' => [
                '<?php
                    $i = 5;
                    echo $i === 3;',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'SKIPPED-noIntersectionOfArrayOrTraversable' => [
                '<?php
                    function foo(iterable $iterable) : void {
                        if (\is_array($iterable) && $iterable instanceof \Traversable) {}
                    }',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'scalarToBoolContradiction' => [
                '<?php
                    /** @param mixed $s */
                    function foo($s) : void {
                        if (!is_scalar($s)) {
                            return;
                        }

                        if (!is_bool($s)) {
                            if (is_bool($s)) {}
                        }
                    }',
                'error_message' => 'ParadoxicalCondition',
            ],
            'noCrashWhenCastingArray' => [
                '<?php
                    function foo() : string {
                        return (object) ["a" => 1, "b" => 2];
                    }',
                'error_message' => 'InvalidReturnStatement',
            ],
            'preventStrongEqualityScalarType' => [
                '<?php
                    function bar(float $f) : void {
                        if ($f === 0) {}
                    }',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'preventYodaStrongEqualityScalarType' => [
                '<?php
                    function bar(float $f) : void {
                        if (0 === $f) {}
                    }',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'classCannotNotBeSelf' => [
                '<?php
                    class A {}
                    class B extends A {}
                    function getA() : A {
                      return new A();
                    }

                    $a = getA();
                    if ($a instanceof B) {
                        $a = new B;
                    }

                    if ($a instanceof A) {}',
                'error_message' => 'RedundantCondition',
            ],
            'preventImpossibleComparisonToTrue' => [
                '<?php
                    /** @return false|string */
                    function firstChar(string $s) {
                      return empty($s) ? false : $s[0];
                    }

                    if (true === firstChar("sdf")) {}',
                'error_message' => 'DocblockTypeContradiction',
            ],
            'preventAlwaysPossibleComparisonToTrue' => [
                '<?php
                    /** @return false|string */
                    function firstChar(string $s) {
                      return empty($s) ? false : $s[0];
                    }

                    if (true !== firstChar("sdf")) {}',
                'error_message' => 'RedundantConditionGivenDocblockType',
            ],
            'preventAlwaysImpossibleComparisonToFalse' => [
                '<?php
                    function firstChar(string $s) : string { return $s; }

                    if (false === firstChar("sdf")) {}',
                'error_message' => 'TypeDoesNotContainType',
            ],
            'preventAlwaysPossibleComparisonToFalse' => [
                '<?php
                    function firstChar(string $s) : string { return $s; }

                    if (false !== firstChar("sdf")) {}',
                'error_message' => 'RedundantCondition',
            ],
            'nullCoalesceImpossible' => [
                '<?php
                    function foo(?string $s) : string {
                        return ((string) $s) ?? "bar";
                    }',
                'error_message' => 'TypeDoesNotContainType'
            ],
            'allowEmptyScalarAndNonEmptyScalarAssertions1' => [
                '<?php
                    /** @param mixed $value */
                    function foo($value) : void {
                        if (\is_scalar($value)) {
                            if ($value) {
                                if (\is_scalar($value)) {}
                            } else {
                                echo $value;
                            }
                        }
                    }',
                'error_message' => 'RedundantCondition',
            ],
            'allowEmptyScalarAndNonEmptyScalarAssertions2' => [
                '<?php
                    /** @param mixed $value */
                    function foo($value) : void {
                        if (\is_scalar($value)) {
                            if ($value) {
                                echo $value;
                            } else {
                                if (\is_scalar($value)) {}
                            }
                        }
                    }',
                'error_message' => 'RedundantCondition',
            ],
            'allowEmptyScalarAndNonEmptyScalarAssertions3' => [
                '<?php
                    /** @param mixed $value */
                    function foo($value) : void {
                        if (\is_scalar($value)) {
                            if ($value) {
                                if ($value) {}
                            } else {
                                echo $value;
                            }
                        }
                    }',
                'error_message' => 'RedundantCondition',
            ],
            'allowEmptyScalarAndNonEmptyScalarAssertions4' => [
                '<?php
                    /** @param mixed $value */
                    function foo($value) : void {
                        if (\is_scalar($value)) {
                            if ($value) {
                                echo $value;
                            } else {
                                if (!$value) {}
                            }
                        }
                    }',
                'error_message' => 'RedundantCondition',
            ],
            'catchRedundantConditionOnBinaryOpForwards' => [
                '<?php
                    class App {}

                    function test(App $app) : void {
                        if ($app || rand(0, 1)) {}
                    }',
                'error_message' => 'TypeDoesNotContainType',
            ],
        ];
    }
}
