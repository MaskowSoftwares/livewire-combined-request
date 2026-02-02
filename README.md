# Livewire Combined Request

A powerful Laravel FormRequest base class that seamlessly works in both HTTP controllers and Livewire v3/v4 components. Write your validation rules, authorization logic, and parameter requirements once—use them everywhere. Perfect for Laravel 10/11/12 projects that want to eliminate duplicated validation between APIs and Livewire components.

## Features

- 🔄 **Unified API**: One FormRequest for HTTP controllers, APIs, and Livewire components
- 🔒 **Parameter Requirements**: Declare required parameters that are automatically validated
- 🛡️ **Authorization**: Identical authorization logic across all contexts
- 📁 **File Uploads**: Full support for Livewire file uploads and temporary files
- 🎯 **Parameter Binding**: Elegant parameter system that works with route model binding and manual injection
- 🐫 **Smart Naming**: Optional automatic camelCase ↔ snake_case conversion for Livewire validation (disabled by default)
- 💾 **Database Ready**: Optional snake_case validated data return for direct database operations
- 🚫 **Zero Configuration**: Drop it into any Laravel + Livewire 3/4 app

## Why use this?

Stop writing validation rules twice! Whether you're building an API endpoint or a Livewire component, use the same FormRequest with identical rules, authorization, and parameter handling.

**Before:**
```php
// API Controller
class UpdateTeamRequest extends FormRequest { /* rules here */ }

// Livewire Component  
public function save() {
    $this->validate([ /* same rules again! */ ]);
    // Manual authorization check...
    // Manual parameter handling...
}
```

**After:**
```php
// One request class for everything
class UpdateTeamRequest extends CombinedFormRequest {
    protected array $requiredParameters = ['team', 'workspace'];
    
    public function authorize() { /* works everywhere */ }
    public function rules() { /* works everywhere */ }
}

// API Controller
public function update(UpdateTeamRequest $request, Team $team) { /* automatic */ }

// Livewire Component
public function save() {
    $validated = UpdateTeamRequest::validateLivewire($this, [
        'team' => $this->team,
        'workspace' => $this->workspace
    ]);
}
```

## Requirements

- PHP 8.1+
- Laravel 10 / 11 / 12
- Livewire 3 / 4

## Installation

```bash
composer require maskow/livewire-combined-request
```

No configuration or manual service provider registration is required.

## Quick start

### 1) Create a request with required parameters

```php
<?php

namespace App\Http\Requests;

use Maskow\CombinedRequest\CombinedFormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateTeamRequest extends CombinedFormRequest
{
    /**
     * Define which parameters this request requires.
     * These will be automatically validated when the request is created.
     */
    protected array $requiredParameters = [
        'team',        // The team model/object
        'workspace',   // The workspace model/object
    ];

    public function authorize(): bool
    {
        // Use parameter() to access both route parameters (HTTP) and injected parameters (Livewire)
        $team = $this->parameter('team');
        $workspace = $this->parameter('workspace');
        
        return Gate::allows('update', $team) && 
               $this->user()->can('access', $workspace);
    }

    public function rules(): array
    {
        $team = $this->parameter('team');
        
        return [
            'name' => ['required', 'string', 'max:255', 'unique:teams,name,' . $team?->id],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A team with this name already exists.',
            'name.required' => 'Team name is required.',
        ];
    }
}
```

### 2) Use it in HTTP controllers and APIs

The request works exactly like a normal Laravel FormRequest with automatic route model binding:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTeamRequest;
use App\Models\Team;
use App\Models\Workspace;

class TeamController extends Controller
{
    /**
     * Route: PUT /workspaces/{workspace}/teams/{team}
     */
    public function update(UpdateTeamRequest $request, Workspace $workspace, Team $team)
    {
        // Required parameters are automatically satisfied by route model binding
        // $request->parameter('team') === $team
        // $request->parameter('workspace') === $workspace
        
        $validated = $request->validated();
        $team->update($validated);
        
        return response()->json($team);
    }
}
```

**Route definition:**
```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::put('/workspaces/{workspace}/teams/{team}', [TeamController::class, 'update']);
});
```

### 3) Use the same request in Livewire components

```php
<?php

namespace App\Livewire;

use App\Http\Requests\UpdateTeamRequest;
use App\Models\Team;
use App\Models\Workspace;
use Livewire\Component;

class EditTeamForm extends Component
{
    public Team $team;
    public Workspace $workspace;
    
    // Public properties for the form
    public string $name = '';
    public string $description = '';
    public bool $is_public = false;

    public function mount(Team $team, Workspace $workspace)
    {
        $this->team = $team;
        $this->workspace = $workspace;
        $this->name = $team->name;
        $this->description = $team->description ?? '';
        $this->is_public = $team->is_public;
    }

    public function save()
    {
        try {
            // The same validation rules and authorization logic!
            $validated = UpdateTeamRequest::validateLivewire($this, [
                'team' => $this->team,
                'workspace' => $this->workspace,
            ]);

            $this->team->update($validated);
            
            session()->flash('message', 'Team updated successfully!');
            
        } catch (\InvalidArgumentException $e) {
            // Missing required parameters
            session()->flash('error', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.edit-team-form');
    }
}
```

## Parameter System

The package provides a powerful parameter system that works seamlessly across HTTP and Livewire contexts:

### Required Parameters

Define required parameters in your request class:

```php
class CreateProjectRequest extends CombinedFormRequest
{
    protected array $requiredParameters = [
        'workspace',    // Model/Object
        'team',         // Model/Object  
        'user_id',      // Primitive value
        'template_id',  // Optional: can be null
    ];

    public function authorize()
    {
        $workspace = $this->parameter('workspace');
        $team = $this->parameter('team');
        $userId = $this->parameter('user_id');
        
        return $this->user()->can('createProject', [$workspace, $team]) &&
               $this->user()->id === $userId;
    }
}
```

### Parameter Access

Use the unified `parameter()` method to access parameters in both contexts:

```php
// Works in both HTTP and Livewire contexts
$team = $this->parameter('team');
$workspace = $this->parameter('workspace');
$userId = $this->parameter('user_id', auth()->id()); // with default

// Check if parameter exists
if ($this->hasParameter('optional_param')) {
    // ...
}

// Get all parameters
$allParams = $this->parameters();
```

### HTTP Context (Route Model Binding)

Parameters are automatically resolved from route parameters:

```php
// Route: PUT /workspaces/{workspace}/teams/{team}
// Parameters 'workspace' and 'team' are automatically available via route model binding
```

### Livewire Context (Manual Injection)

Pass parameters when calling the validation:

```php
// In your Livewire component
public function save()
{
    $validated = CreateProjectRequest::validateLivewire($this, [
        'workspace' => $this->workspace,
        'team' => $this->selectedTeam,
        'user_id' => auth()->id(),
        'template_id' => $this->selectedTemplate?->id,
    ]);
}
```

### Error Handling

Missing required parameters throw descriptive exceptions:

```php
// Exception message:
// "Missing required parameters for App\Http\Requests\UpdateTeamRequest: team, workspace. 
//  Please provide these parameters when calling fromLivewire() or ensure they exist in the route."
```

## Authorization Notifications

When authorization fails in a Livewire context, you can register a global notifier to handle failures gracefully (e.g., show a toast notification):

```php
// In your AppServiceProvider boot() method:

use Maskow\CombinedRequest\CombinedFormRequest;

public function boot(): void
{
    CombinedFormRequest::notifyAuthorizationUsing(function ($component, string $message): void {
        // Show a toast notification, flash message, or dispatch an event
        Toast::error($component, 'Oops… Das hat nicht geklappt!', $message);
        
        // Or use Laravel's session flash:
        // session()->flash('error', $message);
        
        // Or dispatch a browser event:
        // $component->dispatch('notify', ['type' => 'error', 'message' => $message]);
    });
}
```

The callback receives the Livewire component instance and the authorization failure message, giving you full flexibility in how to notify the user.

## FormRequest Hooks

All standard Laravel FormRequest hooks work exactly the same in both HTTP and Livewire contexts:

### prepareForValidation

Mutate or normalize data before validation runs:

```php
class UpdateTeamRequest extends CombinedFormRequest
{
    protected function prepareForValidation(): void
    {
        // Normalize data before validation
        $this->merge([
            'slug' => Str::slug($this->name),
            'email' => strtolower($this->email),
        ]);
        
        // Set default values
        if (! $this->has('is_public')) {
            $this->merge(['is_public' => false]);
        }
    }
}
```

### withValidator

Add custom validation logic after the validator is created:

```php
class CreateProjectRequest extends CombinedFormRequest
{
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->hasExceededProjectLimit()) {
                $validator->errors()->add('project', 'You have reached your project limit.');
            }
        });
    }
    
    private function hasExceededProjectLimit(): bool
    {
        return $this->user()->projects()->count() >= 10;
    }
}
```

### passedValidation

Perform actions after validation succeeds:

```php
class UploadDocumentRequest extends CombinedFormRequest
{
    protected function passedValidation(): void
    {
        // Log successful validation, track analytics, etc.
        activity()->log('Document upload validated');
    }
}
```

### messages & attributes

Customize error messages and attribute names:

```php
class UpdateTeamRequest extends CombinedFormRequest
{
    public function messages(): array
    {
        return [
            'name.required' => 'Please enter a team name.',
            'name.unique' => 'This team name is already taken.',
        ];
    }
    
    public function attributes(): array
    {
        return [
            'is_public' => 'visibility setting',
            'max_members' => 'maximum team size',
        ];
    }
}
```

## CamelCase to snake_case Conversion

Laravel validation rules follow snake_case naming conventions (e.g., `first_name`, `email_address`), while Livewire components typically use camelCase for public properties (e.g., `firstName`, `emailAddress`). This package provides an optional feature to automatically bridge this gap.

### Enabling the Conversion

By default, this feature is **disabled** to maintain backward compatibility. Enable it globally in your `AppServiceProvider`:

```php
// In app/Providers/AppServiceProvider.php

use Maskow\CombinedRequest\CombinedFormRequest;

public function boot(): void
{
    // Enable automatic camelCase to snake_case conversion
    CombinedFormRequest::convertCamelCaseToSnakeCase(true);
}
```

### How It Works

When enabled, the package automatically:

1. **Converts property names**: Transforms camelCase component properties (e.g., `firstName`) to snake_case (e.g., `first_name`) before validation
2. **Validates with snake_case rules**: Your validation rules can use Laravel's standard snake_case convention
3. **Maps errors back**: Validation errors reference the original camelCase property names in your component
4. **Returns data**: Validated data is returned in the format you choose:
   - **camelCase** (default): Converted back for seamless Livewire integration
   - **snake_case** (optional): Kept in snake_case for direct database operations

### Example Usage

**Livewire Component:**
```php
<?php

namespace App\Livewire;

use App\Http\Requests\UpdateUserProfileRequest;
use Livewire\Component;

class EditProfile extends Component
{
    // Component properties use camelCase (Livewire convention)
    public string $firstName = '';
    public string $lastName = '';
    public string $emailAddress = '';
    public bool $isSubscribed = false;

    public function save()
    {
        // Validation happens automatically with snake_case rules
        $validated = UpdateUserProfileRequest::validateLivewire($this);
        
        // $validated contains camelCase keys matching your properties
        auth()->user()->update($validated);
        
        session()->flash('message', 'Profile updated!');
    }

    public function render()
    {
        return view('livewire.edit-profile');
    }
}
```

**FormRequest with snake_case rules:**
```php
<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Maskow\CombinedRequest\CombinedFormRequest;

class UpdateUserProfileRequest extends CombinedFormRequest
{
    public function authorize(): bool|Response
    {
        return Response::allow();
    }

    public function rules(): array
    {
        // Rules use snake_case (Laravel convention)
        return [
            'first_name'    => ['required', 'string', 'max:255'],
            'last_name'     => ['required', 'string', 'max:255'],
            'email_address' => ['required', 'email', 'max:255'],
            'is_subscribed' => ['boolean'],
        ];
    }
    
    public function messages(): array
    {
        return [
            'first_name.required'    => 'Please enter your first name.',
            'email_address.required' => 'Please enter your email address.',
            'email_address.email'    => 'Please enter a valid email address.',
        ];
    }
}
```

**Blade Template:**
```blade
<div>
    <form wire:submit="save">
        <div>
            <label>First Name</label>
            <input wire:model="firstName" type="text">
            {{-- Error references camelCase property name --}}
            @error('firstName') <span class="error">{{ $message }}</span> @enderror
        </div>

        <div>
            <label>Last Name</label>
            <input wire:model="lastName" type="text">
            @error('lastName') <span class="error">{{ $message }}</span> @enderror
        </div>

        <div>
            <label>Email</label>
            <input wire:model="emailAddress" type="email">
            @error('emailAddress') <span class="error">{{ $message }}</span> @enderror
        </div>

        <div>
            <label>
                <input wire:model="isSubscribed" type="checkbox">
                Subscribe to newsletter
            </label>
            @error('isSubscribed') <span class="error">{{ $message }}</span> @enderror
        </div>

        <button type="submit">Save Profile</button>
    </form>
</div>
```

### Benefits

- **Follow Laravel conventions**: Write validation rules in snake_case as Laravel recommends
- **Keep Livewire conventions**: Use camelCase for component properties as Livewire expects
- **Seamless error handling**: Errors automatically reference your camelCase property names
- **No manual mapping**: The conversion happens automatically in both directions
- **Backward compatible**: Disabled by default, so existing code continues to work

### Nested Data Support

The conversion also works with nested arrays:

```php
// Component property
public array $userInfo = [
    'firstName' => 'John',
    'lastName' => 'Doe',
];

// Validation rules (snake_case)
public function rules(): array
{
    return [
        'user_info'            => ['required', 'array'],
        'user_info.first_name' => ['required', 'string'],
        'user_info.last_name'  => ['required', 'string'],
    ];
}

// Errors will reference: 'userInfo.firstName', 'userInfo.lastName'
```

### Returning Validated Data in snake_case

By default, validated data is converted back to camelCase to match your Livewire component properties. However, if you need to pass the validated data directly to database operations (which typically use snake_case column names), you can keep the validated data in snake_case format:

```php
// In app/Providers/AppServiceProvider.php

use Maskow\CombinedRequest\CombinedFormRequest;

public function boot(): void
{
    // Enable conversion
    CombinedFormRequest::convertCamelCaseToSnakeCase(true);
    
    // Keep validated data in snake_case for database operations
    CombinedFormRequest::returnValidatedDataAsSnakeCase(true);
}
```

**How it works:**

```php
// Livewire Component
class ChangeEntryBoardModal extends Component
{
    public Entry $entry;
    public $boardId;    // camelCase property
    public $listId;     // camelCase property

    public function updateBoard(): void
    {
        try {
            // Validate and get data in snake_case
            $data = UpdateEntryRequest::validateLivewire($this, ['entry' => $this->entry]);
            
            // $data now contains: ['board_id' => ..., 'list_id' => ...]
            // Perfect for direct database operations!
            $this->entry->update($data);
            
        } catch (ValidationException $e) {
            // Errors still reference camelCase: 'boardId', 'listId'
            throw $e;
        }
    }
}

// FormRequest
class UpdateEntryRequest extends CombinedFormRequest
{
    public function rules(): array
    {
        return [
            'board_id' => ['required', 'integer', 'exists:boards,id'],
            'list_id'  => ['required', 'integer', 'exists:lists,id'],
        ];
    }
}
```

**Important notes:**

- **Validated data keys**: `board_id`, `list_id` (snake_case) - ready for database operations
- **Validation errors**: `boardId`, `listId` (camelCase) - references component properties
- **Component properties**: Still updated correctly via `fill()` using converted camelCase data
- **Blade templates**: Use camelCase for `@error()` directives: `@error('boardId')`

This setting only takes effect when `convertCamelCaseToSnakeCase` is also enabled. It's particularly useful when:
- Your database columns use snake_case (Laravel convention)
- You want to pass validated data directly to `Model::create()` or `Model::update()`
- You want to avoid manual key conversion

### When to Use This Feature

**Use it when:**
- You want to follow Laravel's snake_case convention for validation rules
- Your Livewire components use camelCase properties (common practice)
- You want consistency across your validation rules (API and Livewire)

**Don't use it when:**
- Your validation rules already match your property names
- You prefer explicit control over naming in each component
- You have a specific reason to use different naming conventions

## How it works (under the hood)

- `ProfileRequest::validateLivewire($this)` builds a fake HTTP request from the component (`fromLivewire`), wiring the service container and redirector so the normal FormRequest pipeline can run.
- The component’s public properties are pulled into the request (`prepareLivewireValidationData`), files are split out, values are normalized for Symfony’s `InputBag`, and your `prepareForValidation` hook runs so data can be mutated first.
- Authorization is executed via your `authorize` method; denials are converted into a `ValidationException` on the `authorization` key (and optionally sent to your notifier).
- The usual validator is created (`getValidatorInstance`), `withValidator` callbacks run, and on success the component’s error bag is cleared and the validated/mutated data is written back to the component via `fill`.
- `validationData()` is overridden to feed the prepared Livewire payload to the validator, and `validated()` ensures validation is triggered even if you call it directly on the request.

## FAQ

### General Questions

**Q: Does it work with file uploads in Livewire?**
A: Yes! Use `WithFileUploads` trait in your component. The request receives `TemporaryUploadedFile` instances and all file validation rules work as expected.

**Q: Can I use it in API controllers?**
A: Absolutely! Type-hint your request in any controller (web or API). It behaves exactly like a normal Laravel FormRequest.

**Q: Do FormRequest hooks like `prepareForValidation` work?**
A: Yes! All standard hooks (`prepareForValidation`, `withValidator`, `messages`, `attributes`, `passedValidation`) work identically in both contexts.

**Q: How do missing required parameters behave?**
A: They throw an `InvalidArgumentException` with a descriptive message listing exactly which parameters are missing.

### Authorization

**Q: How do I handle authorization failures in Livewire?**
A: Register a global notifier via `CombinedFormRequest::notifyAuthorizationUsing(...)`. See the [Authorization Notifications](#authorization-notifications) section for details.

**Q: Does authorization work the same way in both contexts?**
A: Yes! Your `authorize()` method runs identically. In HTTP it returns 403, in Livewire it throws a validation exception.

### Parameters

**Q: What's the difference between `route()` and `parameter()`?**
A: `parameter()` is the new unified method that works in both contexts. `route()` still works for backward compatibility but internally calls `parameter()`.

**Q: Can I mix route model binding with manual parameters?**
A: Yes! HTTP requests use route model binding, Livewire uses manual injection. Both are accessed via the same `parameter()` method.

**Q: What types of values can be parameters?**
A: Anything! Models, primitive values, arrays, objects—the parameter system is completely flexible.

### CamelCase Conversion

**Q: Should I enable camelCase to snake_case conversion?**
A: It depends on your preferences. Enable it if you want to write validation rules in Laravel's standard snake_case convention while keeping camelCase properties in your Livewire components. Leave it disabled if your rules already match your property names.

**Q: Does the conversion affect HTTP/API validation?**
A: No, the conversion only applies to Livewire validation. HTTP and API requests continue to work normally.

**Q: Will enabling this break my existing Livewire components?**
A: No, because it's disabled by default. When you enable it, only components that use the FormRequest with snake_case rules will benefit. Components with matching property and rule names continue to work as before.

**Q: Can I use both camelCase and snake_case rules in the same project?**
A: Yes! The conversion is a global setting, but you can write rules that already match your property names. The conversion only affects keys that differ between camelCase and snake_case.

**Q: Should I use `returnValidatedDataAsSnakeCase(true)` for database operations?**
A: Yes, if you want to pass validated data directly to Eloquent methods like `create()` or `update()` without manual key conversion. When enabled, validated data keys match your database column names (snake_case), while errors still reference your Livewire component properties (camelCase).

**Q: What's the difference between the validated data format and error format?**
A: With `returnValidatedDataAsSnakeCase(true)`:
- **Validated data**: `['board_id' => 1, 'list_id' => 2]` (snake_case for database)
- **Validation errors**: `['boardId' => ['error'], 'listId' => ['error']]` (camelCase for component)
This allows direct database usage while maintaining proper error references in your Blade templates.

**Q: Do I need both settings enabled for database operations?**
A: Yes. You need `convertCamelCaseToSnakeCase(true)` to enable the conversion system, and `returnValidatedDataAsSnakeCase(true)` to keep the validated data in snake_case format. Without the first setting, no conversion happens at all.

## How it works

Under the hood, the package creates a fake HTTP request from your Livewire component, enabling the standard FormRequest pipeline to run. Here's the flow:

1. **Request Creation**: `validateLivewire()` builds a request instance with the container and redirector
2. **Parameter Binding**: Required parameters are validated and bound to the request
3. **Data Preparation**: Component properties are extracted, files separated, and normalized for Symfony's InputBag
4. **Hooks Execution**: Your `prepareForValidation()` runs, allowing data mutation
5. **Authorization**: The `authorize()` method runs; failures become validation exceptions
6. **Validation**: Standard validator creation with `withValidator()` callbacks
7. **Success Handling**: Component errors are cleared and validated data is filled back via `fill()`

The `parameter()` method provides a unified API that checks request parameters (Livewire) first, then falls back to route parameters (HTTP).

## Testing

Run the test suite:

```bash
composer install
composer test
```

## License

Licensed under the Apache 2.0 license. See `LICENSE` for details.

## About

Built by Julius Maskow at [Software-Stratege.de](https://www.software-stratege.de).

Feedback and contributions welcome!
