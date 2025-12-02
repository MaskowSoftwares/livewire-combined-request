<?php

namespace Maskow\CombinedRequest\Tests\Feature;

use Illuminate\Auth\Access\Response;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\Livewire;
use Maskow\CombinedRequest\CombinedFormRequest;
use Orchestra\Testbench\TestCase;

class CombinedFormRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure a request instance exists when building FormRequests manually.
        $this->app->instance('request', Request::create('/'));

        Route::post('/profile', function (Fixtures\ProfileRequest $request) {
            return response()->json($request->validated());
        });
    }

    protected function tearDown(): void
    {
        CombinedFormRequest::notifyAuthorizationUsing(null);

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

    public function test_it_validates_and_mutates_via_livewire_action(): void
    {
        $component = Livewire::test(Fixtures\ProfileComponent::class)
            ->set('name', 'Jane Doe')
            ->set('email', 'TEST@EXAMPLE.COM')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('email', 'test@example.com');

        $this->assertSame([
            'name'  => 'Jane Doe',
            'email' => 'test@example.com',
        ], $component->instance()->lastValidated);
    }

    public function test_it_validates_via_http_controller(): void
    {
        $response = $this->postJson('/profile', [
            'name'  => 'Jane',
            'email' => 'TEST@EXAMPLE.COM',
        ]);

        $response->assertOk()->assertExactJson([
            'name'  => 'Jane',
            'email' => 'test@example.com',
        ]);
    }

    public function test_authorization_failure_bubbles_as_validation_error(): void
    {
        $notified = null;

        CombinedFormRequest::notifyAuthorizationUsing(function (Component $component, string $message) use (&$notified) {
            $notified = $message;
            $component->addError('authorization', $message);
        });

        $component = Livewire::test(Fixtures\UnauthorizedProfileComponent::class)
            ->set('name', 'Jane Doe')
            ->set('email', 'jane@example.com')
            ->call('save')
            ->assertHasErrors(['authorization']);

        $this->assertSame('Nope', $notified);
        $this->assertSame(['authorization' => ['Nope']], $component->instance()->getErrorBag()->toArray());
    }

    public function test_file_uploads_are_available_to_validation(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);

        $component = Livewire::test(Fixtures\FileUploadComponent::class)
            ->set('name', 'Jane')
            ->set('email', 'jane@example.com')
            ->set('avatar', $file)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertInstanceOf(UploadedFile::class, $component->instance()->lastValidated['avatar']);
        $this->assertSame('avatar.jpg', $component->instance()->lastValidated['avatar']->getClientOriginalName());
    }

    public function test_livewire_temporary_file_is_supported(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);

        $component = Livewire::test(Fixtures\FileUploadComponent::class)
            ->upload('avatar', [$file])
            ->set('name', 'Jane')
            ->set('email', 'jane@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertInstanceOf(TemporaryUploadedFile::class, $component->instance()->avatar);
        $this->assertInstanceOf(TemporaryUploadedFile::class, $component->instance()->lastValidated['avatar']);
    }

    public function test_additional_validator_callbacks_run_for_livewire(): void
    {
        Livewire::test(Fixtures\ProfileComponent::class)
            ->set('name', 'john doe')
            ->set('email', 'john@example.com')
            ->call('save')
            ->assertHasErrors(['name']);
    }
}

namespace Maskow\CombinedRequest\Tests\Feature\Fixtures;

use Illuminate\Auth\Access\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maskow\CombinedRequest\CombinedFormRequest;

class ProfileComponent extends Component
{
    public string $name = '';
    public string $email = '';
    public null|UploadedFile $avatar = null;
    public array $lastValidated = [];

    public function save(): void
    {
        $this->lastValidated = ProfileRequest::validateLivewire($this);
    }

    public function render()
    {
        return <<<'BLADE'
            <div></div>
        BLADE;
    }
}

class UnauthorizedProfileComponent extends Component
{
    public string $name = '';
    public string $email = '';
    public array $lastValidated = [];

    public function save(): void
    {
        $this->lastValidated = UnauthorizedProfileRequest::validateLivewire($this);
    }

    public function render()
    {
        return <<<'BLADE'
            <div></div>
        BLADE;
    }
}

class FileUploadComponent extends Component
{
    use WithFileUploads;

    public string $name = '';
    public string $email = '';
    public null|UploadedFile $avatar = null;
    public array $lastValidated = [];

    public function save(): void
    {
        $this->lastValidated = FileUploadRequest::validateLivewire($this);
    }

    public function render()
    {
        return <<<'BLADE'
            <div></div>
        BLADE;
    }
}

class ProfileRequest extends CombinedFormRequest
{
    public function authorize(): bool|Response
    {
        return Response::allow();
    }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string'],
            'email' => ['required', 'email'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower((string) $this->email),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (strtolower((string) $this->name) === 'john doe') {
                $validator->errors()->add('name', 'Who the hell are you?');
            }
        });
    }
}

class UnauthorizedProfileRequest extends CombinedFormRequest
{
    public function authorize(): bool|Response
    {
        return Response::deny('Nope');
    }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string'],
            'email' => ['required', 'email'],
        ];
    }
}

class FileUploadRequest extends CombinedFormRequest
{
    public function authorize(): bool|Response
    {
        return Response::allow();
    }

    public function rules(): array
    {
        return [
            'name'   => ['required', 'string'],
            'email'  => ['required', 'email'],
            'avatar' => ['required', 'file'],
        ];
    }
}
