<?php
namespace Psalm\Tests;

use Psalm\Tests\Traits\InvalidCodeAnalysisTestTrait;
use Psalm\Tests\Traits\ValidCodeAnalysisTestTrait;

class PropertyTypeInvarianceTest extends TestCase
{
    use InvalidCodeAnalysisTestTrait;
    use ValidCodeAnalysisTestTrait;

    /**
     * @return iterable<string,array{string,assertions?:array<string,string>,error_levels?:string[]}>
     */
    public function providerValidCodeParse(): iterable
    {
        return [
            'validcode' => [
                '<?php
                    class ParentClass
                    {
                        /** @var null|string */
                        protected $mightExist;

                        protected ?string $mightExistNative = null;

                        /** @var string */
                        protected $doesExist = "";

                        protected string $doesExistNative = "";
                    }

                    class ChildClass extends ParentClass
                    {
                        /** @var null|string */
                        protected $mightExist = "";

                        protected ?string $mightExistNative = null;

                        /** @var string */
                        protected $doesExist = "";

                        protected string $doesExistNative = "";
                    }',
            ],
            'allowTemplatedInvariance' => [
                '<?php
                    /**
                     * @template T as string|null
                     */
                    abstract class A {
                        /** @var T */
                        public $foo;
                    }

                    /**
                     * @extends A<string>
                     */
                    class AChild extends A {
                        /** @var string */
                        public $foo = "foo";
                    }',
            ],
            'allowTemplatedInvarianceWithListTemplate' => [
                '<?php
                    abstract class Item {}
                    class Foo extends Item {}

                    /** @template TItem of Item */
                    abstract class ItemCollection
                    {
                        /** @var list<TItem> */
                        protected $items = [];
                    }

                    /** @extends ItemCollection<Foo> */
                    class FooCollection extends ItemCollection
                    {
                        /** @var list<Foo> */
                        protected $items = [];
                    }',
            ],
            'allowTemplatedInvarianceWithClassTemplate' => [
                '<?php
                    abstract class Item {}
                    class Foo extends Item {}

                    /** @template T */
                    class Collection {}

                    /** @template TItem of Item */
                    abstract class ItemCollection
                    {
                        /** @var Collection<TItem>|null */
                        protected $items;
                    }

                    /** @extends ItemCollection<Foo> */
                    class FooCollection extends ItemCollection
                    {
                        /** @var Collection<Foo>|null */
                        protected $items;
                    }',
            ],
            'allowTemplatedInvarianceWithClassStringTemplate' => [
                '<?php
                    abstract class Item {}
                    class Foo extends Item {}

                    /** @template T of Item */
                    abstract class ItemType
                    {
                        /** @var class-string<T>|null */
                        protected $type;
                    }

                    /** @extends ItemType<Foo> */
                    class FooTypes extends ItemType
                    {
                        /** @var class-string<Foo>|null */
                        protected $type;
                    }',
            ],
            'templatedInvarianceGrandchild' => [
                '<?php
                    abstract class Item {}
                    class Foo extends Item {}
                    class Bar extends Foo {}

                    /** @template T of Item */
                    abstract class ItemCollection
                    {
                        /** @var list<T> */
                        protected $items = [];
                    }

                    /**
                     * @template T of Foo
                     * @extends ItemCollection<T>
                     */
                    class FooCollection extends ItemCollection
                    {
                        /** @var list<T> */
                        protected $items = [];
                    }

                    /** @extends FooCollection<Bar> */
                    class BarCollection extends FooCollection
                    {
                        /** @var list<Bar> */
                        protected $items = [];
                    }',
            ],
            'allowTemplateCovariant' => [
                '<?php
                    class Foo {}
                    class Bar extends Foo {}
                    class Baz extends Bar {}

                    /** @template-covariant T */
                    class Pair
                    {
                        /** @var T|null */
                        public $a;

                        /** @var T|null */
                        public $b;
                    }

                    /** @extends Pair<Foo> */
                    class FooPair extends Pair
                    {
                        /** @var Bar|null */
                        public $a;

                        /** @var Baz|null */
                        public $b;
                    }',
            ],
            'allowTemplateCovariantManyTemplates' => [
                '<?php
                    class A {}
                    class B extends A {}
                    class C extends B {}

                    /**
                     * @template Ta
                     * @template Tb
                     * @template-covariant Tc
                     * @template Td
                     */
                    class Foo {
                        /** @var Ta|null */
                        public $a;

                        /** @var Tb|null */
                        public $b;

                        /** @var Tc|null */
                        public $c;

                        /** @var Td|null */
                        public $d;
                    }

                    /**
                     * @template Ta
                     * @template Tb
                     * @template-covariant Tc
                     * @template Td
                     * @extends Foo<Ta, Tb, Tc, Td>
                     */
                    class Bar extends Foo {}

                    /**
                     * @template Ta
                     * @template Tb
                     * @template-covariant Tc
                     * @template Td
                     * @extends Bar<A, B, A, C>
                     */
                    class Baz extends Bar {
                        /** @var A|null */
                        public $a;

                        /** @var B|null */
                        public $b;

                        /** @var C|null */
                        public $c;

                        /** @var C|null */
                        public $d;
                    }',
            ],
        ];
    }

    /**
     * @return iterable<string,array{string,error_message:string,1?:string[],2?:bool,3?:string}>
     */
    public function providerInvalidCodeParse(): iterable
    {
        return [
            'variantDocblockProperties' => [
                '<?php
                    class ParentClass
                    {
                        /** @var null|string */
                        protected $mightExist;
                    }

                    class ChildClass extends ParentClass
                    {
                        /** @var string */
                        protected $mightExist = "";
                    }',
                'error_message' => 'NonInvariantDocblockPropertyType',
            ],
            'variantProperties' => [
                '<?php
                    class ParentClass
                    {
                        protected ?string $mightExist = null;
                    }

                    class ChildClass extends ParentClass
                    {
                        protected string $mightExist = "";
                    }',
                'error_message' => 'NonInvariantPropertyType',
            ],
            'variantTemplatedProperties' => [
                '<?php
                    /**
                     * @template T as string|null
                     */
                    abstract class A {
                        /** @var T */
                        public $foo;
                    }

                    /**
                     * @extends A<string>
                     */
                    class AChild extends A {
                        /** @var int */
                        public $foo = 0;
                    }',
                'error_message' => 'NonInvariantDocblockPropertyType',
            ],
            'variantTemplatedGrandchild' => [
                '<?php
                    abstract class Item {}
                    class Foo extends Item {}
                    class Bar extends Foo {}

                    /** @template T of Item */
                    abstract class ItemCollection
                    {
                        /** @var list<T> */
                        protected $items = [];
                    }

                    /**
                     * @template T of Foo
                     * @extends ItemCollection<T>
                     */
                    class FooCollection extends ItemCollection
                    {
                        /** @var list<T> */
                        protected $items = [];
                    }

                    /** @extends FooCollection<Bar> */
                    class BarCollection extends FooCollection
                    {
                        /** @var list<Item> */ // Should be list<Bar>
                        protected $items = [];
                    }',
                'error_message' => 'NonInvariantDocblockPropertyType',
            ],
            'variantPropertiesWithTemplateNotSpecified' => [
                '<?php
                    class Foo {}

                    /** @template T */
                    class Pair
                    {
                        /** @var T|null */
                        protected $a;

                        /** @var T|null */
                        protected $b;
                    }

                    class FooPair extends Pair
                    {
                        /** @var Foo|null */ // Template defaults to mixed, this is invariant
                        protected $a;

                        /** @var Foo|null */
                        protected $b;
                    }',
                'error_message' => 'NonInvariantDocblockPropertyType',
            ],
        ];
    }
}
