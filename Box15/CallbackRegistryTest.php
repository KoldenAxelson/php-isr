<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/CallbackRegistry.php";

/**
 * CallbackRegistryTest
 *
 * Comprehensive test suite for CallbackRegistry
 */
class CallbackRegistryTest extends TestCase
{
    private CallbackRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CallbackRegistry();
    }

    public function testConstructorCreatesEmptyRegistry(): void
    {
        $this->assertSame(0, $this->registry->count());
        $this->assertSame([], $this->registry->list());
    }

    public function testRegisterBasicCallback(): void
    {
        $callback = function () {
            return "Hello World";
        };

        $this->registry->register("test", $callback);

        $this->assertTrue($this->registry->has("test"));
        $this->assertSame(1, $this->registry->count());
    }

    public function testRegisterWithMetadata(): void
    {
        $callback = function () {
            return "test";
        };
        $metadata = [
            "description" => "Test callback",
            "default_ttl" => 3600,
            "tags" => ["test"],
        ];

        $this->registry->register("test", $callback, $metadata);

        $this->assertSame($metadata, $this->registry->getMetadata("test"));
    }

    public function testRegisterEmptyMetadata(): void
    {
        $callback = function () {
            return "test";
        };

        $this->registry->register("test", $callback);

        $this->assertSame([], $this->registry->getMetadata("test"));
    }

    public function testRegisterThrowsOnDuplicate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Callback 'test' is already registered");

        $this->registry->register("test", function () {});
        $this->registry->register("test", function () {}); // Should throw
    }

    public function testRegisterThrowsOnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Callback name cannot be empty");

        $this->registry->register("", function () {});
    }

    public function testRegisterThrowsOnInvalidName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("must contain only alphanumeric");

        $this->registry->register("test@callback", function () {});
    }

    public function testRegisterAcceptsValidNames(): void
    {
        $this->registry->register("valid_name", function () {});
        $this->registry->register("valid-name", function () {});
        $this->registry->register("valid.name", function () {});
        $this->registry->register("ValidName123", function () {});

        $this->assertSame(4, $this->registry->count());
    }

    public function testGetReturnsCallback(): void
    {
        $callback = function () {
            return "Hello";
        };
        $this->registry->register("test", $callback);

        $retrieved = $this->registry->get("test");

        $this->assertIsCallable($retrieved);
        $this->assertSame("Hello", $retrieved());
    }

    public function testGetReturnsNullForMissing(): void
    {
        $this->assertNull($this->registry->get("nonexistent"));
    }

    public function testHasReturnsTrueForRegistered(): void
    {
        $this->registry->register("test", function () {});

        $this->assertTrue($this->registry->has("test"));
    }

    public function testHasReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->registry->has("nonexistent"));
    }

    public function testListReturnsAllNames(): void
    {
        $this->registry->register("callback1", function () {});
        $this->registry->register("callback2", function () {});
        $this->registry->register("callback3", function () {});

        $list = $this->registry->list();

        $this->assertCount(3, $list);
        $this->assertContains("callback1", $list);
        $this->assertContains("callback2", $list);
        $this->assertContains("callback3", $list);
    }

    public function testListReturnsEmptyArrayWhenEmpty(): void
    {
        $this->assertSame([], $this->registry->list());
    }

    public function testGetMetadataReturnsMetadata(): void
    {
        $metadata = ["key" => "value"];
        $this->registry->register("test", function () {}, $metadata);

        $this->assertSame($metadata, $this->registry->getMetadata("test"));
    }

    public function testGetMetadataReturnsNullForMissing(): void
    {
        $this->assertNull($this->registry->getMetadata("nonexistent"));
    }

    public function testGetRegisteredAtReturnsTimestamp(): void
    {
        $before = time();
        $this->registry->register("test", function () {});
        $after = time();

        $timestamp = $this->registry->getRegisteredAt("test");

        $this->assertIsInt($timestamp);
        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }

    public function testGetRegisteredAtReturnsNullForMissing(): void
    {
        $this->assertNull($this->registry->getRegisteredAt("nonexistent"));
    }

    public function testUnregisterRemovesCallback(): void
    {
        $this->registry->register("test", function () {});

        $result = $this->registry->unregister("test");

        $this->assertTrue($result);
        $this->assertFalse($this->registry->has("test"));
        $this->assertSame(0, $this->registry->count());
    }

    public function testUnregisterReturnsFalseForMissing(): void
    {
        $result = $this->registry->unregister("nonexistent");

        $this->assertFalse($result);
    }

    public function testClearRemovesAllCallbacks(): void
    {
        $this->registry->register("callback1", function () {});
        $this->registry->register("callback2", function () {});
        $this->registry->register("callback3", function () {});

        $this->registry->clear();

        $this->assertSame(0, $this->registry->count());
        $this->assertSame([], $this->registry->list());
    }

    public function testCallbackWithParameters(): void
    {
        $callback = function ($params) {
            return "Hello " . $params["name"];
        };

        $this->registry->register("greeting", $callback);

        $retrieved = $this->registry->get("greeting");
        $result = $retrieved(["name" => "World"]);

        $this->assertSame("Hello World", $result);
    }

    public function testCallbackWithClosure(): void
    {
        $multiplier = 5;
        $callback = function ($value) use ($multiplier) {
            return $value * $multiplier;
        };

        $this->registry->register("multiply", $callback);

        $retrieved = $this->registry->get("multiply");
        $this->assertSame(25, $retrieved(5));
    }

    public function testCallbackWithStaticMethod(): void
    {
        $this->registry->register("static", [self::class, "staticCallback"]);

        $retrieved = $this->registry->get("static");
        $this->assertSame("static result", $retrieved());
    }

    public static function staticCallback(): string
    {
        return "static result";
    }

    public function testCallbackWithInvokableObject(): void
    {
        $invokable = new class {
            public function __invoke()
            {
                return "invokable result";
            }
        };

        $this->registry->register("invokable", $invokable);

        $retrieved = $this->registry->get("invokable");
        $this->assertSame("invokable result", $retrieved());
    }

    public function testGetAllInfoReturnsCompleteInformation(): void
    {
        $metadata1 = ["description" => "First callback"];
        $metadata2 = ["description" => "Second callback"];

        $this->registry->register("callback1", function () {}, $metadata1);
        $this->registry->register("callback2", function () {}, $metadata2);

        $info = $this->registry->getAllInfo();

        $this->assertCount(2, $info);
        $this->assertArrayHasKey("callback1", $info);
        $this->assertArrayHasKey("callback2", $info);

        $this->assertSame($metadata1, $info["callback1"]["metadata"]);
        $this->assertSame($metadata2, $info["callback2"]["metadata"]);

        $this->assertTrue($info["callback1"]["is_callable"]);
        $this->assertTrue($info["callback2"]["is_callable"]);

        $this->assertIsInt($info["callback1"]["registered_at"]);
        $this->assertIsInt($info["callback2"]["registered_at"]);
    }

    public function testMultipleRegistrationsAndRetrievals(): void
    {
        // Register multiple callbacks
        $callbacks = [
            "homepage" => function () {
                return "<html>Home</html>";
            },
            "about" => function () {
                return "<html>About</html>";
            },
            "contact" => function () {
                return "<html>Contact</html>";
            },
        ];

        foreach ($callbacks as $name => $callback) {
            $this->registry->register($name, $callback);
        }

        // Verify all are registered
        $this->assertSame(3, $this->registry->count());

        // Retrieve and execute each
        foreach ($callbacks as $name => $original) {
            $retrieved = $this->registry->get($name);
            $this->assertIsCallable($retrieved);
            $this->assertSame($original(), $retrieved());
        }
    }

    public function testRegistryPersistsCallbackState(): void
    {
        $counter = 0;
        $callback = function () use (&$counter) {
            return ++$counter;
        };

        $this->registry->register("counter", $callback);

        $retrieved = $this->registry->get("counter");

        $this->assertSame(1, $retrieved());
        $this->assertSame(2, $retrieved());
        $this->assertSame(3, $retrieved());
    }
}
