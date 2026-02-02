<?php

namespace Maskow\CombinedRequest\Tests\Feature;

use Illuminate\Auth\Access\Response;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;
use Livewire\Component;
use Livewire\Livewire;
use Maskow\CombinedRequest\CombinedFormRequest;
use Orchestra\Testbench\TestCase;

class CamelCaseConversionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure a request instance exists when building FormRequests manually.
        $this->app->instance('request', Request::create('/'));
    }

    protected function tearDown(): void
    {
        // Reset the conversion settings after each test
        CombinedFormRequest::convertCamelCaseToSnakeCase(false);
        CombinedFormRequest::returnValidatedDataAsSnakeCase(false);

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            \Livewire\LivewireServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Needed for Livewire's checksum/encryption during testing.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
    }

    public function test_conversion_is_disabled_by_default(): void
    {
        $this->assertFalse(CombinedFormRequest::isCamelCaseConversionEnabled());
    }

    public function test_conversion_can_be_enabled(): void
    {
        CombinedFormRequest::convertCamelCaseToSnakeCase(true);
        $this->assertTrue(CombinedFormRequest::isCamelCaseConversionEnabled());

        CombinedFormRequest::convertCamelCaseToSnakeCase(false);
        $this->assertFalse(CombinedFormRequest::isCamelCaseConversionEnabled());
    }

    public function test_validation_works_with_snake_case_rules_and_camel_case_properties(): void
    {
        CombinedFormRequest::convertCamelCaseToSnakeCase(true);

        $component = Livewire::test(Fixtures\CamelCaseComponent::class)
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('emailAddress', 'john@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $validated = $component->instance()->lastValidated;

        // The validated data should be returned with camelCase keys
        $this->assertArrayHasKey('firstName', $validated);
        $this->assertArrayHasKey('lastName', $validated);
        $this->assertArrayHasKey('emailAddress', $validated);
        $this->assertSame('John', $validated['firstName']);
        $this->assertSame('Doe', $validated['lastName']);
        $this->assertSame('john@example.com', $validated['emailAddress']);
    }

    public function test_validation_errors_reference_camel_case_properties(): void
    {
        CombinedFormRequest::convertCamelCaseToSnakeCase(true);

        $component = Livewire::test(Fixtures\CamelCaseComponent::class)
            ->set('firstName', '') // Invalid: required
            ->set('lastName', 'Doe')
            ->set('emailAddress', 'invalid-email') // Invalid: not an email
            ->call('save')
            ->assertHasErrors(['firstName', 'emailAddress']);

        // Errors should reference the camelCase property names
        $errors = $component->instance()->getErrorBag();
        $this->assertTrue($errors->has('firstName'));
        $this->assertTrue($errors->has('emailAddress'));
        $this->assertFalse($errors->has('first_name')); // Should NOT have snake_case key
        $this->assertFalse($errors->has('email_address')); // Should NOT have snake_case key
    }

    public function test_conversion_does_not_break_existing_behavior_when_disabled(): void
    {
        // Ensure conversion is disabled (default state)
        CombinedFormRequest::convertCamelCaseToSnakeCase(false);

        // This component uses camelCase properties and camelCase rules
        $component = Livewire::test(Fixtures\NormalCamelCaseComponent::class)
            ->set('userName', 'johndoe')
            ->set('userEmail', 'john@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $validated = $component->instance()->lastValidated;
        $this->assertArrayHasKey('userName', $validated);
        $this->assertArrayHasKey('userEmail', $validated);
    }

    public function test_nested_arrays_are_converted_correctly(): void
    {
        CombinedFormRequest::convertCamelCaseToSnakeCase(true);

        $component = Livewire::test(Fixtures\NestedDataComponent::class)
            ->set('userInfo', [
                'firstName' => 'John',
                'lastName' => 'Doe',
            ])
            ->call('save')
            ->assertHasNoErrors();

        $validated = $component->instance()->lastValidated;
        $this->assertArrayHasKey('userInfo', $validated);
        $this->assertIsArray($validated['userInfo']);
        $this->assertArrayHasKey('firstName', $validated['userInfo']);
        $this->assertArrayHasKey('lastName', $validated['userInfo']);
    }

    public function test_validated_data_can_be_returned_as_snake_case(): void
    {
        CombinedFormRequest::convertCamelCaseToSnakeCase(true);
        CombinedFormRequest::returnValidatedDataAsSnakeCase(true);

        $component = Livewire::test(Fixtures\CamelCaseComponent::class)
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('emailAddress', 'john@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $validated = $component->instance()->lastValidated;

        // The validated data should be in snake_case (for database operations)
        $this->assertArrayHasKey('first_name', $validated);
        $this->assertArrayHasKey('last_name', $validated);
        $this->assertArrayHasKey('email_address', $validated);
        $this->assertArrayNotHasKey('firstName', $validated);
        $this->assertArrayNotHasKey('lastName', $validated);
        $this->assertArrayNotHasKey('emailAddress', $validated);
        
        // Verify values are correct
        $this->assertSame('John', $validated['first_name']);
        $this->assertSame('Doe', $validated['last_name']);
        $this->assertSame('john@example.com', $validated['email_address']);

        // Component properties should still be updated with camelCase
        $this->assertSame('John', $component->instance()->firstName);
        $this->assertSame('Doe', $component->instance()->lastName);
        $this->assertSame('john@example.com', $component->instance()->emailAddress);
    }

    public function test_errors_remain_camel_case_even_when_validated_data_is_snake_case(): void
    {
        CombinedFormRequest::convertCamelCaseToSnakeCase(true);
        CombinedFormRequest::returnValidatedDataAsSnakeCase(true);

        $component = Livewire::test(Fixtures\CamelCaseComponent::class)
            ->set('firstName', '') // Invalid: required
            ->set('lastName', 'Doe')
            ->set('emailAddress', 'invalid-email') // Invalid: not an email
            ->call('save')
            ->assertHasErrors(['firstName', 'emailAddress']);

        // Errors must still reference the camelCase property names
        $errors = $component->instance()->getErrorBag();
        $this->assertTrue($errors->has('firstName'));
        $this->assertTrue($errors->has('emailAddress'));
        $this->assertFalse($errors->has('first_name')); // Should NOT have snake_case key
        $this->assertFalse($errors->has('email_address')); // Should NOT have snake_case key
    }

    public function test_snake_case_validated_data_option_is_disabled_by_default(): void
    {
        $this->assertFalse(CombinedFormRequest::isReturnValidatedDataAsSnakeCaseEnabled());
    }

    public function test_snake_case_validated_data_can_be_enabled(): void
    {
        CombinedFormRequest::returnValidatedDataAsSnakeCase(true);
        $this->assertTrue(CombinedFormRequest::isReturnValidatedDataAsSnakeCaseEnabled());

        CombinedFormRequest::returnValidatedDataAsSnakeCase(false);
        $this->assertFalse(CombinedFormRequest::isReturnValidatedDataAsSnakeCaseEnabled());
    }

    public function test_validated_data_remains_camel_case_when_snake_case_option_disabled(): void
    {
        CombinedFormRequest::convertCamelCaseToSnakeCase(true);
        CombinedFormRequest::returnValidatedDataAsSnakeCase(false); // Explicitly disabled

        $component = Livewire::test(Fixtures\CamelCaseComponent::class)
            ->set('firstName', 'Jane')
            ->set('lastName', 'Smith')
            ->set('emailAddress', 'jane@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $validated = $component->instance()->lastValidated;

        // Should be in camelCase (default behavior)
        $this->assertArrayHasKey('firstName', $validated);
        $this->assertArrayHasKey('lastName', $validated);
        $this->assertArrayHasKey('emailAddress', $validated);
    }
}

namespace Maskow\CombinedRequest\Tests\Feature\Fixtures;

use Illuminate\Auth\Access\Response;
use Livewire\Component;
use Maskow\CombinedRequest\CombinedFormRequest;

class CamelCaseComponent extends Component
{
    public string $firstName = '';
    public string $lastName = '';
    public string $emailAddress = '';
    public array $lastValidated = [];

    public function save(): void
    {
        $this->lastValidated = CamelCaseRequest::validateLivewire($this);
    }

    public function render()
    {
        return <<<'BLADE'
            <div></div>
        BLADE;
    }
}

class CamelCaseRequest extends CombinedFormRequest
{
    public function authorize(): bool|Response
    {
        return Response::allow();
    }

    public function rules(): array
    {
        // Rules use snake_case (Laravel convention)
        return [
            'first_name'    => ['required', 'string'],
            'last_name'     => ['required', 'string'],
            'email_address' => ['required', 'email'],
        ];
    }
}

class NormalCamelCaseComponent extends Component
{
    public string $userName = '';
    public string $userEmail = '';
    public array $lastValidated = [];

    public function save(): void
    {
        $this->lastValidated = NormalCamelCaseRequest::validateLivewire($this);
    }

    public function render()
    {
        return <<<'BLADE'
            <div></div>
        BLADE;
    }
}

class NormalCamelCaseRequest extends CombinedFormRequest
{
    public function authorize(): bool|Response
    {
        return Response::allow();
    }

    public function rules(): array
    {
        // Rules use camelCase (matching property names)
        return [
            'userName'  => ['required', 'string'],
            'userEmail' => ['required', 'email'],
        ];
    }
}

class NestedDataComponent extends Component
{
    public array $userInfo = [];
    public array $lastValidated = [];

    public function save(): void
    {
        $this->lastValidated = NestedDataRequest::validateLivewire($this);
    }

    public function render()
    {
        return <<<'BLADE'
            <div></div>
        BLADE;
    }
}

class NestedDataRequest extends CombinedFormRequest
{
    public function authorize(): bool|Response
    {
        return Response::allow();
    }

    public function rules(): array
    {
        // Rules use snake_case for nested data
        return [
            'user_info'            => ['required', 'array'],
            'user_info.first_name' => ['required', 'string'],
            'user_info.last_name'  => ['required', 'string'],
        ];
    }
}
